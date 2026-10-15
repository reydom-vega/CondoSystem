/* Only against test_ui_redesign.php --preview --management-only. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Use the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='admin/admin_dashboard.php')||!manifest.some(entry=>entry.path==='superadmin/admin_dashboard.php'))throw Error('Management fixture unavailable.');
 const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-management-dashboard-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[],badRequests=[];
 try{
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab=>tab.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let number=0;const pending=new Map();socket.onmessage=event=>{const message=JSON.parse(event.data),call=pending.get(message.id);if(call){pending.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);if(message.method==='Network.responseReceived'&&message.params.response.url.includes('/api/')&&message.params.response.status>=500)badRequests.push(message.params.response.url);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++number,timer=setTimeout(()=>{pending.delete(id);reject(Error('Timeout '+method));},30000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const until=async expression=>{for(let i=0;i<150;i++){if(await ev(expression))return;await sleep(100);}throw Error('Timed out '+expression);};
  const shot=async name=>{const metrics=await send('Page.getLayoutMetrics');const result=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:metrics.cssContentSize.width,height:metrics.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
  const check=async(expression,label)=>{if(!await ev(expression)){await shot('failure');throw Error(label);}checks++;};
  const go=async(actor,route)=>{await ev('window.managementNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.managementNavigating&&document.readyState==='complete'&&!!document.querySelector('[data-management-dashboard]')&&!!window.CelandineTheme");await sleep(250);};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  await send('Page.addScriptToEvaluateOnNewDocument',{source:"(()=>{const original=setTimeout;window.setTimeout=(callback,delay,...args)=>{if(delay===60000)window.managementMinute=callback;return original(callback,delay,...args);};})()"});
  for(const [actor,role,route]of[[4,'Admin','admin/admin_dashboard.php'],[6,'Super Admin','superadmin/admin_dashboard.php']]){
   for(const theme of ['dark','light'])for(const width of [1366,1024,768,390,320]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:950,deviceScaleFactor:1,mobile:false});await go(actor,route);await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);await sleep(120);
    await check(`document.querySelector('.dash-title').textContent===${JSON.stringify(role+' Dashboard')}`,'Correct role title '+role+'/'+theme+'/'+width);
    await check("document.documentElement.scrollWidth<=innerWidth+1",'No horizontal overflow '+role+'/'+theme+'/'+width);
    await check("document.querySelectorAll('[data-management-metric]').length===4&&[...document.querySelectorAll('[data-management-metric]>strong')].every(n=>/^\\d[\\d,]*$/.test(n.textContent))",'Every operational KPI has a real number '+role+'/'+theme+'/'+width);
    await check(`!!document.querySelector('[data-management-finance]')===${actor===6}&&!!document.querySelector('[data-management-queue=accounts]')===${actor===6}&&!!document.querySelector('[data-management-queue=staff_reports]')===${actor===6}`,'Privileged panels follow permissions '+role+'/'+theme+'/'+width);
    await check(`document.querySelector('.celandine-brand-emblem').src.endsWith(${JSON.stringify(theme==='light'?'celandineorange.svg':'celandine.svg')})`,'Original theme logo '+role+'/'+theme+'/'+width);
    await check(`getComputedStyle(document.querySelector('.management-panel')).backgroundColor===${JSON.stringify(theme==='light'?'rgb(255, 255, 255)':'rgb(26, 30, 43)')}`,'Original card palette '+role+'/'+theme+'/'+width);
    await check("!!document.querySelector('[data-digital-id-toggle]')&&!!document.querySelector('[data-theme-toggle]')&&!!document.querySelector('#profileToggle')",'Shared navbar controls retained '+role+'/'+theme+'/'+width);
    await check("(()=>{const queue=document.getElementById('managementReviewQueue').getBoundingClientRect(),maintenance=document.querySelector('[aria-labelledby=managementMaintenanceTitle]').getBoundingClientRect();return Math.abs(maintenance.top-queue.bottom-22)<2;})()",'Maintenance follows review queue without a row-height gap '+role+'/'+theme+'/'+width);
    if(width===1366||width===390)await shot(role.replace(' ','-')+'-'+theme+'-'+width);
    if(width===1366){
     // Reproduce the reported short review queue without changing fixture records.
     await ev("document.querySelector('#managementReviewQueue .management-queue-list').querySelectorAll('.management-queue').forEach((row,index)=>{if(index)row.remove();})");
     await check("(()=>{const queue=document.getElementById('managementReviewQueue').getBoundingClientRect(),maintenance=document.querySelector('[aria-labelledby=managementMaintenanceTitle]').getBoundingClientRect(),property=document.querySelector('[aria-labelledby=managementPropertyTitle]').getBoundingClientRect();return property.bottom>queue.bottom&&Math.abs(maintenance.top-queue.bottom-22)<2;})()",'Short review queue stacks independently of taller property card '+role+'/'+theme);
     await shot(role.replace(' ','-')+'-sparse-'+theme);
    }
   }
   await send('Emulation.setDeviceMetricsOverride',{width:1366,height:950,deviceScaleFactor:1,mobile:false});await go(actor,route);
   const result=await ev("fetch(document.querySelector('[data-management-dashboard]').dataset.endpoint,{cache:'no-store'}).then(async response=>({status:response.status,cache:response.headers.get('cache-control'),body:await response.json()}))");
   if(result.status!==200||!result.cache.includes('no-store')||!result.body.success||typeof result.body.pending_bookings!=='number'||typeof result.body.unresolved_violations!=='number')throw Error('Protected dashboard API unavailable');checks++;
   await check("getComputedStyle(document.querySelector('[data-management-refresh]')).color==='rgb(255, 255, 255)'",'Refresh keeps the existing primary button foreground '+role);
   const method=await ev("fetch(document.querySelector('[data-management-dashboard]').dataset.endpoint,{method:'POST'}).then(r=>({status:r.status,allow:r.headers.get('allow')}))");if(method.status!==405||method.allow!=='GET')throw Error('Read-only API accepts a write request');checks++;
   if(actor===4&&(result.body.data.finance||result.body.data.failed_notifications||result.body.html.includes('data-management-finance')))throw Error('Admin finance disclosure');checks++;
   await check("document.querySelector('[data-management-metric=messages]>strong').textContent==='2'&&document.querySelector('[data-management-metric=messages]>small').textContent.includes('4 unread')",'Unread conversations distinct from unread messages '+role);
   await check("document.querySelector('#managementMaintenanceTitle').closest('section').querySelector('li').textContent.includes('Electrical')",'Urgent maintenance first '+role);
   await check("document.querySelector('#managementScheduleTitle').closest('section').querySelectorAll('li').length===2",'Approved and confirmed upcoming bookings '+role);
   await check("!document.querySelector('[data-management-content]').textContent.includes('Private dashboard fixture')&&!document.querySelector('[data-management-content]').textContent.includes('Private filed notes')",'Private narrative bodies absent '+role);
   await ev("window.managementRequests=0;window.managementFetch=window.fetch;window.fetch=async(...args)=>{if(String(args[0]).includes('/api/admin_dashboard.php')){managementRequests++;await new Promise(resolve=>setTimeout(resolve,250));}return managementFetch(...args);};document.querySelector('[data-management-refresh]').click();document.querySelector('[data-management-refresh]').click()");
   await until("document.querySelector('[data-management-feedback]').textContent==='Dashboard updated.'&&!document.querySelector('[data-management-refresh]').disabled");
   await check("managementRequests===1&&document.querySelector('[data-management-content]').getAttribute('aria-busy')==='false'",'Refresh requests do not overlap '+role);
   await ev("managementRequests=0;document.querySelector('.management-queue').focus();window.managementMinute()");await sleep(350);
   await check("managementRequests===0&&document.activeElement.classList.contains('management-queue')",'Background refresh preserves focused review controls '+role);
   await ev("document.activeElement.blur();window.managementMinute()");await until("managementRequests===1&&document.querySelector('[data-management-content]').getAttribute('aria-busy')==='false'");checks++;
   await ev("managementRequests=0;Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));window.managementMinute()");await sleep(350);
   await check("managementRequests===0",'Background refresh pauses while hidden '+role);await ev("delete document.hidden;document.dispatchEvent(new Event('visibilitychange'))");
   await ev("window.managementSaved=document.querySelector('[data-management-content]').innerHTML;window.fetch=async(...args)=>String(args[0]).includes('/api/admin_dashboard.php')?new Response(JSON.stringify({error:'Fixture failure'}),{status:503,headers:{'content-type':'application/json'}}):managementFetch(...args);document.querySelector('[data-management-refresh]').click()");
   await until("document.querySelector('[data-management-feedback]').dataset.error==='true'&&!document.querySelector('[data-management-refresh]').disabled");
   await check("document.querySelector('[data-management-content]').innerHTML===managementSaved",'Failed refresh retains the last snapshot '+role);
   await ev("window.fetch=managementFetch;document.querySelector('[data-management-refresh]').click()");await until("document.querySelector('[data-management-feedback]').textContent==='Dashboard updated.'");checks++;
   await send('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});await ev("document.querySelector('[data-management-refresh]').click()");await until("document.querySelector('[data-management-feedback]').textContent.includes('offline')");
   await check("!document.querySelector('[data-management-refresh]').disabled&&!!document.querySelector('[data-management-metric=reviews]')",'Offline feedback preserves controls and numbers '+role);
   await send('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});
   await ev("document.getElementById('profileToggle').click()");await check("document.getElementById('profileMenu').classList.contains('open')&&!!document.querySelector('[data-profile-theme]')",'Profile dropdown remains functional '+role);
   await ev("document.getElementById('profileToggle').click();document.querySelector('[data-digital-id-toggle]').click()");await until("document.getElementById('digitalIdDialog').open&&!document.querySelector('.digital-id-qr-frame').hidden");
   await check(`document.querySelector('.digital-id-role span').textContent===${JSON.stringify(role)}`,'Existing Digital ID has the correct role '+role);await ev("document.querySelector('.digital-id-close').click()");
   await send('Emulation.setDeviceMetricsOverride',{width:390,height:950,deviceScaleFactor:1,mobile:false});await ev("document.getElementById('menuToggle').click()");await check("document.getElementById('sidebar').classList.contains('open')",'Mobile sidebar opens '+role);await ev("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");await check("!document.getElementById('sidebar').classList.contains('open')",'Mobile sidebar closes '+role);
   for(const link of await ev("[...document.querySelectorAll('[data-management-content] a,.management-shortcuts a')].map(a=>a.href).filter(h=>!h.includes('#managementReviewQueue'))")){
    const relative=decodeURIComponent(new URL(link).pathname.replace('/CondoSystem3/',''));if(!fs.existsSync(path.join(__dirname,'..',relative)))throw Error('Broken dashboard destination '+relative);
   }checks++;
   if(actor===6){await check("document.querySelector('[data-management-finance-value=collected]').textContent==='\\u20b12,424.99'&&document.querySelector('[data-management-finance-value=past_due]').textContent==='\\u20b1150.25'",'Finance uses payment date and actual unpaid due date');await check("!!document.querySelector('.management-delivery-notice')",'Super Admin sees failed delivery follow-up');}
  }
  // API capability checks do not redirect through unrelated module pages.
  for(const [actor,status]of[[0,401],[1,403],[2,403],[5,403],[7,403],[8,403]]){
   const result=await ev(`fetch(${JSON.stringify(base+'api/admin_dashboard.php?fixture_actor='+actor)}).then(async r=>({status:r.status,body:await r.json()}))`);if(result.status!==status||result.body.data||result.body.html)throw Error('API role boundary '+actor);checks++;
  }
  if(errors.length||badRequests.length)throw Error(JSON.stringify({errors,badRequests}));
  console.log('Passed '+checks+' management dashboard browser, permission, responsive and refresh checks. Screenshots: '+directory);
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
