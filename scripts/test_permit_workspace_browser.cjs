/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'resident/permits.php')) throw Error('Permit fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-permit-workspace-'));
  const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let socket; let checks=0; const errors=[], exportedImages=[];
  try {
    let target;
    for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab=>tab.type==='page');if(target)break;}catch{}await sleep(100);}
    if(!target)throw Error('Chrome unavailable');
    socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
    let number=0;const calls=new Map();
    socket.onmessage=event=>{const message=JSON.parse(event.data),call=calls.get(message.id);if(call){calls.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);};
    const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++number,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout: '+method));},20000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
    const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
    const until=async expression=>{for(let i=0;i<150;i++){if(await ev(expression))return;await sleep(100);}throw Error('Timed out: '+expression);};
    const check=async(expression,label)=>{if(!await ev(expression)){console.error('Browser errors:',errors);console.error(await ev('document.body.innerText'));throw Error(label);}checks++;console.log('PASS '+label);};
    const shot=async name=>{const result=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
    const go=async(actor,pathname)=>{
      await ev('window.permitNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.permitNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-workspace-tab="${key}"]').click()`);await sleep(300);};
    await send('Page.enable');await send('Runtime.enable');
    await send('Page.setDownloadBehavior',{behavior:'allow',downloadPath:directory});
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    const saved = async () => until("!window.permitNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
    const confirmSubmit = async expression => {
      await ev(expression);
      await until("!!document.querySelector('.system-confirm-accept')");
      await ev("window.permitNavigating=true;document.querySelector('.system-confirm-accept').click()");
      await saved();
    };
    const create = async (actor, item) => {
      await go(actor,'resident/permits.php');
      await ev(`(()=>{const form=document.querySelector('#permitRequestForm');form.querySelector('[name="items[0][item_name]"]').value=${JSON.stringify(item)};form.querySelector('[name=start_time]').value='00:00';form.querySelector('[name=end_time]').value='23:59';form.querySelector('[name=details]').value=${JSON.stringify(item)};window.permitNavigating=true;form.requestSubmit();})()`);
      await saved();
      return ev("document.querySelector('#permitRequests tbody tr [name=request_id]').value");
    };
    const downloads = async (actor, url) => {
      await go(actor,url);
      await until("!!document.querySelector('#serviceQr canvas') && !!document.querySelector('[data-permit-download=jpg]')");
      const documentData=await ev("fetch(document.querySelector('[data-permit-downloads]').dataset.exportUrl+'&download=details',{cache:'no-store'}).then(response=>response.json())");
      for (const format of ['jpg','pdf']) {
        await ev('CelandineTheme.set('+JSON.stringify(format==='jpg'?'dark':'light')+');document.querySelector("[data-permit-download='+format+']").click()');
        await until("document.querySelector('[data-permit-download-feedback]').textContent.includes('download is ready.')");
        const file=path.join(directory,documentData.reference+'.'+format);
        for(let attempt=0;attempt<100&&!fs.existsSync(file);attempt++)await sleep(100);
        if(!fs.existsSync(file))throw Error('Download not saved: '+file);
        const bytes=fs.readFileSync(file);
        if(format==='jpg') { if(bytes[0]!==255||bytes[1]!==216)throw Error('Invalid JPG'); exportedImages.push({url:documentData.qr_url,image:'data:image/jpeg;base64,'+bytes.toString('base64')}); }
        else if(bytes.subarray(0,5).toString()!=='%PDF-')throw Error('Invalid PDF');
        await check("[...document.querySelectorAll('[data-permit-download]')].every(button=>!button.disabled&&!button.classList.contains('portal-button-loading'))",'Owner/tenant '+actor+' downloads actual '+format.toUpperCase()+' and controls recover');
      }
      await shot('approved-permit-downloads-'+actor);
      await send('Emulation.setDeviceMetricsOverride',{width:390,height:1000,deviceScaleFactor:1,mobile:false});
      await check('document.documentElement.scrollWidth<=innerWidth+1','Permit download controls fit mobile for '+actor);
      await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    };
    if(process.argv.includes('--downloads-only')) {
      for(const actor of [1,2]) {
        const id=await create(actor,'Download verification appliance '+Date.now());
        await go(4,'superadmin/service_requests.php?kind=permit&request_id='+id);
        await ev("document.querySelector('.permit-review').open=true");
        await confirmSubmit("document.querySelector('button[name=action][value=gate_approved]').click()");
        await go(actor,'resident/permits.php?tab=requests');
        const url=await ev(`document.querySelector('#permitRequests [name=request_id][value="${id}"]').closest('tr').querySelector('a[href*=resident_service_pass]').href`);
        await downloads(actor,url);
      }
      if(errors.length)throw Error(errors.join('\n'));
      console.log('Passed '+checks+' approved permit download checks. Files: '+directory);return;
    }
    for (const actor of [1,2]) {
      await go(actor,'resident/permits.php');
      await check("[...document.querySelectorAll('[data-workspace-tab]')].map(tab=>tab.dataset.workspaceTab).join(',')==='form,requests,history' && !document.querySelector('#permit-panel-form').hidden",`resident/tenant ${actor}: Request Form, Requests and History in order`);
      await ev("window.permitDraftMarker=42;document.querySelector('[name=transporter]').value='Preserved draft';document.querySelector('#addPermitItem').click();document.querySelectorAll('.permit-item')[1].querySelector('input').value='Second item'");
      await click('requests');await click('history');await click('form');
      await check("window.permitDraftMarker===42 && document.querySelector('[name=transporter]').value==='Preserved draft' && document.querySelectorAll('.permit-item').length===2 && document.querySelectorAll('.permit-item')[1].querySelector('input').value==='Second item'",`resident/tenant ${actor}: tab changes preserve draft and added items without reloading`);
      await ev("document.querySelector('[data-workspace-tab=form]').dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}))");
      await check("document.activeElement.dataset.workspaceTab==='history' && !document.querySelector('#permit-panel-history').hidden",`resident/tenant ${actor}: keyboard navigation opens History`);
      await go(actor,'resident/permits.php?history=1#permitHistory');
      await check("!document.querySelector('#permit-panel-history').hidden",`resident/tenant ${actor}: previous history URL still opens History`);
      await go(actor,'resident/permits.php#permitRequestForm');
      for (const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
        for (const key of ['form','requests','history']) {
          await click(key);
          await check('document.documentElement.scrollWidth<=innerWidth+1',`resident/tenant ${actor}: ${key} fits ${width}px`);
          if(width===390)await shot('resident-'+actor+'-'+key+'-mobile');
        }
      }
      await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    }
    const ownerId=await create(1,'Workspace Owner Appliance');
    await check("!document.querySelector('#permit-panel-requests').hidden && new URL(location.href).searchParams.get('tab')==='requests'",'Submitting a permit opens Requests');
    await check("[...document.querySelectorAll('[data-workspace-tab]')].every(tab=>!tab.querySelector('.workspace-tab-count') || Number(tab.querySelector('.workspace-tab-count').textContent)===document.querySelectorAll('#permit-panel-'+tab.dataset.workspaceTab+' [data-permit-status]').length)",'Permit counts match requests and history records');
    await go(2,'resident/permits.php?tab=requests');
    await check(`!document.querySelector('[name=request_id][value="${ownerId}"]')`,'Tenant cannot see the owner permit or its actions');
    await check(`(async()=>{const response=await fetch('permits.php?request_id=${ownerId}');return response.status===404;})()`,'Tenant cannot open another account permit details');
    await go(4,'superadmin/service_requests.php?kind=permit&request_id='+ownerId);
    await check("[...document.querySelectorAll('[data-workspace-tab]')].map(tab=>tab.dataset.workspaceTab).join(',')==='requests,history' && !document.querySelector('#permit-panel-requests').hidden && !!document.querySelector('[value=gate_changes_requested]')",'Admin has Requests and History with existing review controls');
    await ev("document.querySelector('.permit-review').open=true;document.querySelector('.permit-review [name=admin_notes]').value='Add the model number'");
    await confirmSubmit("document.querySelector('[name=action][value=gate_changes_requested]').click()");
    await check(`!document.querySelector('#permit-panel-requests').hidden && [...document.querySelectorAll('#permitRequests tr')].some(row=>row.querySelector('[name=request_id][value="${ownerId}"]') && row.dataset.permitStatus==='changes_requested')`,'Requests needing corrections stay in Requests');
    await go(1,'resident/permits.php?request_id='+ownerId+'&edit=1');
    await check("!document.querySelector('#permit-panel-form').hidden && document.querySelector('[name=" + JSON.stringify('items[0][item_name]') + "]').value==='Workspace Owner Appliance' && document.querySelector('#permitCategory').disabled",'Edit/resubmit opens the populated Request Form');
    await ev("document.querySelector('[name=" + JSON.stringify('items[0][description]') + "]').value='Model 2026';window.permitNavigating=true;document.querySelector('#permitRequestForm').requestSubmit()");
    await saved();
    await check(`!document.querySelector('#permit-panel-requests').hidden && document.querySelector('#permitRequests [name=request_id][value="${ownerId}"]').closest('tr').dataset.permitStatus==='pending'`,'Resubmission returns the same permit to pending Requests');
    await go(6,'superadmin/service_requests.php?kind=permit&request_id='+ownerId);
    await ev("document.querySelector('.permit-review').open=true");
    await confirmSubmit("document.querySelector('button[name=action][value=gate_approved]').click()");
    await check(`!document.querySelector('#permit-panel-requests').hidden && document.querySelector('#permitRequests [name=request_id][value="${ownerId}"]').closest('tr').dataset.permitStatus==='approved'`,'Superadmin approval keeps the active permit in Requests');
    await go(1,'resident/permits.php?tab=requests');
    const passUrl=await ev(`document.querySelector('#permitRequests [name=request_id][value="${ownerId}"]').closest('tr').querySelector('a[href*=resident_service_pass]').href`);
    await go(1,passUrl);
    await check("!!document.querySelector('#serviceQr') && document.body.textContent.includes('Workspace Owner Appliance')",'Existing gate pass still shows QR and approved items');
    await downloads(1,passUrl);
    await go(4,'superadmin/service_requests.php?kind=permit&request_id='+ownerId);
    await ev("document.querySelector('[name=physical_check]').checked=true");
    await confirmSubmit("document.querySelector('.permit-completion').requestSubmit()");
    await check("!document.querySelector('#permit-panel-history').hidden && new URL(location.href).searchParams.get('tab')==='history'",'Completing movement opens admin History');
    await go(1,'resident/permits.php?request_id='+ownerId);
    await check("!document.querySelector('#permit-panel-history').hidden && document.querySelector('.permit-detail-panel').textContent.includes('Completed') && document.querySelector('.permit-detail-panel').textContent.includes('Model 2026')",'Completed permit details and corrected items remain available in resident History');
    await go(1,passUrl);
    await check("!document.querySelector('#serviceQr')",'Completed permit cannot issue a reusable QR');
    await check("!document.querySelector('[data-permit-downloads]') && (async()=>{const response=await fetch(location.href+'&download=details');return response.status===403;})()",'Completed permit cannot download from its old pass URL');
    const tenantId=await create(2,'Workspace Tenant Furniture');
    await go(4,'superadmin/service_requests.php?kind=permit&request_id='+tenantId);
    await ev("document.querySelector('.permit-review').open=true");
    await confirmSubmit("document.querySelector('button[name=action][value=gate_approved]').click()");
    await go(2,'resident/permits.php?tab=requests');
    const tenantPass=await ev(`document.querySelector('#permitRequests [name=request_id][value="${tenantId}"]').closest('tr').querySelector('a[href*=resident_service_pass]').href`);
    await downloads(2,tenantPass);
    await go(2,'resident/permits.php?tab=requests');
    await confirmSubmit(`document.querySelector('#permitRequests [name=request_id][value="${tenantId}"]').form.requestSubmit()`);
    await check("!document.querySelector('#permit-panel-history').hidden && document.querySelector('#permitHistory').textContent.includes('Cancelled')",'Resident cancellation opens History');
    const rejectId=await create(1,'Workspace Rejected Appliance');
    await go(4,'superadmin/service_requests.php?kind=permit&request_id='+rejectId);
    await ev("document.querySelector('.permit-review').open=true;document.querySelector('.permit-review [name=admin_notes]').value='Schedule is unavailable'");
    await confirmSubmit("document.querySelector('[name=action][value=gate_rejected]').click()");
    await check("!document.querySelector('#permit-panel-history').hidden && document.querySelector('#permitHistory').textContent.includes('Rejected')",'Admin rejection opens History');
    for (const actor of [4,6]) {
      await go(actor,'superadmin/service_requests.php?kind=permit&tab=history');
      await check("[...document.querySelectorAll('#permitHistory [data-permit-status]')].every(row=>['completed','cancelled','rejected','expired'].includes(row.dataset.permitStatus)) && [...document.querySelectorAll('#permitRequests [data-permit-status]')].every(row=>['pending','changes_requested','approved'].includes(row.dataset.permitStatus))",`admin/superadmin ${actor}: active requests and history are separated`);
      await ev("document.querySelector('#permitSearch-history').value='no matching permit';document.querySelector('#permitSearch-history').dispatchEvent(new Event('input'))");
      await check("!document.querySelector('#permitNoMatches-history').hidden && document.querySelector('#permitSearch-requests').value===''",`admin/superadmin ${actor}: History search is independent of Requests`);
      await ev("document.querySelector('#permitSearch-history').value='';document.querySelector('#permitSearch-history').dispatchEvent(new Event('input'));document.querySelector('#permitStatusFilter-history').value='completed';document.querySelector('#permitStatusFilter-history').dispatchEvent(new Event('change'))");
      await check("[...document.querySelectorAll('#permitHistory [data-permit-status]')].filter(row=>!row.hidden).length===1 && document.querySelector('#permitStatusFilter-requests').value===''",`admin/superadmin ${actor}: status filters retain completed history`);
      await shot('staff-'+actor+'-history-desktop');
      for (const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
        for (const key of ['requests','history']) {
          await click(key);
          await check('document.documentElement.scrollWidth<=innerWidth+1',`admin/superadmin ${actor}: ${key} fits ${width}px`);
          if(width===390)await shot('staff-'+actor+'-'+key+'-mobile');
        }
      }
      await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    }
    await go(5,'security/scanner.php');await until("typeof Html5Qrcode==='function'");
    for (const exported of exportedImages) {
      await check(`(async()=>{const image=new Image();image.src=${JSON.stringify(exported.image)};await image.decode();const canvas=document.createElement('canvas');canvas.width=1200;canvas.height=800;canvas.getContext('2d').drawImage(image,0,Math.max(0,image.height-800),image.width,800,0,0,1200,800);const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));return await new Html5Qrcode('reader').scanFile(new File([blob],'permit-qr.png',{type:'image/png'}),false)===${JSON.stringify(exported.url)};})()`,'Exported JPG retains the correct scannable permit QR');
    }
    if(errors.length)throw Error(errors.join('\n'));
    console.log('Passed '+checks+' permit workspace browser checks. Screenshots: '+directory);
  } finally {socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
