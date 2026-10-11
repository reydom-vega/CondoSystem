/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'resident/maintenance.php')) throw Error('Maintenance fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-maintenance-workspace-'));
  const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let socket; let checks=0; const errors=[];
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
      await ev('window.maintenanceNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.maintenanceNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-maintenance-tab="${key}"]').click()`);await sleep(300);};
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    const active = key => "!document.querySelector('#maintenance-panel-"+key+"').hidden";
    const submit = async(selector,button='button[type=submit]') => {
      await ev('window.maintenanceNavigating=true;(()=>{const form=document.querySelector('+JSON.stringify(selector)+');form.requestSubmit(form.querySelector('+JSON.stringify(button)+'));})()');
      await sleep(150);
      await ev("document.querySelector('.system-confirm-accept')?.click()");
      await until("!window.maintenanceNavigating && document.readyState==='complete' && !!window.CelandineUi");
      await sleep(200);
    };
    await go(1,'resident/maintenance.php');
    await check("[...document.querySelectorAll('[data-maintenance-tab]')].map(t=>t.dataset.maintenanceTab).join('|')==='form|requests|history' && "+active('form'),'owner sees Request Form, Requests, History with Request Form first');
    await ev("window.tabMarker=1;document.querySelector('#description').value='Unsaved draft'");
    await click('requests');
    await check("window.tabMarker===1 && "+active('requests')+" && document.querySelectorAll('#maintenance-panel-requests .maintenance-request-item').length===1",'Requests opens without reloading and contains the existing pending request');
    const ownerId=await ev("Number(new URL(document.querySelector('#maintenance-panel-requests .maintenance-nav-button').href).searchParams.get('view'))");
    await click('history');
    await check(active('history')+" && document.querySelectorAll('#maintenance-panel-history .maintenance-request-item').length===0",'active requests are excluded from History');
    await ev('history.back()');await until(active('requests'));
    await check(active('requests'),'browser Back restores the Requests tab');
    await click('form');
    await check("document.querySelector('#description').value==='Unsaved draft'",'tab switching preserves the unsaved request form');
    await ev("document.querySelector('[data-maintenance-tab=form]').dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}))");
    await check(active('history')+" && document.activeElement.dataset.maintenanceTab==='history'",'keyboard navigation selects and focuses History');
    await go(2,'resident/maintenance.php');
    await check("document.querySelectorAll('.maintenance-request-item').length===0",'tenant does not see the unit owner requests');
    await check("(async()=>{const form=document.querySelector('.maintenance-submit-form');const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form)});return (await response.text()).includes('Choose a valid issue, location, and urgency')})()",'backend rejects an incomplete maintenance request');
    await ev("document.querySelector('#issue_type').value='Electrical';document.querySelector('#location').value='Bedroom';document.querySelector('#description').value='Tenant workspace test: outlet needs repair.'");
    await submit('.maintenance-submit-form');
    await check(active('requests')+" && document.querySelectorAll('#maintenance-panel-requests .maintenance-request-item').length===1 && !!document.querySelector('.alert.success')",'successful tenant submission opens Requests');
    const tenantId=await ev("Number(new URL(document.querySelector('#maintenance-panel-requests .maintenance-nav-button').href).searchParams.get('view'))");
    await go(1,'resident/maintenance.php?view='+tenantId);
    await check("!document.querySelector('.maintenance-detail-card') && ![...document.querySelectorAll('.maintenance-request-item')].some(item=>item.textContent.includes('Electrical'))",'owner cannot retrieve tenant request through a detail URL');
    await go(4,'superadmin/maintenancerequests.php?view='+tenantId);
    await check("[...document.querySelectorAll('[data-maintenance-tab]')].map(t=>t.dataset.maintenanceTab).join('|')==='requests|history' && "+active('requests'), 'admin sees Requests and History with the existing review form');
    await check("new Set([...document.querySelectorAll('[id]')].map(e=>e.id)).size===document.querySelectorAll('[id]').length",'admin panels have unique field and tab IDs');
    await click('history');await click('requests');
    await submit('.maintenance-admin-actions form','button[value=approve]');
    await check("document.querySelector('.maintenance-admin-detail .maintenance-status-badge').textContent.trim()==='Approved'",'admin approval continues to work');
    await go(8,'superadmin/maintenancerequests.php?view='+tenantId);
    await submit('.maintenance-admin-actions form','button[value=start]');
    await check("!!document.querySelector('.maintenance-complete-form')",'maintenance staff can start approved work');
    await ev("document.querySelector('[name=completion_note]').value='Outlet repaired and checked.'");
    await submit('.maintenance-complete-form');
    await go(2,'resident/maintenance.php?view='+tenantId);
    await check(active('requests')+" && !!document.querySelector('button[value=confirm]') && !!document.querySelector('button[value=problem]')",'completed work remains in Requests for resident confirmation');
    await submit('.maintenance-detail-actions form','button[value=problem]');
    await check(active('requests')+" && document.querySelector('.maintenance-detail-card .maintenance-status-badge').textContent==='Reopened'",'reporting a problem reopens the request in Requests');
    await go(8,'superadmin/maintenancerequests.php?view='+tenantId);
    await submit('.maintenance-admin-actions form','button[value=reopen_start]');
    await submit('.maintenance-complete-form');
    await go(2,'resident/maintenance.php?view='+tenantId);
    await submit('.maintenance-detail-actions form','button[value=confirm]');
    await check(active('history')+" && document.querySelector('.maintenance-detail-card .maintenance-status-badge').textContent==='Successful' && document.querySelectorAll('#maintenance-panel-requests .maintenance-request-item').length===0",'confirmation moves tenant request into History and opens its details');
    await go(2,'resident/maintenance.php?tab=form');
    await ev("document.querySelector('#issue_type').value='Other';document.querySelector('#location').value='Other';document.querySelector('#description').value='Cancellation tab test'");
    await submit('.maintenance-submit-form');
    const cancelId=await ev("Number(new URL(document.querySelector('#maintenance-panel-requests .maintenance-nav-button').href).searchParams.get('view'))");
    await go(2,'resident/maintenance.php?view='+cancelId);
    await submit('.maintenance-detail-actions form','button[value=cancel]');
    await check(active('history')+" && document.querySelector('.maintenance-detail-card .maintenance-status-badge').textContent==='Cancelled'",'cancellation opens the record in History');
    await go(6,'superadmin/maintenancerequests.php?view='+ownerId);
    await submit('.maintenance-admin-actions form:nth-of-type(2)','button[value=reject]');
    await check(active('history')+" && document.querySelector('.maintenance-admin-detail .maintenance-status-badge').textContent==='Rejected'",'superadmin rejection moves the request into History');
    await go(4,'superadmin/maintenancerequests.php?status=closed');
    await check(active('history')+" && document.querySelector('#requestStatus-history').value==='closed' && document.querySelectorAll('#maintenance-panel-history .maintenance-view-link').length===1",'legacy closed status URL opens filtered History');
    await go(4,'superadmin/maintenancerequests.php?tab=history&search=MR-'+String(tenantId).padStart(5,'0'));
    await check(active('history')+" && document.querySelectorAll('#maintenance-panel-history .maintenance-view-link').length===1",'staff request-code search works in History');
    await shot('admin-history-desktop');
    for(const width of [768,390,320]){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
      for(const [actor,pathname] of [[2,'resident/maintenance.php'],[4,'superadmin/maintenancerequests.php?tab=history']]){
        await go(actor,pathname);
        await check("document.documentElement.scrollWidth<=innerWidth+1 && [...document.querySelectorAll('[data-maintenance-tab]')].every(t=>{const r=t.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.height>=40})",'maintenance tabs fit '+width+'px for actor '+actor);
        if(width===320)await shot('mobile-'+actor);
      }
    }
    if(errors.length)throw Error(errors.join('\n'));
    console.log('Passed '+checks+' maintenance workspace browser checks. Screenshots: '+directory);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
