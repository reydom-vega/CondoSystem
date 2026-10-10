/* Read-only layout/keyboard regression against the disposable UI fixture. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2], samplesOnly = process.argv.includes('--samples');
const onlyPage = process.argv.find(value => value.startsWith('--page='))?.slice(7);
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Use the disposable loopback UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
 const manifest = await (await fetch(base+'__manifest')).json();
 const samples = new Set(['resident/dashboard.php','resident/payments.php','resident/residentviolation.php','resident/parking.php','resident/maintenance.php','resident/messages.php','resident/announcements.php','resident/visitors.php','superadmin/admin_dashboard.php','superadmin/unitpayments.php','superadmin/generate_bills.php','superadmin/pending_accounts.php','superadmin/parkinginventory.php','superadmin/parking_configuration.php','superadmin/notification_delivery.php','superadmin/admin_messages.php','superadmin/violations.php','superadmin/maintenancerequests.php','superadmin/staff.php']);
 const entries=[];
 for(const entry of manifest) {
  if(onlyPage && entry.path.split('?')[0] !== onlyPage) continue;
  if(samplesOnly && (!samples.has(entry.path.split('?')[0]) || ![1,6].includes(entry.actor))) continue;
  const html=await(await fetch(base+entry.path+(entry.path.includes('?')?'&':'?')+'fixture_actor='+entry.actor)).text();
  const selected=html.includes('name="portal-workspace"') && html.includes('dashboard-page');
  if (/scanner\.php|book_amenity\.php|bookingrequest\.php|permits\.php|payment_return\.php|resident_service_pass\.php|signuppending\.php|login\.php|signup\.php/.test(entry.path) && selected) throw Error('Excluded page received workspace styling: '+entry.path);
  if (selected) entries.push(entry);
 }
 const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const value=server.address().port;server.close(()=>resolve(value));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-workspace-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket;const report={screenshots:directory,pages:[],interactions:[],failures:[]};
 try {
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}if(!target)throw Error('Chrome unavailable');
  socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);let id=0;const calls=new Map();let errors=[];
  socket.onmessage=event=>{const m=JSON.parse(event.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const key=++id,timer=setTimeout(()=>{calls.delete(key);reject(Error('Timeout '+method));},20000);calls.set(key,{resolve,reject,timer});socket.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);return r.result.value;};
  const nav=async entry=>{errors=[];await send('Page.navigate',{url:base+entry.path+(entry.path.includes('?')?'&':'?')+'fixture_actor='+entry.actor});for(let i=0;i<160;i++){if(await ev("document.readyState==='complete'&&!!window.CelandineUi")){await sleep(550);return;}await sleep(100);}throw Error('Page unavailable '+entry.path);};
  const shot=async name=>{const r=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const check=async(expression,name)=>{if(!await ev(expression))throw Error(name);report.interactions.push(name);console.log('PASS '+name);};
  await send('Page.enable');await send('Runtime.enable');
  for(const width of samplesOnly?[1440,390]:[768,320]) {
   await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
   for(const [index,entry] of entries.entries()) {
    await nav(entry);
    const metrics=await ev(`(()=>{const outside=[...document.querySelectorAll('.dashboard-main *')].filter(el=>el.getClientRects().length&&!el.closest('thead,dialog,.modal-overlay,.confirm-modal,.profile-dropdown,.notification-dropdown,[hidden]')&&el.getBoundingClientRect().right>${width}+2&&getComputedStyle(el.parentElement).overflowX==='visible').slice(0,8).map(el=>el.tagName+'.'+el.className);return {width:innerWidth,scroll:document.documentElement.scrollWidth,outside,titleFont:getComputedStyle(document.querySelector('.dash-title')).fontSize,unlabelled:[...document.querySelectorAll('.workspace-charge-row input')].filter(el=>!el.labels.length||el.labels[0].getBoundingClientRect().height<16).length,filterErrors:[...document.querySelectorAll('.workspace-filter-field input,.workspace-filter-field select')].filter(el=>el.getBoundingClientRect().height>60||el.getBoundingClientRect().right>${width}+2).length,tableCards:[...document.querySelectorAll('.workspace-records')].every(el=>getComputedStyle(el).display===(innerWidth<768?'block':'table'))};})()`);
    const result={path:entry.path.split('?')[0],actor:entry.actor,...metrics,errors:[...errors]};report.pages.push(result);
    if(metrics.scroll>width+2||metrics.unlabelled||metrics.filterErrors||!metrics.tableCards||errors.length) {report.failures.push(result);await shot('failure-'+width+'-'+entry.actor+'-'+entry.path.split('?')[0].replaceAll('/','-'));}
    if(samplesOnly)await shot(width+'-'+entry.actor+'-'+entry.path.split('?')[0].replaceAll('/','-'));
    if(index%15===0)console.log(`${width}px: ${index+1}/${entries.length}`);
   }
  }
  await nav({path:'superadmin/generate_bills.php',actor:6});
  await ev("document.querySelector('#addItemRow').click();document.querySelector('#addBulkChargeRow').click()");await sleep(150);
  await check("(()=>{const inputs=[...document.querySelectorAll('.workspace-charge-field input')];return inputs.every(el=>el.labels.length===1)&&new Set(inputs.map(el=>el.id)).size===inputs.length&&document.querySelectorAll('#itemRows .item-row').length===5&&document.querySelectorAll('#bulkChargeRows .bulk-charge-row').length===2;})()",'Adding custom and monthly charges keeps unique labels and original controls');
  await ev("document.querySelector('#itemRows .item-row:last-child .remove-item-row').click();document.querySelector('#bulkChargeRows .bulk-charge-row:last-child .remove-bulk-row').click()");
  await check("document.querySelectorAll('#itemRows .item-row').length===4&&document.querySelectorAll('#bulkChargeRows .bulk-charge-row').length===1",'Charge remove controls still remove their own row');
  await nav({path:'superadmin/parking_configuration.php',actor:6});
  await check("!!document.querySelector('.sidebar-link.active[href*=parking_configuration]')&&document.querySelector('[name=sticker_price]').name==='sticker_price'&&!!document.querySelector('[name=csrf_token]')",'Parking configuration retains its navigation, policy fields, and CSRF protection');
  await ev("document.querySelector('#menuToggle').click()");
  await check("document.querySelector('#sidebar').classList.contains('open')&&!document.querySelector('#sidebar').inert",'Updated configuration supports the mobile navigation drawer');
  await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
  await check("!document.querySelector('#sidebar').classList.contains('open')&&document.activeElement.id==='menuToggle'",'Escape returns keyboard focus to the navigation control');
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await nav({path:'resident/dashboard.php',actor:2});
  await check("[...document.querySelectorAll('.stat-card')].every(el=>getComputedStyle(el).opacity==='1')",'Tenant dashboard remains visible with reduced motion');
  console.log(`Workspace coverage: ${report.pages.length} layouts, ${report.interactions.length} interactions, ${report.failures.length} failures. Screenshots: ${directory}`);
  if(report.failures.length){console.log(JSON.stringify(report.failures,null,2));process.exitCode=1;}
 }finally{fs.writeFileSync(path.join(directory,'report.json'),JSON.stringify(report,null,2));socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
