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

  if(process.argv.includes('--scanner-only')){
    await go(5,'security/scanner.php');await until("document.querySelectorAll('.vp-session').length>0");
    const sessions=(await post('list')).data.data.sessions,target=sessions.find(s=>s.plate==='UI-OWNER');
    if(!target)throw Error('Missing scanner fixture');
    await ev(`window.dispatchEvent(new CustomEvent('visitor-parking:select',{detail:{id:${target.id}}}))`);
    await until(`document.querySelector('[data-vp-workspace]').dataset.vpRequest==='${target.id}'&&document.querySelectorAll('.vp-session').length===1`);
    await check("document.querySelector('[data-vp-filters]').hidden&&!document.querySelector('[data-vp-show-all]').hidden",'Selected scan shows one parking session');
    const after=(await post('detail',{id:target.id})).data.data;
    if(after.arrival!==target.arrival||after.departure!==target.departure)throw Error('Selecting a QR session changed movement timestamps');checks++;
    await ev("document.querySelector('[data-vp-show-all]').click()");await until("!document.querySelector('[data-vp-workspace]').dataset.vpRequest&&!document.querySelector('[data-vp-filters]').hidden&&document.querySelectorAll('.vp-session').length>1");checks++;
    if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,scope:'Scanner session selection without automatic movement'}));return;
  }
  if(process.argv.includes('--assisted-only')){
    const direct=(action,values={})=>ev(`(async()=>{const d=new FormData();Object.entries(${JSON.stringify(values)}).forEach(([k,v])=>d.set(k,v));d.set('action',${JSON.stringify(action)});d.set('csrf_token',document.querySelector('input[name=csrf_token]').value);const r=await fetch(${JSON.stringify(base+'api/visitor_parking.php')},{method:'POST',body:d,cache:'no-store'});return {status:r.status,data:await r.json()};})()`);
    await go(4,'superadmin/service_requests.php?kind=visitor&tab=requests');
    await ev(`(async()=>{document.getElementById('staffAssistedVisitor').open=true;document.getElementById('needs_parking').click();const f=document.querySelector('#staffAssistedVisitor form');for(const [k,v]of Object.entries({sponsor_id:'2',visitor_name:'Staff Assisted Visitor',start_date:new Date().toLocaleDateString('en-CA'),details:'Staff prepared the family visitor request.',registration_authority:'Tenant requested registration at the management desk.',id_type:'National ID',vehicle_plate:'ASSIST 500',vehicle_type:'Car',vehicle_model:'Vios',vehicle_color:'Silver'}))f.elements[k].value=v;f.elements.parking_fee_ack.checked=true;const c=document.createElement('canvas');c.width=640;c.height=400;c.getContext('2d').fillRect(0,0,640,400);const b=await new Promise(r=>c.toBlob(r,'image/png')),d=new DataTransfer();d.items.add(new File([b],'fixture-id.png',{type:'image/png'}));f.elements.valid_id.files=d.files;window.parkingFeeNavigating=true;f.requestSubmit();})()`);
    await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&!!window.CelandineUi");
    const listed=await direct('list'),session=listed.data.data.sessions.find(r=>r.plate==='ASSIST 500');if(!session)throw Error(JSON.stringify(listed));
    if(!session.assisted||!session.needs_ack||session.arrival!==null)throw Error('Staff acknowledgment must not consent for a resident');checks++;
    const reviewed=(await direct('review_approval',{id:session.id})).data.data;
    const image=await ev(`(async()=>{const r=await fetch(${JSON.stringify(reviewed.visitor.document_url)});return r.status;})()`);if(image!==200)throw Error('Assisted ID not protected/available to authorized reviewer');checks++;
    const approved=await direct('approve_all',{id:session.id,identity_version:reviewed.visitor.identity_version,review_version:reviewed.review_version,slot_id:'0',include_extension:'0',approval_note:'Reviewed the assisted visitor identity.'});if(!approved.data.success)throw Error(JSON.stringify(approved));checks++;
    await go(5,'security/scanner.php');if((await post('checkin',{id:session.id})).status!==422)throw Error('Unconsented assisted vehicle entered');checks++;
    await go(1,'resident/parking.php?tab=requests');if((await post('detail',{id:session.id})).status!==422)throw Error('Another account accessed assisted parking');checks++;
    await go(2,'resident/parking.php?tab=requests');
    await ev(`(()=>{const form=[...document.querySelectorAll('input[value=parking_fee_ack]')].map(n=>n.form).find(f=>Number(f.elements.request_id.value)===${session.id});form.closest('details').open=true;form.elements.parking_fee_ack.checked=true;window.parkingFeeNavigating=true;form.requestSubmit();})()`);
    await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&!!window.CelandineUi");
    const consented=(await post('detail',{id:session.id})).data.data;if(consented.needs_ack)throw Error('Sponsor consent did not save');checks++;
    await go(5,'security/scanner.php');const entered=await post('checkin',{id:session.id});if(!entered.data.success||!entered.data.data.quote.grace_applied)throw Error(JSON.stringify(entered));checks++;
    await go(5,'security/scanner.php');await until(`document.querySelector('[data-vp-grace="${session.id}"]')`);await shot('assisted-security-grace');
    const exited=await post('checkout',{id:session.id});if(!exited.data.success||exited.data.data.fee_state!=='free'||exited.data.data.payment_id!==null)throw Error(JSON.stringify(exited));checks++;
    await go(6,'superadmin/parking.php?tab=slots');
    await ev(`(()=>{const f=document.querySelector('input[value=save_visitor_rate]').form;f.closest('details').open=true;f.elements.effective_from.value=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(Date.now()+7200000)).replace(' ','T');f.elements.reason.value='PMO-approved future configuration fixture.';f.elements.confirm_rate.checked=true;window.parkingFeeNavigating=true;f.requestSubmit();})()`);
    await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&document.body.textContent.includes('rate version scheduled')");
    const policy=await direct('rate_policy');if(policy.data.data.id!==2)throw Error('Future configuration immediately changed current rates');checks++;
    if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,scope:'Staff-assisted sponsor consent and versioned rate settings',screenshots:directory}));return;
  }
  await go(1,'resident/parking.php?tab=requests');await until("document.querySelectorAll('.vp-session').length>0");
  let list=await post('list');const owner=list.data.data.sessions.find(s=>s.plate==='UI-OWNER');if(!owner)throw Error('Missing owner fixture');
  await check("![...document.querySelectorAll('.vp-session')].some(n=>n.textContent.includes('UI-TENANT'))",'Owner cannot view tenant session');
  await check("![...document.querySelectorAll('.vp-session button')].some(n=>n.textContent.includes('Confirm Vehicle Entry')||n.textContent.includes('Confirm Vehicle Checkout'))",'Resident has no gate movement buttons');
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
  await check(`document.querySelector('[data-vp-session="${owner.id}"]').textContent.includes('FREE PARKING GRACE PERIOD')`,'Security entry starts live clock and free grace countdown');
  await ev(`document.querySelector('[data-vp-session="${owner.id}"] details').open=true;document.querySelector('[data-vp-session="${owner.id}"] details button').click()`);await until(`document.querySelector('[data-vp-session="${owner.id}"]').textContent.includes('Checked Out')`);checks++;
  let detail=(await post('detail',{id:owner.id})).data.data;const ownerBill=detail.payment_id;
  if(detail.quote.amount!=='0.00'||detail.payment_id!==null||detail.fee_state!=='free')throw Error('Grace checkout must not create a payable charge');checks++;
  if((await post('checkout',{id:owner.id})).data.data.payment_id!==ownerBill)throw Error('Repeat checkout changed bill');checks++;
  let result=await post('checkin',{id:tenant.id});if(!result.data.success)throw Error(JSON.stringify(result));checks++;
  result=await post('checkout',{id:tenant.id});if(!result.data.success)throw Error(JSON.stringify(result));const tenantBill=result.data.data.payment_id;checks++;
  await go(2,'resident/payments.php');await check("!document.body.textContent.includes('UI-TENANT')",'Free tenant checkout creates no payable parking invoice');
  await go(1,'resident/payments.php');await check("document.body.textContent.includes('UI-PAID')&&!document.body.textContent.includes('UI-TENANT')",'Existing paid parking bill remains in owner billing without tenant details');
  await go(1,'resident/visitors.php');await ev("document.getElementById('needs_parking').click()");
  await check("!document.querySelector('[name=expected_arrival]')&&!document.querySelector('[name=expected_departure]')",'Registration requires no arrival or departure estimates');
  await check("document.querySelector('.vp-rate-card').textContent.includes('15 minutes')&&document.querySelector('.vp-rate-card').textContent.includes('50.00')&&document.querySelector('.vp-rate-card').textContent.includes('25.00')&&document.querySelector('.vp-rate-card').textContent.includes('300.00')",'Actual policy rates are visible before registration');
  await check("document.querySelector('[name=parking_fee_ack]').required&&!document.querySelector('[data-vp-estimate]')",'Policy acknowledgment is required without inventing a final fee estimate');
  await ev("document.getElementById('no_visitor_vehicle').click()");await check("document.getElementById('visitorParkingFields').hidden&&document.getElementById('visitorParkingFields').disabled",'No vehicle disables parking-only validation');await ev("document.getElementById('needs_parking').click()");await check("!document.getElementById('visitorParkingFields').hidden&&!document.getElementById('visitorParkingFields').disabled",'Yes vehicle restores mandatory parking fields');
  await check("[...document.querySelectorAll('.service-vehicle-options label')].every(n=>getComputedStyle(n).display!=='none'&&n.getBoundingClientRect().height>0)",'Yes and No vehicle labels are visible');
  const receipt=manifest.find(m=>m.path.startsWith('resident/payment_receipt.php'));
  const pdf=await ev(`(async()=>{const r=await fetch(${JSON.stringify(base+receipt.path)});const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,type:r.headers.get('content-type'),data:btoa(Array.from(b,x=>String.fromCharCode(x)).join(''))};})()`);
  if(pdf.status!==200||!pdf.type.includes('application/pdf'))throw Error('Paid parking PDF unavailable');checks++;fs.writeFileSync(path.join(directory,'visitor-parking-receipt.pdf'),Buffer.from(pdf.data,'base64'));
  await shot('registration-dark');await ev("CelandineTheme.set('light')");await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true});await sleep(300);await shot('registration-light-mobile');
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
