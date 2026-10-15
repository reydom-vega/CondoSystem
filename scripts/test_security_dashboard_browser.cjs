/* Only use the disposable test_ui_redesign.php --preview fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='security/security_dashboard.php'))throw Error('Missing security fixture');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-security-dashboard-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[];
 try {
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let seq=0;const calls=new Map();socket.onmessage=e=>{const m=JSON.parse(e.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout '+method));},120000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<200;i++){if(await ev(expression))return;await sleep(75);}throw Error('Timed out '+expression+'\n'+await ev('document.body.innerText'));};
  const shot=async name=>{await ev('window.scrollTo(0,0)');await sleep(50);const metrics=await send('Page.getLayoutMetrics');const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:metrics.cssContentSize.width,height:metrics.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const check=async(expression,label)=>{if(!await ev(expression)){await shot('failure');throw Error(label);}checks++;};
  const go=async(actor,route='resident/parking.php')=>{await ev('window.parkingFeeNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&!!window.CelandineUi");await sleep(350);};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  await go(5,'security/security_dashboard.php');await until("!!document.querySelector('[data-security-stat=visitors_inside]')");
  await check("['Registered Vehicles','Visitor Registrations','Parking & Stickers'].every(label=>!Array.from(document.querySelectorAll('.sidebar-link')).some(a=>a.textContent.includes(label)))",'Removed features absent from sidebar');
  await check("document.querySelectorAll('.security-actions a').length===3&&document.querySelectorAll('.security-home .admin-stat-card').length===3&&document.querySelectorAll('.security-home .recent-panel').length===2",'Dashboard focuses on scanner/reports/visitor movements');
  await check("!Array.from(document.scripts).some(s=>s.src.endsWith('js/live-updates.js'))&&!document.body.textContent.includes('Pending Parking Requests')",'Removed obsolete dashboard queue/poller');
  const data=await ev(`(async()=>{const c=JSON.parse(document.querySelector('[data-security-config]').textContent),r=await fetch(c.endpoint);return {status:r.status,cache:r.headers.get('cache-control'),body:await r.json()}})()`);if(data.status!==200||!data.cache.includes('no-store')||!data.body.success)throw Error('Security API');checks++;
  await check(`document.querySelector('[data-security-stat=visitors_inside]').textContent===${JSON.stringify(String(data.body.data.visitors_inside))}`,'Rendered statistics match guarded API');
  await ev("document.querySelector('[data-security-refresh]').click()");await until("!document.querySelector('[data-security-home]').hasAttribute('aria-busy')");await check("!document.querySelector('[data-security-refresh]').disabled&&document.querySelector('[data-security-feedback]').hidden",'Manual refresh completes and cleans loading');
  await ev("document.getElementById('profileToggle').click()");await check("document.getElementById('profileMenu').classList.contains('open')",'Profile dropdown opens');await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape'});await check("!document.getElementById('profileMenu').classList.contains('open')",'Profile dropdown Escape closes');
  for(const width of [1366,768,390,320])for(const theme of ['dark','light']){await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<700});await ev('CelandineTheme.set('+JSON.stringify(theme)+')');await check('document.documentElement.scrollWidth<=innerWidth+1','Security responsive '+width+' '+theme);await check("document.querySelector('.security-actions a').getBoundingClientRect().height>=44",'Usable action controls '+width+' '+theme);if(width===390||width===1366)await shot('security-'+theme+'-'+width);}
  const offline=await ev("(async()=>{const value=document.querySelector('[data-security-stat=visitors_inside]').textContent,original=fetch;window.fetch=()=>Promise.reject(Error('offline'));document.querySelector('[data-security-refresh]').click();await new Promise(r=>setTimeout(r,100));window.fetch=original;return !document.querySelector('[data-security-feedback]').hidden&&!document.querySelector('[data-security-refresh]').disabled&&document.querySelector('[data-security-stat=visitors_inside]').textContent===value})()");if(!offline)throw Error('Offline cleanup/stale figures');checks++;
  await ev("document.querySelector('[data-security-refresh]').click()");await until("!document.querySelector('[data-security-home]').hasAttribute('aria-busy')&&document.querySelector('[data-security-feedback]').hidden");checks++;
  for(const route of ['superadmin/registeredvehicles.php','superadmin/service_requests.php?kind=visitor','superadmin/parking.php']){const result=await ev(`fetch(${JSON.stringify(new URL(route,base).href)}+${JSON.stringify(route.includes('?')?'&fixture_actor=5':'?fixture_actor=5')}).then(r=>r.status)`);if(result!==403)throw Error('Removed route access '+route+' '+result);checks++;}
  await go(5,'security/scanner.php');await check("!!document.querySelector('#qrScannerApp')&&!!document.querySelector('#scanPanel')",'QR scanner retained');
  await go(5,'security/security_dashboard.php');await until("!!document.querySelector('[data-security-home]')");
  for(const actor of [0,1,2,4,6,7,8]){const result=await ev(`fetch(${JSON.stringify(base+'api/security_dashboard.php?fixture_actor=')}+${actor}).then(r=>r.status)`);if(result!==(actor===0?401:403))throw Error('Security API role '+actor+' '+result);checks++;}
  if(errors.length)throw Error(errors.join('\n'));console.log('Passed '+checks+' Security dashboard/navigation/theme/responsive/refresh/permission/browser checks.');console.log('QA files: '+directory);
 }finally{socket?.close();chrome.kill();await sleep(500);}
})().catch(error=>{console.error(error);process.exitCode=1;});
