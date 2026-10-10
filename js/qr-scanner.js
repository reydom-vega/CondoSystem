(() => {
  const root = document.getElementById('qrScannerApp');
  const endpoint = '../api/scan_history.php';
  const historyBody = document.getElementById('scanHistoryBody');
  const filters = document.getElementById('qrHistoryFilters');
  const feedback = document.getElementById('qrHistoryFeedback');
  const status = document.getElementById('scannerStatus');
  const result = document.getElementById('scanResult');
  const openResult = document.getElementById('openResult');
  const resultCard = document.getElementById('scanResultCard');
  const resultLabel = document.getElementById('scanResultLabel');
  const resultMessage = document.getElementById('scanResultMessage');
  const resultFields = document.getElementById('scanResultFields');
  const startButton = document.getElementById('startScanner');
  const stopButton = document.getElementById('stopScanner');
  const manualButton = document.getElementById('manualScanSubmit');
  const placeholder = document.getElementById('cameraPlaceholder');
  const cameraState = document.getElementById('cameraState');
  let page = 1, pages = 1, controller, debounce;
  let scanner, scannerRunning = false, cameraBusy = false, scanBusy = false, lastContent = '', lastAt = 0, pendingRequest;
  const label = value => String(value || '').replaceAll('_', ' ').replace(/^./, c => c.toUpperCase());
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const typeLabel = type => ({visitor:'Visitor QR Pass',property:'Property Gate Pass',parking:'Visitor Parking',permit:'Other Permit',unknown:'Unknown QR'}[type] || type);
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
  function dates(form) {
    const period = form.elements.period.value;
    form.querySelectorAll('.qr-date-single').forEach(el => { el.hidden = period !== 'single'; el.querySelector('input').required = period === 'single'; });
    form.querySelectorAll('.qr-date-range').forEach(el => { el.hidden = period !== 'range'; el.querySelector('input').required = period === 'range'; });
  }
  async function json(response) {
    let data;
    try { data = await response.json(); }
    catch(error) { throw new Error('Session expired or server unavailable. Refresh the page and try again.'); }
    if (!response.ok || !data.success) throw new Error(data.error || 'Request could not be completed.');
    return data;
  }
  async function loadHistory(reset = false) {
    if (reset) page = 1;
    controller?.abort(); controller = new AbortController(); const current = controller;
    historyBody.innerHTML = '<tr><td colspan="11" class="qr-table-message">Loading scan history…</td></tr>'; feedback.textContent = '';
    document.getElementById('historyPanel').setAttribute('aria-busy','true');
    try {
      const params = new URLSearchParams(new FormData(filters)); params.set('page',page);
      const data = await json(await fetch(`${endpoint}?${params}`,{signal:current.signal,headers:{Accept:'application/json'}}));
      page = data.page; pages = data.pages;
      Object.entries(data.summary).forEach(([key,value]) => { const card = document.getElementById(`qrSummary_${key}`); if(card) card.textContent = value; });
      document.getElementById('qrMatchCount').textContent = `${data.total} matching records`;
      document.getElementById('qrPageNumber').textContent = `Page ${page} of ${pages}`;
      document.getElementById('qrPrevious').disabled = page <= 1; document.getElementById('qrNext').disabled = page >= pages;
      historyBody.innerHTML = data.records.length ? data.records.map(row => `<tr><td>${escape(row.scan_reference)}</td><td>${escape(typeLabel(row.qr_type))}</td><td>${escape(row.subject_name || row.requester_name || 'Unknown')}</td><td>${escape(row.unit_number || '—')}</td><td>${escape(row.pass_number || '—')}</td><td>${escape(row.local_time)}</td><td>${escape(row.scanner_full_name)}</td><td>${escape(label(row.scanner_role))}</td><td><span class="${row.scan_action !== 'verification' ? 'qr-action-completed' : ''}">${escape(label(row.scan_action))}</span></td><td><span class="qr-status qr-status-${escape(row.verification_result)}">${escape(label(row.verification_result))}</span></td><td><button class="service-btn service-btn-secondary" type="button" data-scan-id="${Number(row.id)}" aria-label="View details for ${escape(row.scan_reference)}">View details</button></td></tr>`).join('') : '<tr><td colspan="11" class="qr-table-message">No scans match these filters. Try another name, pass number or date period.</td></tr>';
    } catch(error) {
      if(error.name !== 'AbortError') { feedback.textContent = error.message; historyBody.innerHTML = '<tr><td colspan="11" class="qr-table-message">Unable to load records. Select Refresh to try again.</td></tr>'; }
    } finally { if(current === controller) document.getElementById('historyPanel').removeAttribute('aria-busy'); }
  }
  const tabs = Array.from(document.querySelectorAll('[data-scanner-tab]'));
  tabs.forEach(tab => tab.addEventListener('click',async () => {
    const history = tab.dataset.scannerTab === 'history';
    tabs.forEach(button => { button.setAttribute('aria-selected',String(button === tab)); button.tabIndex = button === tab ? 0 : -1; });
    document.getElementById('scanPanel').hidden = history; document.getElementById('historyPanel').hidden = !history;
    if(history) { if(scannerRunning) await stopScanner(); loadHistory(); }
  }));
  tabs.forEach((tab,index) => tab.addEventListener('keydown',event => {
    let next;
    if(event.key === 'ArrowRight') next = (index + 1) % tabs.length;
    if(event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
    if(event.key === 'Home') next = 0;
    if(event.key === 'End') next = tabs.length - 1;
    if(next === undefined) return;
    event.preventDefault(); tabs[next].focus(); tabs[next].click();
  }));
  async function handleScan(content) {
    if(scanBusy || !content || (content === lastContent && Date.now()-lastAt<5000)) return false;
    scanBusy = true; openResult.style.display = 'none'; openResult.removeAttribute('href'); result.textContent = 'Verifying QR code…';
    manualButton.disabled = true; manualButton.textContent = 'Verifying…';
    resultCard.dataset.state = 'pending'; resultCard.setAttribute('aria-busy','true');
    resultLabel.textContent = 'CHECKING PASS'; resultMessage.textContent = 'Checking the current authorization and recording this scan.';
    resultFields.replaceChildren(); resultFields.hidden = true;
    if(!pendingRequest || pendingRequest.content !== content) pendingRequest = {content,id:uuid()};
    try {
      const data = await json(await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({content,request_id:pendingRequest.id,csrf_token:root.dataset.csrf})}));
      lastContent = content; lastAt = Date.now(); pendingRequest = null;
      const verification = data.verification;
      resultCard.dataset.state = verification.verification_result;
      resultLabel.textContent = label(verification.verification_result).toUpperCase();
      result.textContent = typeLabel(verification.qr_type);
      resultMessage.textContent = verification.message;
      const fields = {'Visitor / Requester':verification.subject_name || verification.requester_name,'Unit':verification.unit_number,'Pass / Permit':verification.pass_number};
      Object.entries(fields).forEach(([name,value]) => {
        if(!value) return;
        const div = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
        dt.textContent = name; dd.textContent = value; div.append(dt,dd); resultFields.append(div);
      });
      resultFields.hidden = !resultFields.childElementCount;
      if(verification.recognized && verification.pass_url) { openResult.href = verification.pass_url; openResult.style.display = 'flex'; }
      loadHistory();
      return true;
    } catch(error) { resultCard.dataset.state = 'error'; resultLabel.textContent = 'UNABLE TO VERIFY'; resultMessage.textContent = `${error.message} Try the scan again.`; result.textContent = 'Verification could not be saved'; return false; }
    finally { scanBusy = false; resultCard.removeAttribute('aria-busy'); manualButton.disabled = false; manualButton.textContent = 'Verify pass'; }
  }
  function updateCameraControls() {
    startButton.disabled = scannerRunning || cameraBusy;
    stopButton.disabled = !scannerRunning || cameraBusy;
    startButton.textContent = cameraBusy && !scannerRunning ? 'Starting…' : 'Start camera';
    cameraState.textContent = cameraBusy ? (scannerRunning ? 'Stopping…' : 'Starting…') : scannerRunning ? 'Camera live' : 'Camera off';
    cameraState.dataset.live = String(scannerRunning);
    placeholder.hidden = scannerRunning || cameraBusy;
  }
  async function startScanner() {
    if(scannerRunning || cameraBusy) return;
    if(typeof Html5Qrcode === 'undefined') { status.textContent = 'Scanner library is still loading. Check your connection and try again.'; return; }
    scanner ||= new Html5Qrcode('reader');
    cameraBusy = true; updateCameraControls(); status.textContent = 'Starting camera. Allow camera access when prompted.';
    try { await scanner.start({facingMode:'environment'},{fps:10,qrbox:{width:250,height:250}},handleScan,()=>{}); scannerRunning = true; status.textContent = 'Camera active. Point at a pass QR code.'; }
    catch(error) { status.textContent = 'Camera could not start. Allow camera permission and use HTTPS or localhost.'; }
    finally { cameraBusy = false; updateCameraControls(); }
    if(scannerRunning && document.getElementById('scanPanel').hidden) await stopScanner();
  }
  async function stopScanner() {
    if(!scanner || !scannerRunning || cameraBusy) return;
    cameraBusy = true; updateCameraControls();
    try { await scanner.stop(); scannerRunning = false; status.textContent = 'Camera stopped.'; }
    catch(error) { status.textContent = 'Unable to stop camera. Try again.'; }
    finally { cameraBusy = false; updateCameraControls(); }
  }
  document.getElementById('startScanner').addEventListener('click',startScanner);
  document.getElementById('stopScanner').addEventListener('click',stopScanner);
  document.getElementById('manualScanForm').addEventListener('submit',async event => { event.preventDefault(); const input=document.getElementById('manualScan'), content=input.value.trim(); if(await handleScan(content)) { if(input.value.trim() === content) input.value=''; } });
  document.getElementById('refreshHistory').addEventListener('click',()=>loadHistory());
  filters.addEventListener('submit',event => { event.preventDefault(); loadHistory(true); });
  filters.addEventListener('change',() => dates(filters));
  filters.elements.q.addEventListener('input',()=> { clearTimeout(debounce); debounce=setTimeout(()=>loadHistory(true),300); });
  filters.addEventListener('reset',()=>setTimeout(()=> { dates(filters); loadHistory(true); },0));
  document.getElementById('clearQrSearch').addEventListener('click',()=> { filters.elements.q.value=''; loadHistory(true); });
  document.getElementById('qrPrevious').addEventListener('click',()=> { if(page>1) { page--; loadHistory(); } });
  document.getElementById('qrNext').addEventListener('click',()=> { if(page<pages) { page++; loadHistory(); } });
  document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click',()=> {
    const dialog = button.closest('dialog');
    if(window.CelandineMotion) window.CelandineMotion.closeDialog(dialog);
    else dialog.close();
  }));
  historyBody.addEventListener('click',async event => {
    const button=event.target.closest('[data-scan-id]'); if(!button) return;
    button.disabled=true;
    try {
      const {record}=await json(await fetch(`${endpoint}?id=${button.dataset.scanId}`,{headers:{Accept:'application/json'}}));
      const detail=document.getElementById('qrDetailsFields'); detail.replaceChildren();
      const fields={'Scan ID':record.scan_reference,'QR type':typeLabel(record.qr_type),'Related reference ID':record.reference_id,'Visitor / Requester':record.subject_name,'Resident requester':record.requester_name,'Unit':record.unit_number,'Pass / Permit':record.pass_number,'Date and time (Asia/Manila)':record.local_time,'UTC timestamp':record.event_time_utc,'Scanner account ID':record.scanner_user_id,'Scanned by':record.scanner_full_name,'Role':label(record.scanner_role),'Action':label(record.scan_action),'Verification result':label(record.verification_result),'Remarks':record.remarks,'Record created (UTC)':record.created_at_utc};
      Object.entries(fields).forEach(([name,value])=> { const div=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd'); dt.textContent=name; dd.textContent=value || '—'; div.append(dt,dd); detail.append(div); });
      document.getElementById('qrScanDetails').showModal();
    } catch(error) { feedback.textContent=error.message; } finally { button.disabled=false; }
  });
  const exportForm=document.getElementById('qrExportForm');
  document.getElementById('openQrExport')?.addEventListener('click',()=> {
    Array.from(filters.elements).forEach(input=> { if(input.name && exportForm.elements[input.name]) exportForm.elements[input.name].value=input.value; });
    if(exportForm.elements.period.value === '') exportForm.elements.period.value='today';
    dates(exportForm); document.getElementById('qrExportFeedback').textContent=''; document.getElementById('qrExportDialog').showModal();
  });
  exportForm?.addEventListener('change',()=>dates(exportForm));
  exportForm?.addEventListener('submit',async event => {
    event.preventDefault(); const button=document.getElementById('qrDownloadPdf'),message=document.getElementById('qrExportFeedback');
    button.disabled=true; message.textContent='Preparing PDF report…';
    try {
      const params=new URLSearchParams(new FormData(exportForm));
      if(document.getElementById('qrUseActive').checked) ['q','qr_type','status','role'].forEach(name=>params.set(name,filters.elements[name].value));
      const response=await fetch(`../api/scan_history_export.php?${params}`);
      if(!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) { const error=await response.json(); throw new Error(error.error || 'Unable to export report.'); }
      const blob=await response.blob(),url=URL.createObjectURL(blob),link=document.createElement('a');
      link.href=url; link.download=response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || 'qr_scan_history.pdf';
      document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),1000);
      message.textContent='Report downloaded successfully.';
    } catch(error) { message.textContent=error.message; } finally { button.disabled=false; }
  });
  window.addEventListener('focus',()=>loadHistory());
  updateCameraControls(); dates(filters); loadHistory();
})();
