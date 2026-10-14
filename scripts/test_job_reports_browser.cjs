/* Only use the disposable test_ui_redesign.php --preview fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='job_report.php'))throw Error('Missing profile fixture');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-job-reports-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[];
 try {
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let seq=0;const calls=new Map();socket.onmessage=e=>{const m=JSON.parse(e.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout '+method));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<200;i++){if(await ev(expression))return;await sleep(75);}throw Error('Timed out '+expression+'\n'+await ev('document.body.innerText'));};
  const shot=async name=>{const metrics=await send('Page.getLayoutMetrics');const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:metrics.cssContentSize.width,height:metrics.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const check=async(expression,label)=>{if(!await ev(expression)){await shot('failure');throw Error(label);}checks++;};
  const go=async(actor,route='job_report.php')=>{await ev('window.parkingFeeNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&!!window.CelandineUi");await sleep(350);if(await ev("!!document.querySelector('[data-job-reports]')"))await until("document.querySelector('[data-job-reports]').getAttribute('aria-busy')==='false'");};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  const post=(action,values={},scope='personal')=>ev(`(async()=>{const cfg=JSON.parse(document.querySelector('[data-jr-config]').textContent),d=new URLSearchParams({csrf_token:cfg.csrf,action:${JSON.stringify(action)},scope:${JSON.stringify(scope)}});Object.entries(${JSON.stringify(values)}).forEach(([k,v])=>d.set(k,typeof v==='object'?JSON.stringify(v):v));const r=await fetch(cfg.endpoint,{method:'POST',body:d,cache:'no-store'});return {status:r.status,data:await r.json(),cache:r.headers.get('cache-control')};})()`);
  const key=()=>require('node:crypto').randomBytes(24).toString('hex');
  const click=async selector=>{await until("document.querySelector('[data-job-reports]').getAttribute('aria-busy')==='false'");await ev(`document.querySelector(${JSON.stringify(selector)}).click()`);await sleep(200);await until("document.querySelector('[data-job-reports]').getAttribute('aria-busy')==='false'");};
  const fill=async(selector,value)=>ev(`(()=>{const el=document.querySelector(${JSON.stringify(selector)});el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  if(process.argv.includes('--self-only')){
    await go(6);
    const input={type:'daily',period:'2026-10-13',notes:{accomplished:'Reviewed property management work.'},request_key:key(),confirmed:'1'};
    const preview=(await post('preview',input)).data.data;
    const saved=(await post('file',{...input,preview_hash:preview.preview_hash})).data.data;
    await go(6,'superadmin/staff.php?tab=reports&job_report='+saved.id);
    await until("document.querySelector('[data-jr-dialog]').open");
    await check("!document.querySelector('.jr-review-feedback')&&document.querySelector('[data-jr-dialog-actions]').textContent.includes('independently reviewed')",'self-filed UI requires independent review');
    await check("document.querySelector('[data-jr-files]').textContent.includes('Self-filed')",'Super own filing appears in central table with label');
    const self=await post('review',{id:saved.id,decision:'reviewed',request_key:key()},'management');if(self.data.ok)throw Error('Self review bypass');checks++;
    const personal=await post('overview',{user_id:4,staff_id:'STAFF-00004'});if(personal.data.data.actor.id!==6)throw Error('Arbitrary actor override');checks++;
    for(const actor of [1,2]){
      await go(actor,'resident/dashboard.php');
      const denied=await ev(`(async()=>{const r=await fetch(${JSON.stringify(base+'api/job_reports.php')},{method:'POST',body:new URLSearchParams({action:'list',scope:'management'})});return r.status;})()`);
      if(denied!==403)throw Error('Resident or tenant reports endpoint allowed');checks++;
    }
    if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,scope:'Super self-file and resident/tenant HTTP access'}));return;
  }
  for(const role of [4,5,6,7,8]){
    await go(role);await until("document.querySelector('[data-jr-summary] .jr-stat')&&document.querySelector('[data-jr-files] .jr-pager')");
    await check("document.querySelector('[data-jr-period] [name=type]').value==='daily'",'daily default role '+role);
    await check("[...document.querySelectorAll('[data-workspace-tab]')].map(t=>t.textContent).join('|')==='Job Report|My Filed Reports'&&!document.querySelector('#job-report-panel-report').hidden&&document.querySelector('#job-report-panel-filed').hidden",'personal tabs and default report form role '+role);
    await check("document.querySelectorAll('script[src*=workspace-tabs]').length===1&&document.querySelectorAll('link[href*=workspace-tabs]').length===1",'one shared tab controller and stylesheet role '+role);
    await ev("window.jobReportDocument=123;document.querySelector('[data-jr-notes] textarea').value='Unsaved work note';document.querySelector('[data-workspace-tab=filed]').click();document.querySelector('[data-jr-file-filters] [name=q]').value='Saved search';");
    await check("!document.querySelector('#job-report-panel-filed').hidden&&document.querySelector('#job-report-panel-report').hidden&&new URL(location.href).searchParams.get('tab')==='filed'",'second tab shows filed reports role '+role);
    await click('[data-workspace-tab=report]');
    await check("window.jobReportDocument===123&&document.querySelector('[data-jr-notes] textarea').value==='Unsaved work note'&&document.querySelector('[data-jr-file-filters] [name=q]').value==='Saved search'",'tab switching preserves notes and filters without reload role '+role);
    await ev('history.back()');await until("!document.querySelector('#job-report-panel-filed').hidden");checks++;
    await ev("document.querySelector('[data-workspace-tab=filed]').dispatchEvent(new KeyboardEvent('keydown',{key:'Home',bubbles:true}));document.querySelector('[data-jr-notes] textarea').value='';document.querySelector('[data-jr-file-filters] [name=q]').value='';");
    await check("document.activeElement.dataset.workspaceTab==='report'&&!document.querySelector('#job-report-panel-report').hidden",'keyboard tabs select and focus report form role '+role);
    const result=await post('overview');if(!result.data.ok||result.data.data.actor.id!==role||!result.cache.includes('no-store'))throw Error('Own scope and no cache role '+role);checks++;
    const forbidden=await post('list',{},'management');if(role!==6&&forbidden.status!==403)throw Error('Central guard role '+role);checks++;
    await ev("document.querySelector('[data-jr-period] [name=type]').value='monthly';document.querySelector('[data-jr-period] [name=type]').dispatchEvent(new Event('change',{bubbles:true}));");
    await until("document.querySelector('[data-jr-period] [name=period]').type==='month'&&!document.querySelector('[data-jr-period] button').disabled");
    await check("document.querySelector('[data-jr-period] [name=period]').value===JSON.parse(document.querySelector('[data-jr-config]').textContent).month",'monthly current calendar month role '+role);
  }
  await go(4);await until("document.querySelector('[data-jr-summary] .jr-stat')&&document.querySelector('[data-jr-files] .jr-pager')");
  await fill('[data-jr-notes] [name=accomplished]','Inspected facilities and responded to service requests.');await click('[data-jr-save]');await until("document.querySelector('[data-jr-draft-state]').textContent.includes('Revision 1')");
  await go(4);await until("document.querySelector('[data-jr-notes] [name=accomplished]').value.includes('Inspected facilities')");checks++;
  await click('[data-jr-preview]');await until("document.querySelector('[data-jr-dialog]').open&&document.querySelector('.jr-confirm input')");
  await check("document.querySelector('[data-jr-document]').textContent.includes('Staff-Reported Work Notes')&&document.querySelector('[data-jr-document]').textContent.includes('System-Recorded Activity')",'preview source labels');
  await ev("document.querySelector('.jr-confirm input').checked=true;[...document.querySelectorAll('[data-jr-dialog-actions] button')].find(b=>b.textContent==='File Report').click()");await until("document.querySelector('[data-jr-dialog-message]').textContent.includes('filed successfully')");
  const list=(await post('list')).data.data,report=list.rows[0];if(!report||report.status!=='submitted')throw Error('UI file submission');checks++;
  const detail=(await post('detail',{id:report.id})).data.data;const hash=detail.snapshot_hash;
  await ev("document.querySelector('[data-jr-close]').click()");
  await click('[data-workspace-tab=filed]');await click('[data-jr-files] button');
  await check(`document.querySelector('[data-jr-dialog]').open&&document.querySelector('[data-jr-document]').textContent.includes(${JSON.stringify(report.reference)})`,'filed tab opens existing report detail modal');
  await ev("document.querySelector('[data-jr-close]').click()");
  await go(6,'superadmin/staff.php');await until("document.querySelector('[data-jr-review-summary] .jr-stat')");
  await check("document.querySelector('[data-workspace-tab=reports]').getAttribute('aria-selected')==='true'&&!document.querySelector('[data-workspace-panel=reports]').hidden",'Staff Reports first default tab');
  await check("[...document.querySelectorAll('[data-workspace-tab]')].map(e=>e.textContent).join('|')==='Staff Reports|Staff Information|Add Staff Account'",'old Staff tabs preserved in order');
  await click('[data-workspace-tab=information]');await check("!document.querySelector('[data-workspace-panel=information]').hidden&&document.querySelector('.staff-table')",'staff information remains usable');
  await click('[data-workspace-tab=add]');await check("!document.querySelector('[data-workspace-panel=add]').hidden&&document.querySelector('[name=action][value=add_staff]')",'add staff account remains available');await click('[data-workspace-tab=reports]');
  await fill('[data-jr-file-filters] [name=q]','staff-00004');await ev("document.querySelector('[data-jr-file-filters]').requestSubmit()");await until("document.querySelector('[data-job-reports]').getAttribute('aria-busy')==='false'");await until("document.querySelector('[data-jr-files]').textContent.includes('STAFF-00004')&&!document.querySelector('[data-jr-file-filters] button[type=submit]').disabled");
  await check("document.querySelector('[data-jr-files]').textContent.includes('Admin Staff')",'case insensitive Staff ID search');
  await click('[data-jr-files] button');await until("document.querySelector('[data-jr-dialog]').open&&document.querySelector('.jr-review-feedback textarea')");
  const pending=(await post('detail',{id:report.id},'management')).data.data;if(pending.status!=='submitted')throw Error('Opening silently reviewed');checks++;
  await fill('.jr-review-feedback textarea','Please clarify the pending tasks and follow-up schedule.');await ev("[...document.querySelectorAll('[data-jr-dialog-actions] button')].find(b=>b.textContent==='Return for Clarification').click()");await until("document.querySelector('[data-jr-dialog-message]').textContent.includes('Review recorded')");
  await ev("document.querySelector('[data-jr-close]').click()");await go(4,'job_report.php?job_report='+report.id);await until("document.querySelector('[data-jr-dialog]').open&&document.querySelector('[data-jr-dialog-actions]').textContent.includes('Prepare corrected version')");
  await check("document.querySelector('[data-jr-document]').textContent.includes('Please clarify the pending tasks')",'own private review feedback');
  await check("!document.querySelector('#job-report-panel-filed').hidden",'report notification deep link selects filed tab');
  await ev("[...document.querySelectorAll('[data-jr-dialog-actions] button')].find(b=>b.textContent==='Prepare corrected version').click()");await until("!document.querySelector('[data-jr-dialog]').open&&!document.querySelector('[data-jr-correction]').hidden");
  await check("!document.querySelector('#job-report-panel-report').hidden&&document.querySelector('#job-report-panel-filed').hidden",'preparing correction returns to the visible report form');
  await fill('[data-jr-notes] [name=pending]','Follow up with the service team on the next work day.');await click('[data-jr-preview]');await until("document.querySelector('[data-jr-dialog-actions]').textContent.includes('File Corrected Version')");
  await ev("document.querySelector('.jr-confirm input').checked=true;[...document.querySelectorAll('[data-jr-dialog-actions] button')].find(b=>b.textContent==='File Corrected Version').click()");await until("document.querySelector('[data-jr-dialog-message]').textContent.includes('filed successfully')");
  const versions=(await post('list')).data.data.rows;if(versions.length!==2||Number(versions[0].version)!==2)throw Error('Correction version UI');checks++;
  if((await post('detail',{id:report.id})).data.data.snapshot_hash!==hash)throw Error('Original report changed');checks++;
  const second=versions[0];
  await ev("document.querySelector('[data-jr-close]').click()");await go(6,'superadmin/staff.php?tab=reports&job_report='+second.id);await until("document.querySelector('.jr-review-feedback textarea')");
  await ev("[...document.querySelectorAll('[data-jr-dialog-actions] button')].find(b=>b.textContent==='Mark Reviewed').click()");await until("document.querySelector('[data-jr-dialog-message]').textContent.includes('Review recorded')");checks++;
  await ev("document.querySelector('[data-jr-close]').click()");
  for(const theme of ['dark','light']){
    await ev(`window.CelandineTheme.set(${JSON.stringify(theme)})`);await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await sleep(200);
    await check("document.documentElement.scrollWidth<=window.innerWidth+1",'mobile no page overflow '+theme);
    await shot('staff-reports-mobile-'+theme);
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:950,deviceScaleFactor:1,mobile:false});await shot('staff-reports-desktop-'+theme);
    await go(4);await until("document.querySelector('[data-jr-summary] .jr-stat')&&document.querySelector('[data-jr-files] .jr-pager')");await check(`document.documentElement.dataset.theme===${JSON.stringify(theme)}`,'theme persists personal '+theme);await shot('my-job-report-'+theme);
    await click('[data-workspace-tab=filed]');await shot('my-filed-reports-'+theme);
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await check("document.documentElement.scrollWidth<=innerWidth+1&&[...document.querySelectorAll('[data-workspace-tab]')].every(t=>t.getBoundingClientRect().right<=innerWidth+1)",'personal filed tabs fit mobile '+theme);await shot('my-filed-reports-mobile-'+theme);
    await send('Page.reload');await until("document.readyState==='complete'&&document.querySelector('[data-job-reports]')?.getAttribute('aria-busy')==='false'");
    await check("!document.querySelector('#job-report-panel-filed').hidden&&document.querySelector('[data-workspace-tab=filed]').getAttribute('aria-selected')==='true'",'filed tab persists on reload '+theme);
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:950,deviceScaleFactor:1,mobile:false});
    await go(6,'superadmin/staff.php?tab=reports');await until("document.querySelector('[data-jr-review-summary] .jr-stat')");
  }
  for(const format of ['pdf','png']){
    const download=await ev(`(async()=>{const c=JSON.parse(document.querySelector('[data-jr-config]').textContent),r=await fetch(c.export,{method:'POST',body:new URLSearchParams({scope:'management',csrf_token:c.csrf,id:${second.id},format:'${format}'}),cache:'no-store'}),b=new Uint8Array(await r.arrayBuffer());return {status:r.status,cache:r.headers.get('cache-control'),type:r.headers.get('content-type'),signature:[...b.slice(0,4)]};})()`);
    if(download.status!==200||!download.cache.includes('no-store')||(format==='pdf'?download.signature[0]!==37:![80,137].includes(download.signature[0])))throw Error('Authorized '+format+' export');checks++;
  }
  for(const actor of [1,2,5,7,8]){
    await go(actor,actor<=2?'resident/dashboard.php':'job_report.php');
    if(actor<=2){await check("!document.querySelector('a[href$=\"job_report.php\"]')",'resident tenant menu no job reports '+actor);}
    else{const denied=await post('detail',{id:report.id});if(denied.data.ok)throw Error('Personal IDOR actor '+actor);checks++;}
  }
  await go(4);await until("document.querySelector('[data-jr-summary] .jr-stat')&&document.querySelector('[data-jr-files] .jr-pager')");
  const csrf=await ev("(async()=>{const cfg=JSON.parse(document.querySelector('[data-jr-config]').textContent),r=await fetch(cfg.endpoint,{method:'POST',body:new URLSearchParams({action:'file',csrf_token:'invalid'})});return r.status;})()");if(csrf!==403)throw Error('CSRF guard');checks++;
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
