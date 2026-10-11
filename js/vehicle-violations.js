(() => {
  'use strict';
  const lookup = document.getElementById('vehicleLookup');
  if (!lookup) return;
  const form = document.getElementById('violationForm');
  const results = document.getElementById('vehicleLookupResults');
  const status = document.getElementById('vehicleLookupStatus');
  const query = document.getElementById('vehicleLookupQuery');
  const mode = document.getElementById('vehicleLookupMode');
  const unknown = document.getElementById('reportUnknownVehicle');
  const fields = document.getElementById('vehicleIncidentFields');
  const dialog = document.getElementById('violationVehicleDialog');
  const incidentDialog = document.getElementById('violationIncidentDialog');
  const body = document.getElementById('violationVehicleDialogBody');
  const incidentBody = document.getElementById('violationIncidentDialogBody');
  const canReview = lookup.dataset.canReview === '1';
  const rates = window.vehicleViolationFineRates || {};
  let searchAbort, historyAbort, debounce, detailVersion = 0, incidentVersion = 0;
  let selected = null;
  const input = name => form.elements.namedItem(name);
  const text = value => value === null || value === undefined || value === '' ? 'Not recorded' : String(value);
  const el = (tag, value, className) => {
    const node = document.createElement(tag);
    if (value !== undefined) node.textContent = text(value);
    if (className) node.className = className;
    return node;
  };
  const button = (label, handler, className = 'btn-small btn-neutral') => {
    const node = el('button', label, className); node.type = 'button'; node.addEventListener('click', handler); return node;
  };
  const animate = node => window.CelandineMotion?.enter(node);
  document.querySelectorAll('.alert').forEach(animate);
  async function api(params, signal) {
    const url = new URL(lookup.dataset.api, location.href);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url, { credentials: 'same-origin', signal, headers: { Accept: 'application/json' } });
    let data;
    try { data = await response.json(); } catch { throw Error('Unable to load records. Refresh your session and try again.'); }
    if (!response.ok) throw Error(data.error || 'Unable to load records.');
    return data;
  }
  function openModal(target, title) {
    target.querySelector('h2').textContent = title;
    if (!target.open) target.showModal();
    animate(target);
  }
  document.querySelectorAll('[data-close-vehicle-dialog]').forEach(node => node.addEventListener('click', () => node.closest('dialog').close()));
  dialog.addEventListener('close', () => { detailVersion++; historyAbort?.abort(); });
  incidentDialog.addEventListener('close', () => { incidentVersion++; });
  function definitionList(pairs) {
    const dl = el('dl', undefined, 'vehicle-record-fields');
    pairs.forEach(([label, value]) => { const cell = el('div'); cell.append(el('dt', label), el('dd', value)); dl.append(cell); });
    return dl;
  }
  function vehicleFields(record, full = false) {
    const pairs = [
      ['Plate', record.plate], ['Registration type', record.registration_type],
      [record.source === 'visitor' ? 'Host resident / tenant' : 'Registered account / slot assignment', record.account_name],
      ['Unit', record.unit_number], ['Parking slot', record.slot_code], ['Parking level', record.level],
    ];
    if (record.source === 'registered') pairs.push(['Make', record.make], ['Model', record.model], ['Color', record.color], ['Year', record.year],
      ['Registration status', record.registration_status], ['Sticker status', record.sticker_status || 'No sticker order'],
      ['Sticker payment', record.sticker_payment_status], ['Sticker number', record.sticker_number]);
    if (record.source === 'visitor') pairs.push(['Visitor name', record.visitor_name],
      ['Visitor registration reference', record.visitor_registration_id ? `VIS-${record.visitor_registration_id}` : 'No linked visitor registration'],
      ['Parking request reference', `PARK-${record.id}`], ['Visit date', record.start_date], ['Parking end date', record.end_date || record.start_date],
      ['Parking approval status', record.parking_status], ['Visitor pass status', record.visitor_status],
      ['Vehicle description', record.vehicle_description], ['Check-in', record.time_in], ['Check-out', record.time_out], ['Gate status', record.gate_status]);
    if (full) pairs.push(['Record source', record.source], ['Database record ID', record.id], ['Inventory status', record.slot_status]);
    return definitionList(pairs);
  }
  async function details(record) {
    const version = ++detailVersion;
    body.replaceChildren(el('p', 'Loading vehicle details…'));
    openModal(dialog, 'Vehicle details');
    try {
      const fresh = await api({ action: 'details', source: record.source, id: record.id });
      if (version !== detailVersion || !dialog.open) return;
      body.replaceChildren(vehicleFields(fresh, true), el('p', 'A registration or slot assignment does not establish who committed the incident.'));
    } catch (error) { if (version === detailVersion) body.replaceChildren(el('p', error.message, 'alert error')); }
  }
  function selectVehicle(record) {
    selected = record;
    const isUnknown = record.source === 'unknown' || !record.normalized_plate;
    input('registration_source').value = isUnknown ? 'unknown' : record.source;
    input('related_record_id').value = isUnknown ? '0' : record.id;
    input('vehicle_parking_slot_id').value = record.parking_slot_id || 0;
    input('observed_plate').value = record.plate || (mode.value === 'plate' ? query.value.trim() : '');
    input('observed_plate').readOnly = !isUnknown;
    input('observed_plate').disabled = false; input('observed_plate').required = true;
    input('incident_at').disabled = false; input('incident_at').required = true;
    if (!input('incident_at').value) {
      const now = new Date();
      input('incident_at').value = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0,16);
    }
    document.getElementById('vehicleReportPenalty').disabled = false;
    input('user_id').value = record.account_id || ''; input('user_id').disabled = true;
    input('user_id').required = false;
    input('user_id').options[0].textContent = 'Responsibility not yet verified';
    document.getElementById('violationResidentLabel').textContent = 'Related Resident / Host';
    document.getElementById('vehicleResidentHint').hidden = false;
    document.getElementById('violationFineHint').textContent = 'Preview of the configured rate. A charge is added only after management approval.';
    input('description').required = true; input('location').required = true;
    if (!input('location').value || input('location').value === input('location').dataset.vehicleLocation) {
      input('location').value = record.slot_code ? [record.slot_code,record.level].filter(Boolean).join(' · ') : '';
      input('location').dataset.vehicleLocation = input('location').value;
    }
    input('fine_amount').required = false; input('fine_amount').disabled = true;
    fields.hidden = false;
    document.getElementById('unknownParkingSlotField').hidden = !isUnknown;
    document.getElementById('unknownParkingSlot').value = record.parking_slot_id || '0';
    document.getElementById('selectedVehicleIdentity').textContent = [record.plate || 'Enter the observed plate', record.make, record.model,
      record.registration_type || 'Unregistered / unidentified vehicle', record.account_name, record.unit_number ? `Unit ${record.unit_number}` : '',
      record.slot_code ? `Slot ${record.slot_code} · ${record.level || ''}` : ''].filter(Boolean).join(' · ');
    form.dataset.confirm = 'Submit this vehicle incident for review? No billing charge is created at this stage.';
    form.dataset.confirmTitle = 'Submit vehicle report'; form.dataset.confirmAction = 'Submit report';
    form.querySelector('button[type=submit]').textContent = 'Submit Vehicle Report';
    syncPenalty();
    form.closest('.violation-workspace')?.dispatchEvent(new CustomEvent('violation:show-issue'));
    form.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
    animate(fields); input('violation_type').focus({ preventScroll: true });
    if (dialog.open) dialog.close();
  }
  function syncPenalty() {
    if (!selected) return;
    input('penalty_type').value = document.getElementById('vehicleReportPenalty').value;
    input('fine_amount').value = input('penalty_type').value === 'warning' ? '0.00' : (rates[input('violation_type').value.trim()] || '').toString();
    input('violation_type').setCustomValidity(input('penalty_type').value === 'fine' && !rates[input('violation_type').value.trim()]
      ? 'Select a violation type with a configured fine, or choose Warning.' : '');
  }
  document.getElementById('vehicleReportPenalty').addEventListener('change', syncPenalty);
  input('violation_type').addEventListener('input', syncPenalty);
  input('violation_type').addEventListener('change', syncPenalty);
  document.getElementById('unknownParkingSlot').addEventListener('change', event => { input('vehicle_parking_slot_id').value = event.target.value; });
  document.getElementById('clearSelectedVehicle').addEventListener('click', () => {
    selected = null; fields.hidden = true;
    input('registration_source').value = ''; input('related_record_id').value = '0'; input('vehicle_parking_slot_id').value = '0';
    ['observed_plate','incident_at'].forEach(name => { input(name).disabled = true; input(name).required = false; input(name).value = ''; });
    document.getElementById('vehicleReportPenalty').disabled = true;
    document.getElementById('vehicleReportPenalty').value = 'fine';
    input('violation_type').setCustomValidity('');
    input('user_id').disabled = false; input('user_id').required = true; input('user_id').value = '';
    input('user_id').options[0].textContent = 'Select resident…';
    document.getElementById('violationResidentLabel').textContent = 'Resident';
    document.getElementById('vehicleResidentHint').hidden = true;
    document.getElementById('violationFineHint').textContent = 'Configured violation types use their listed rate. Enter an amount for a custom type.';
    if (input('location').value === input('location').dataset.vehicleLocation) input('location').value = '';
    delete input('location').dataset.vehicleLocation;
    input('description').required = false; input('location').required = false;
    input('fine_amount').required = true; input('fine_amount').disabled = false; input('penalty_type').value = 'fine';
    form.dataset.confirm = 'Issue this violation and add its fine to the resident’s bill?';
    form.dataset.confirmTitle = 'Issue violation'; form.dataset.confirmAction = 'Issue violation';
    form.querySelector('button[type=submit]').textContent = 'Issue Violation';
    input('violation_type').dispatchEvent(new Event('input'));
  });
  function card(record) {
    const node = el('article', undefined, 'vehicle-result-card');
    const period = record.source === 'parking' ? `Parking inventory · ${record.slot_status}` : record.current ? 'Current record' : 'Historical, pending, or inactive record';
    node.append(el('h3', record.plate || record.slot_code), el('span', period, 'vehicle-record-period'), vehicleFields(record));
    const actions = el('div', undefined, 'vehicle-result-actions');
    actions.append(button('View Vehicle Details', () => details(record)), button('View Previous Violations', () => history(record)),
      button('Issue Violation', () => selectVehicle(record), 'btn-small btn-primary'));
    node.append(actions); return node;
  }
  async function search() {
    clearTimeout(debounce); searchAbort?.abort(); searchAbort = new AbortController();
    const controller = searchAbort;
    unknown.hidden = true;
    if (query.value.trim().length < 2) { status.textContent = 'Enter at least 2 starting characters.'; results.replaceChildren(); results.setAttribute('aria-busy', 'false'); return; }
    status.textContent = 'Searching vehicles and parking…'; results.setAttribute('aria-busy', 'true');
    try {
      const data = await api({ action: 'search', mode: mode.value, query: query.value.trim() }, controller.signal);
      if (controller !== searchAbort) return;
      results.replaceChildren(...data.records.map(card));
      status.textContent = data.records.length ? `${data.records.length} matching records.${data.limited ? ' More records match; enter a longer search.' : ''}`
        : mode.value === 'plate' ? 'Vehicle Not Found — No matching registered resident or visitor vehicle was found.' : 'No matching parking slot was found.';
      unknown.hidden = mode.value !== 'plate' || data.records.length > 0;
      [...results.children].forEach(animate);
    } catch (error) {
      if (error.name !== 'AbortError') { results.replaceChildren(); status.textContent = error.message; }
    } finally { if (controller === searchAbort) results.setAttribute('aria-busy', 'false'); }
  }
  document.getElementById('vehicleLookupForm').addEventListener('submit', event => { event.preventDefault(); search(); });
  query.addEventListener('input', () => { clearTimeout(debounce); searchAbort?.abort(); debounce = setTimeout(search, 300); });
  mode.addEventListener('change', search);
  document.getElementById('vehicleLookupClear').addEventListener('click', () => {
    clearTimeout(debounce); searchAbort?.abort(); searchAbort = null; query.value = ''; results.replaceChildren(); status.textContent = ''; unknown.hidden = true; results.setAttribute('aria-busy','false'); query.focus();
  });
  unknown.addEventListener('click', () => selectVehicle({ source: 'unknown', id: 0, plate: query.value.trim(), normalized_plate: '', registration_type: 'Unregistered / unidentified vehicle' }));
  function history(record) {
    detailVersion++;
    body.replaceChildren(); openModal(dialog, `Previous violations · ${record.plate || record.slot_code}`);
    const summary = el('div', undefined, 'vehicle-history-summary');
    const filters = el('form', undefined, 'vehicle-history-filters');
    const controls = {};
    const add = (label, name, type) => {
      const wrapper = el('div', undefined, 'violation-field'); const caption = el('label', label);
      const control = el(type === 'select' ? 'select' : 'input'); control.name = name; control.id = `vehicle-history-${name}`; caption.htmlFor = control.id;
      if (type !== 'select') control.type = type;
      if (name === 'search') control.maxLength = 100;
      wrapper.append(caption, control); filters.append(wrapper); controls[name] = control; return control;
    };
    add('Search history', 'search', 'search'); add('From', 'from', 'date'); add('To', 'to', 'date');
    const type = add('Violation type', 'type', 'select'); type.append(new Option('All types',''));
    const state = add('Status', 'status', 'select');
    [['','All statuses'],['pending','Pending Review'],['approved','Approved'],['rejected','Rejected'],['warning_issued','Warning'],['unpaid','Unpaid'],['paid','Paid'],['disputed','Disputed'],['waived','Waived']].forEach(([value,label]) => state.append(new Option(label,value)));
    const apply = el('button','Apply filters'); apply.type = 'submit'; filters.append(apply);
    const feedback = el('p'); feedback.setAttribute('role','status');
    const tableWrap = el('div',undefined,'vehicle-history-scroll'); tableWrap.tabIndex = 0; tableWrap.setAttribute('role','region'); tableWrap.setAttribute('aria-label','Vehicle violation history');
    const pages = el('div',undefined,'vehicle-history-pages');
    body.append(summary, filters, feedback, tableWrap, pages);
    let currentPage = 1, knownTypes = false;
    async function load(page = 1) {
      historyAbort?.abort(); historyAbort = new AbortController(); const controller = historyAbort;
      feedback.textContent = 'Loading previous violations…'; apply.disabled = true;
      try {
        const params = { action:'history', source:record.source, id:record.id, page };
        Object.entries(controls).forEach(([key,control]) => { params[key] = control.value; });
        const data = await api(params,controller.signal);
        if (historyAbort !== controller || !dialog.open) return;
        currentPage = data.page;
        summary.replaceChildren(...[['Total Reports','total'],['Pending Reports','pending'],['Confirmed Violations','confirmed'],['Disputed Violations','disputed'],['Waived / Dismissed','dismissed']].map(([label,key]) => el('span',`${label}: ${data.summary[key]}`)));
        if (!knownTypes) { data.types.forEach(value => type.append(new Option(value,value))); knownTypes = true; }
        feedback.textContent = data.total ? `${data.total} matching reports. Plate-only historical matches are unverified and retain their recorded account.` : 'No previous violations found for this vehicle.';
        const table = el('table',undefined,'unit-table'); const head = el('thead'); const headings = el('tr');
        ['Violation ID','Plate Number','Violation Type','Incident Date','Location','Parking Slot','Fine Amount','Violation Status','Payment Status','Actions'].forEach(value => { const th = el('th',value); th.scope = 'col'; headings.append(th); });
        head.append(headings); table.append(head); const rows = el('tbody');
        data.records.forEach(item => {
          const row = el('tr');
          [`#${item.id}`,item.observed_plate,item.violation_type,item.incident_at,item.location,item.slot_code,
            `₱${Number(item.fine_amount).toFixed(2)}`,item.review_status === 'pending' ? 'Pending Review' : item.status || item.review_status,item.payment_status || 'No charge'].forEach(value => row.append(el('td',value)));
          row.cells[1].append(el('p',item.match_label,'vehicle-record-period'));
          const action = el('td'); action.append(button('View Details', () => incident(item.id))); row.append(action); rows.append(row);
        });
        table.append(rows); tableWrap.replaceChildren(table);
        const previous = button('Previous', () => load(currentPage-1)); previous.disabled = currentPage <= 1;
        const next = button('Next', () => load(currentPage+1)); next.disabled = currentPage >= data.pages;
        pages.replaceChildren(previous,el('span',`Page ${data.page} of ${data.pages}`),next);
      } catch (error) { if (error.name !== 'AbortError') { feedback.textContent = error.message; tableWrap.replaceChildren(); pages.replaceChildren(); } }
      finally { if (historyAbort === controller) apply.disabled = false; }
    }
    filters.addEventListener('submit',event => { event.preventDefault(); load(); }); load();
  }
  async function incident(id) {
    const version = ++incidentVersion;
    incidentBody.replaceChildren(el('p','Loading incident…')); openModal(incidentDialog,`Violation #${id}`);
    try {
      const data = await api({action:'violation',id});
      if (version !== incidentVersion || !incidentDialog.open) return;
      incidentBody.replaceChildren(definitionList([
        ['Violation type',data.violation_type],['Observed plate',data.observed_plate],['Incident date',data.incident_at || data.created_at],
        ['Review status',data.review_status],['Financial status',data.status || 'No charge'],['Reporting personnel',data.reporting_personnel],
        ['Responsible account',data.account_name_snapshot || data.legacy_account_name],['Recorded unit',data.unit_snapshot || data.legacy_unit_number],
        ['Fine amount',`₱${Number(data.fine_amount).toFixed(2)}`],['Payment reference',data.payment_id ? `DUES-${data.payment_id}` : 'No charge'],
        ['Payment status',data.payment_status],['Reviewer',data.reviewer_name],['Reviewed at',data.reviewed_at],
      ]));
      if (data.review_status === 'legacy') incidentBody.append(el('p','Legacy account/unit information may reflect the current account. No vehicle identity is inferred from account ownership.'));
      [['Incident description',data.description],['Location',data.location],['Reporting remarks',data.report_remarks],['Admin remarks / responsibility verification',data.admin_remarks],['Dispute reason',data.dispute_reason]].forEach(([label,value]) => { incidentBody.append(el('h3',label),el('p',value,'vehicle-dialog-message')); });
      if (data.vehicle_snapshot) { incidentBody.append(el('h3','Vehicle details recorded with this report'),vehicleFields(data.vehicle_snapshot,true)); }
      if (data.evidence_path) { const link = el('a','View incident evidence'); link.href = `../violation_evidence.php?id=${Number(data.id)}`; link.target='_blank'; link.rel='noopener'; incidentBody.append(link); }
      if (data.audit_authorized) {
        incidentBody.append(el('h3','Related audit events'));
        if (!data.audit_events.length) incidentBody.append(el('p','No related audit events found.'));
        data.audit_events.forEach(event => incidentBody.append(el('p',`${event.created_at} · ${event.admin_name} (${event.admin_role}) · ${event.action} · ${event.details}`,'vehicle-dialog-message')));
      }
      if (canReview && data.review_status === 'pending') reviewForm(data);
    } catch (error) { if (version === incidentVersion) incidentBody.replaceChildren(el('p',error.message,'alert error')); }
  }
  function reviewForm(report) {
    const review = el('form',undefined,'vehicle-incident-review'); review.method = 'post'; review.action = 'violations.php';
    review.dataset.confirm='Save this review decision? Approval may create a billing charge for the verified responsible account.';
    review.dataset.confirmTitle='Review vehicle report'; review.dataset.confirmAction='Save decision';
    const hidden = (name,value) => { const field=el('input'); field.type='hidden'; field.name=name; field.value=value; review.append(field); };
    hidden('csrf_token',input('csrf_token').value); hidden('action','review_vehicle_report'); hidden('violation_id',report.id);
    review.append(el('h3','Review responsibility and evidence'));
    const accountLabel=el('label','Verified responsible resident / tenant'); const accounts=input('user_id').cloneNode(true);
    accounts.name='responsible_user_id'; accounts.id='vehicle-review-account'; accounts.disabled=false; accounts.required=true; accounts.value=''; accountLabel.htmlFor=accounts.id;
    // Never preselect the visitor host or infer the liable account from a parking slot.
    review.append(accountLabel,accounts);
    const noteLabel=el('label','Verification / rejection notes'); const note=el('textarea'); note.id='vehicle-review-note'; note.name='responsibility_note'; note.required=true; note.maxLength=5000; note.rows=3; noteLabel.htmlFor=note.id; review.append(noteLabel,note);
    const check=el('input'); check.type='checkbox'; check.name='responsibility_verified'; check.value='1'; check.required=true;
    const label=el('label'); label.append(check,document.createTextNode('I verified the responsible account using the incident evidence and applicable PMO rules.')); review.append(label);
    if (report.registration_source === 'visitor' && report.penalty_type === 'fine') {
      if (lookup.dataset.visitorPolicy !== '1') review.append(el('p','Visitor host billing is disabled by PMO policy. Leave pending for investigation or reject this report.'));
      else { const policy=el('input'); policy.type='checkbox'; policy.name='visitor_policy_verified'; policy.value='1'; policy.required=true; const caption=el('label'); caption.append(policy,document.createTextNode('The configured PMO visitor policy applies and permits this verified account to be charged.')); review.append(caption); }
    }
    const actions=el('div',undefined,'vehicle-result-actions');
    const approve=el('button','Approve report'); approve.type='submit'; approve.name='decision'; approve.value='approve';
    approve.disabled=report.registration_source==='visitor' && report.penalty_type==='fine' && lookup.dataset.visitorPolicy!=='1';
    const reject=el('button','Reject report'); reject.type='submit'; reject.name='decision'; reject.value='reject'; reject.formNoValidate=true;
    actions.append(approve,reject); review.append(actions); incidentBody.append(review);
  }
  document.addEventListener('click', event => { const target=event.target.closest('[data-vehicle-violation-details]'); if (target) incident(Number(target.dataset.vehicleViolationDetails)); });
})();
