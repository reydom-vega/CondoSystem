/* Only use the disposable test_ui_redesign.php --preview fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='resident/parking.php'))throw Error('Missing profile fixture');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-visitor-parking-browser-'));
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
  const go=async(actor,route='resident/parking.php')=>{await ev('window.parkingFeeNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&!!window.CelandineUi");await sleep(350);};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  const post=(action,values={})=>ev(`(async()=>{const w=document.querySelector('[data-vp-workspace]'),d=new FormData();Object.entries(${JSON.stringify(values)}).forEach(([k,v])=>d.set(k,v));d.set('action',${JSON.stringify(action)});d.set('csrf_token',w.dataset.csrf);const r=await fetch(w.dataset.endpoint,{method:'POST',body:d,cache:'no-store'});return {status:r.status,data:await r.json(),cache:r.headers.get('cache-control')};})()`);
  await go(1,'resident/parking.php?tab=requests');await until("document.querySelectorAll('.vp-session').length>0");
  let list=await post('list');const owner=list.data.data.sessions.find(s=>s.plate==='UI-OWNER');if(!owner)throw Error('Missing owner fixture');
  await check("![...document.querySelectorAll('.vp-session')].some(n=>n.textContent.includes('UI-TENANT'))",'Owner cannot view tenant session');
  await check("![...document.querySelectorAll('.vp-session button')].some(n=>n.textContent.includes('Actual Vehicle Entry')||n.textContent.includes('Confirm Checkout'))",'Resident has no gate movement buttons');
  if(!list.cache.includes('no-store'))throw Error('Parking responses can be cached');checks++;
  for(const actor of [1,2,4,6,5]){
    const route=actor<=2?'resident/parking.php?tab=requests':actor===5?'security/scanner.php':'superadmin/parking.php?tab=extensions';
    await go(actor,route);await until("document.querySelectorAll('.vp-session').length>0");
    for(const width of [1366,768,390,320])for(const theme of ['dark','light']){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<700});await ev('CelandineTheme.set('+JSON.stringify(theme)+')');
      await check('document.documentElement.scrollWidth<=innerWidth+1','Parking fits '+actor+' '+width+' '+theme);
      if(actor===4&&width===1366){await ev("document.querySelector('[data-vp-only-extensions] .vp-extension-table button')?.click()");await shot('extensions-'+theme);}
    }
  }
  await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
  await go(2,'resident/parking.php?tab=requests');list=await post('list');const tenant=list.data.data.sessions.find(s=>s.plate==='UI-TENANT'),extension=tenant.extensions.find(e=>e.status==='pending');
  if(!extension||tenant.arrival!==null)throw Error('Planned extended stay incorrectly active');checks++;
  if((await post('detail',{id:owner.id})).status!==422)throw Error('Cross-account session access');checks++;
  if((await post('checkin',{id:tenant.id})).status!==422)throw Error('Resident started vehicle clock');checks++;
  if((await post('decide',{id:extension.id,decision:'approved'})).status!==422)throw Error('Resident approved own extension');checks++;
  await go(4,'superadmin/parking.php?tab=extensions');await until("document.querySelector('[data-vp-only-extensions] .vp-extension-table button')");
  if((await post('decide',{id:extension.id,decision:'rejected'})).status!==422)throw Error('Rejection accepted without reason');checks++;
  if((await post('checkin',{id:tenant.id})).status!==422)throw Error('Admin started vehicle clock');checks++;
  await ev("document.querySelector('[data-vp-only-extensions] .vp-extension-table button').click()");await until("[...document.querySelectorAll('[data-vp-only-extensions] .vp-session')].some(n=>!n.hidden)");
  await check("document.querySelector('[data-vp-only-extensions] .vp-session:not([hidden])').textContent.includes('UI-TENANT')",'Extension table opens full session and previous extension details');
  await check("[...document.querySelectorAll('[data-vp-only-extensions] .vp-session:not([hidden]) form input,[data-vp-only-extensions] .vp-session:not([hidden]) form textarea')].every(n=>n.getBoundingClientRect().height>=40)",'Management decision date and reason fields are visible');
  await ev("var form=document.querySelector('[data-vp-only-extensions] .vp-session:not([hidden]) form');form.elements.decision_note.value='Approved family visit';form.requestSubmit(form.querySelector('[value=approved]'))");
  await until("document.querySelector('[data-vp-only-extensions] [data-vp-feedback]').textContent.includes('decision saved')");checks++;
  await go(5,'security/scanner.php');await until("document.querySelectorAll('.vp-session').length>0");
  if((await post('decide',{id:extension.id,decision:'approved'})).status!==422)throw Error('Security approved extension');checks++;
  await ev(`document.querySelector('[data-vp-session="${owner.id}"] button').click()`);await until(`document.querySelector('[data-vp-session="${owner.id}"] [data-vp-live]')`);
  await check(`document.querySelector('[data-vp-session="${owner.id}"]').textContent.includes('Hourly rate')`,'Security entry starts live snapshot clock');
  await ev(`document.querySelector('[data-vp-session="${owner.id}"] details').open=true;document.querySelector('[data-vp-session="${owner.id}"] details button').click()`);await until(`document.querySelector('[data-vp-session="${owner.id}"]').textContent.includes('Checked Out')`);checks++;
  let detail=(await post('detail',{id:owner.id})).data.data;const ownerBill=detail.payment_id;
  if(detail.quote.amount!=='50.00')throw Error('First started hour fee incorrect');checks++;
  if((await post('checkout',{id:owner.id})).data.data.payment_id!==ownerBill)throw Error('Repeat checkout changed bill');checks++;
  let result=await post('checkin',{id:tenant.id});if(!result.data.success)throw Error(JSON.stringify(result));checks++;
  result=await post('checkout',{id:tenant.id});if(!result.data.success)throw Error(JSON.stringify(result));const tenantBill=result.data.data.payment_id;checks++;
  await go(2,'resident/payments.php');await check(`document.body.textContent.includes('UI-TENANT')&&document.body.textContent.includes('Visitor Parking Fee')`,'Tenant receives own checkout fee in existing payments');
  await go(1,'resident/payments.php');await check("document.body.textContent.includes('UI-OWNER')&&!document.body.textContent.includes('UI-TENANT')",'Owner receives own fee and no tenant vehicle details');
  const foreignStatus=await ev(`(async()=>{const r=await fetch('payment_receipt.php?id=${tenantBill}');return r.status;})()`);if(foreignStatus!==404)throw Error('Foreign parking receipt accessible');checks++;
  await go(1,'resident/visitors.php');await ev("document.querySelector('[name=needs_parking]').checked=true;document.querySelector('[name=needs_parking]').dispatchEvent(new Event('change',{bubbles:true}));var a=document.querySelector('[name=expected_arrival]'),d=document.querySelector('[name=expected_departure]');a.value='2027-01-01T08:00';d.value='2027-01-02T08:00';d.dispatchEvent(new Event('input',{bubbles:true}))");
  await check("document.querySelector('[data-vp-estimate]').textContent.includes('1,200.00')&&document.querySelector('[data-vp-extension-reason]').hidden&&getComputedStyle(document.querySelector('[data-vp-extension-reason]')).display==='none'",'Exactly 24-hour estimate needs no extension');
  await ev("document.querySelector('[name=expected_departure]').value='2027-01-02T08:01';document.querySelector('[name=expected_departure]').dispatchEvent(new Event('input',{bubbles:true}))");
  await check("document.querySelector('[data-vp-estimate]').textContent.includes('1,250.00')&&!document.querySelector('[data-vp-extension-reason]').hidden&&document.querySelector('[name=extension_reason]').required",'Beyond 24 hours requires reason and rounds started hour');
  await ev("document.getElementById('no_visitor_vehicle').click()");await check("document.getElementById('visitorParkingFields').hidden&&document.getElementById('visitorParkingFields').disabled",'No vehicle disables parking-only validation');await ev("document.getElementById('needs_parking').click()");await check("!document.getElementById('visitorParkingFields').hidden&&!document.getElementById('visitorParkingFields').disabled",'Yes vehicle restores mandatory parking fields');
  await check("[...document.querySelectorAll('.service-vehicle-options label')].every(n=>getComputedStyle(n).display!=='none'&&n.getBoundingClientRect().height>0)",'Yes and No vehicle labels are visible');
  const receipt=manifest.find(m=>m.path.startsWith('resident/payment_receipt.php'));
  const pdf=await ev(`(async()=>{const r=await fetch(${JSON.stringify(base+receipt.path)});const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,type:r.headers.get('content-type'),data:btoa(Array.from(b,x=>String.fromCharCode(x)).join(''))};})()`);
  if(pdf.status!==200||!pdf.type.includes('application/pdf'))throw Error('Paid parking PDF unavailable');checks++;fs.writeFileSync(path.join(directory,'visitor-parking-receipt.pdf'),Buffer.from(pdf.data,'base64'));
  await shot('registration-dark');await ev("CelandineTheme.set('light')");await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true});await sleep(300);await shot('registration-light-mobile');
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
