/* Only use the disposable test_ui_redesign.php --preview fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='treasurer/treasurer_dashboard.php'))throw Error('Missing treasurer fixture');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-treasurer-dashboard-'));
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
  await send('Network.setBlockedURLs',{urls:['*fonts.googleapis.com*','*fonts.gstatic.com*','*www.google.com/maps/*']});
  const route='treasurer/treasurer_dashboard.php';
  for(const width of [1366,1024,768,390,320]) {
   await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
   await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
   await go(7,route);
   for(const theme of ['dark','light']) {
    await ev(`CelandineTheme.set('${theme}')`);
    await check("!!document.querySelector('[data-treasurer-dashboard]')",'financial dashboard renders '+width+' '+theme);
    await check('document.documentElement.scrollWidth<=innerWidth+1','no page overflow '+width+' '+theme);
    await check("document.querySelectorAll('[data-treasurer-metric]').length===4&&document.querySelectorAll('.treasurer-trend li').length===6&&document.querySelectorAll('.treasurer-aging li').length===4",'complete financial panels '+width+' '+theme);
    await check("[...document.querySelectorAll('[data-treasurer-metric]')].every(el=>/\\u20b1[\\d,]+\\.\\d{2}/.test(el.textContent))",'money displayed with cents '+width+' '+theme);
    await check("document.querySelector('[data-digital-id-toggle]').nextElementSibling.matches('[data-theme-toggle]')&&!!document.querySelector('[data-profile-digital-id]')",'existing ID and profile controls preserved '+width+' '+theme);
    await check("[...document.querySelectorAll('.treasurer-actions .system-icon-svg')].every(e=>e.getBoundingClientRect().width>0)",'quick-action icons visible '+width+' '+theme);
    await check("document.querySelector('.treasurer-table-scroll').getBoundingClientRect().right<=innerWidth+1",'recent payments scroll within panel '+width+' '+theme);
    await check("getComputedStyle(document.querySelector('.treasurer-panel')).backgroundColor===(document.documentElement.dataset.theme==='light'?'rgb(255, 255, 255)':'rgb(26, 30, 43)')",'original palette retained '+width+' '+theme);
    if(width===1366||width===390)await shot('treasurer-'+width+'-'+theme);
   }
   if(width===390) {
    await ev("document.querySelector('#menuToggle').click()");
    await check("document.querySelector('#sidebar').classList.contains('open')",'mobile sidebar opens');
    await ev("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
    await check("!document.querySelector('#sidebar').classList.contains('open')",'mobile sidebar Escape closes');
    await ev("document.querySelector('[data-digital-id-toggle]').click()");await until("!document.querySelector('.digital-id-qr-frame').hidden");
    await check("document.querySelector('.digital-id-role span').textContent==='Treasurer'",'treasurer Digital ID remains functional');
    await ev("document.querySelector('.digital-id-close').click()");await until("!document.getElementById('digitalIdDialog').open");
   }
  }
  await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
  await go(7,route);
  await check("document.querySelectorAll('.treasurer-follow-ups li').length===3&&document.querySelectorAll('.treasurer-due-state.past-due').length===2",'past-due and upcoming follow-ups render with distinct labels');
  await check("document.querySelectorAll('.treasurer-table tbody tr').length===4",'completed payments show actual payer and method rows');
  const followUp=await ev("document.querySelector('.treasurer-follow-ups a').getAttribute('href')");
  await ev("document.querySelector('.treasurer-follow-ups a').click()");await until("location.pathname.endsWith('/superadmin/unitpayments.php')&&document.readyState==='complete'");
  await check("new URL(location.href).searchParams.get('search')===new URL("+JSON.stringify(followUp)+",location.href).searchParams.get('search')",'follow-up opens existing billing reference search');
  await go(7,route);
  const balance=await ev("document.querySelector('[data-treasurer-metric=outstanding_cents]').textContent");
  await ev("document.querySelector('#treasurerMonth').value='2000-01';document.querySelector('.treasurer-period').requestSubmit()");
  await until("new URL(location.href).searchParams.get('month')==='2000-01'&&document.readyState==='complete'&&document.querySelector('#treasurerMonth').value==='2000-01'");
  await check("document.querySelector('#treasurerOverviewTitle').nextElementSibling.textContent.includes('January 2000')&&document.querySelector('[data-treasurer-metric=collected_cents]').textContent==='\\u20b10.00'",'GET month filter changes dated collections');
  await check(`document.querySelector('[data-treasurer-metric=outstanding_cents]').textContent===${JSON.stringify(balance)}`,'month filter keeps current open balance clearly separate');
  await ev("document.querySelector('.treasurer-period>a').click()");await until("document.readyState==='complete'&&document.querySelector('#treasurerMonth')?.value==='2000-01'");
  await check("new URL(location.href).searchParams.get('month')==='2000-01'",'refresh keeps selected month');
  await go(7,route+'?month=invalid');
  await check("document.querySelector('.treasurer-home [role=alert]').textContent.includes('valid month')&&!!document.querySelector('[data-treasurer-metric]')",'invalid filter gives readable feedback and safe default');
  await go(7,route);await ev("document.querySelector('.treasurer-actions a').click()");await until("location.pathname.endsWith('/superadmin/unitpayments.php')&&document.readyState==='complete'");
  await check("document.body.textContent.includes('Billing & Payments')&&!!document.querySelector('.billing-tabs')",'existing billing navigation works');
  await go(7,route);await ev("document.querySelector('.treasurer-actions a[href*=generate_bills]').click()");await until("location.pathname.endsWith('/superadmin/generate_bills.php')&&document.readyState==='complete'");
  await check("!!document.querySelector('form')",'existing generate-bills navigation works');
  await go(7,route);await ev("document.querySelector('.treasurer-actions a[href*=history]').click()");await until("location.pathname.endsWith('/superadmin/unitpayments.php')&&document.readyState==='complete'");
  await check("new URL(location.href).searchParams.get('tab')==='history'",'payment-history shortcut keeps existing tab');
  await go(1,route);await check("!document.querySelector('[data-treasurer-dashboard]')&&location.pathname.endsWith('/resident/dashboard.php')",'resident cannot open treasurer dashboard');
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
