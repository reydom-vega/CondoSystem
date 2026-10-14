/* Use only the disposable test_ui_redesign.php --preview HTTP fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 await(await fetch(base+'__manifest')).json();
 const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const p=server.address().port;server.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-digital-id-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;
 try {
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');
  socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let seq=0;const calls=new Map(),errors=[],badResources=[];
  socket.onmessage=event=>{const message=JSON.parse(event.data),call=calls.get(message.id);if(call){calls.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);if(message.method==='Network.responseReceived'&&message.params.response.status>=400&&message.params.response.url.startsWith(base)&&!message.params.response.url.includes('api/digital_id.php')&&!(message.params.response.status===401&&message.params.response.url.includes('api/notifications.php')))badResources.push(message.params.response.url.split('#')[0]);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout '+method));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const until=async(expression,timeout=45000)=>{const end=Date.now()+timeout;while(Date.now()<end){if(await ev(expression))return;await sleep(100);}throw Error('Timed out: '+expression+' '+await ev("document.querySelector('.digital-id-feedback')?.textContent||document.body.innerText.slice(0,500)"));};
  const check=async(expression,label)=>{if(!await ev('(async()=>('+expression+'))()')){console.log(await ev("[...document.querySelectorAll('.dash-header-right button')].map(button=>({class:button.className,label:button.getAttribute('aria-label')}))"));await shot('failure');throw Error(label);}checks++;};
  const go=async(actor,route)=>{await ev('window.idNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.idNavigating && document.readyState==='complete' && !!window.CelandineUi",45000);};
  const open=async()=>{await ev("document.querySelector('[data-digital-id-toggle]').click()");await until("document.querySelector('.digital-id-qr-frame')?.hidden===false");};
  const close=async()=>{await ev("document.querySelector('.digital-id-close').click()");await until("!document.getElementById('digitalIdDialog').open && document.body.style.overflow!=='hidden'");};
  const shot=async name=>{const result=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  await send('Page.addScriptToEvaluateOnNewDocument',{source:`
   window.idRequests={issue:0,revoke:0,active:0,maxActive:0};
   const originalFetch=window.fetch;
   window.fetch=async function(input,options){
    if(!String(input).includes('/api/digital_id.php'))return originalFetch.apply(this,arguments);
    const action=JSON.parse(options.body).action;
    const counters=window.idRequests;counters[action]++;counters.active++;counters.maxActive=Math.max(counters.maxActive,counters.active);
    try {
     if(window.idDelay)await new Promise(resolve=>setTimeout(resolve,window.idDelay));
     if(window.idFailNext){window.idFailNext=false;throw new TypeError('Injected offline response');}
     const response=await originalFetch.apply(this,arguments);
     if(response.ok&&action==='issue')window.lastIssuedId=await response.clone().json();
     return response;
    }finally{counters.active--;}
   };`});
  const roles=[[1,'resident/dashboard.php','Unit Owner'],[2,'resident/dashboard.php','Tenant'],[4,'admin/admin_dashboard.php','Admin'],[5,'security/security_dashboard.php','Security Personnel'],[6,'superadmin/admin_dashboard.php','Super Admin'],[7,'treasurer/treasurer_dashboard.php','Treasurer'],[8,'maintenance/maintenance_dashboard.php','Maintenance']];
  if (process.argv.includes('--profile-only')) {
   for (const mobile of [false,true]) {
    await send('Emulation.setDeviceMetricsOverride',{width:mobile?390:1366,height:mobile?844:1000,deviceScaleFactor:1,mobile});
    for (const [actor,route,role] of roles) {
     await go(actor,route);
     await check("window.idRequests.issue===0 && document.querySelectorAll('#digitalIdDialog').length===1",'One idle shared ID modal: '+role);
     const expected=['Edit Profile','My QR ID','Light/Dark Mode','Logout'];
     await check("JSON.stringify([...document.querySelectorAll('.profile-dropdown .profile-dropdown-item')].map(item=>item.textContent.trim()))==="+JSON.stringify(JSON.stringify(expected)), 'Profile menu order: '+role);
     await check("(()=>{const id=document.querySelector('.dash-header-right>[data-digital-id-toggle]');return id.nextElementSibling.matches('[data-theme-toggle]')&&id.nextElementSibling.nextElementSibling.matches('.notification-menu')&&id.nextElementSibling.nextElementSibling.nextElementSibling.matches('.profile-menu');})()",'Navbar order preserved: '+role);
     for (const theme of ['dark','light']) {
      await ev('CelandineTheme.set('+JSON.stringify(theme)+');document.getElementById("profileToggle").click()');await sleep(300);
      await check("document.querySelector('[data-profile-theme]').getAttribute('aria-checked')==="+JSON.stringify(String(theme==='light')), 'Switch reflects current theme');
      await until("getComputedStyle(document.querySelector('.profile-dropdown')).visibility==='visible'");
      await check("(()=>{const d=document.querySelector('.profile-dropdown'),r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&d.scrollWidth<=d.clientWidth+1;})()",'Dropdown fits viewport');
      await ev("document.querySelector('[data-profile-theme]').click()");
      await check("document.querySelector('#profileMenu').classList.contains('open')&&document.querySelector('#profileToggle').getAttribute('aria-expanded')==='true'&&document.querySelector('[data-profile-theme]').getAttribute('aria-checked')==="+JSON.stringify(String(theme!=='light'))+"&&localStorage.getItem('condo-theme')==="+JSON.stringify(theme==='light'?'dark':'light'), 'Dropdown theme switch persists and keeps menu open');
      await ev("document.querySelector('[data-theme-toggle]').click();document.getElementById('profileToggle').click()");
      await check("document.documentElement.dataset.theme==="+JSON.stringify(theme)+"&&document.querySelector('[data-profile-theme]').getAttribute('aria-checked')==="+JSON.stringify(String(theme==='light')), 'Navbar and menu stay synchronized');
      await sleep(300);
      await check("(()=>{const button=document.getElementById('profileToggle').getBoundingClientRect(),dropdown=document.querySelector('.profile-dropdown').getBoundingClientRect();return dropdown.top>=button.bottom+8;})()",'Dropdown opens below its profile button');
      if ([1,2,6].includes(actor)) await shot('profile-'+actor+'-'+(mobile?'mobile':'desktop')+'-'+theme);
      const issued=await ev('window.idRequests.issue');
      await ev("document.querySelector('[data-profile-digital-id]').click()");await until("document.querySelector('.digital-id-qr-frame').hidden===false");
      await check('window.idRequests.issue==='+String(issued+1)+"&&window.idRequests.maxActive===1&&document.querySelector('.digital-id-role span').textContent==="+JSON.stringify(role)+"&&!document.querySelector('#profileMenu').classList.contains('open')",'Menu opens same secure QR workflow with correct role');
      await close();
      await check("document.activeElement.id==='profileToggle'&&!document.querySelector('.digital-id-qr').childElementCount",'Menu QR close restores profile focus and clears credential');
     }
     await ev('window.idNavigating=true');await send('Page.reload');await until("!window.idNavigating&&document.readyState==='complete'&&!!document.querySelector('[data-profile-theme]')");
     await check("document.documentElement.dataset.theme==='light'&&document.querySelector('[data-profile-theme]').getAttribute('aria-checked')==='true'&&window.idRequests.issue===0",'Saved theme restored without issuing a QR');
    }
   }
   // Inspect every actual role/page, including shared management headers.
   const manifest=await(await fetch(base+'__manifest')).json();
   for (const entry of manifest) {
    if (!entry.actor||entry.actor===3) continue;
    await go(entry.actor,entry.path);
    if (!await ev("!!document.querySelector('.profile-dropdown')")) continue;
    await check("document.querySelectorAll('.profile-dropdown [data-profile-digital-id]').length===1&&document.querySelectorAll('.profile-dropdown [data-profile-theme]').length===1&&document.querySelector('.profile-dropdown .profile-dropdown-item:last-child').href.includes('logout.php')",'Shared controls across '+entry.path+' / '+entry.actor);
    await check("document.querySelector('.profile-dropdown .profile-dropdown-item:first-of-type').getAttribute('href').endsWith('edit_profile.php')",'Original Edit Profile link retained: '+entry.path);
   }
   for (const actor of [1,2]) {
    await go(actor,'resident/dashboard.php');await ev("document.getElementById('profileToggle').click();document.querySelector('.profile-dropdown a[href*=\"edit_profile.php\"]').click()");
    await until("location.pathname.endsWith('/resident/edit_profile.php')&&document.readyState==='complete'");
    const originalName=await ev("document.querySelector('[name=full_name]').value"),updatedName=originalName+' Profile Test';
    await ev('window.profileSubmitting=true;document.querySelector("[name=full_name]").value='+JSON.stringify(updatedName)+';document.querySelector("[name=full_name]").form.requestSubmit()');
    await until("!window.profileSubmitting&&!!document.querySelector('.alert.success')&&document.readyState==='complete'");
    await ev('window.idNavigating=true');await send('Page.reload');await until("!window.idNavigating&&document.readyState==='complete'&&!!document.querySelector('[name=full_name]')");
    await check("document.querySelector('[name=full_name]').value==="+JSON.stringify(updatedName), 'Existing profile update persists for account '+actor);
    await ev('window.profileSubmitting=true;document.querySelector("[name=full_name]").value='+JSON.stringify(originalName)+';document.querySelector("[name=full_name]").form.requestSubmit()');
    await until("!window.profileSubmitting&&!!document.querySelector('.alert.success')&&document.readyState==='complete'");
   }
   await go(1,'resident/dashboard.php');await ev("document.getElementById('profileToggle').click();document.querySelector('.profile-dropdown a[href*=\"logout.php\"]').click()");
   await until("location.pathname.endsWith('/logout.php')&&document.readyState==='complete'");
   await check("!!document.querySelector('form[method=post] input[name=csrf_token]')",'Existing secure logout confirmation retained');
   await ev("document.querySelector('form[method=post]').requestSubmit()");await until("location.pathname.endsWith('/login.php')&&document.readyState==='complete'");
   await check("(await fetch('api/digital_id.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'issue'})})).status===401",'Logout ends authenticated QR access');
   if (errors.length) throw Error(errors.join('\n'));
   if (badResources.length) throw Error('Missing resources: '+badResources.join(', '));
   console.log(JSON.stringify({checks,screenshots:directory,profileDropdown:true}));
   return;
  }
  const renderedCodes=[];
  for(const mobile of [false,true]) {
   await send('Emulation.setDeviceMetricsOverride',{width:mobile?390:1366,height:mobile?844:1000,deviceScaleFactor:1,mobile});
   for(const [actor,route,role] of roles) {
    await go(actor,route);
    await check("window.idRequests.issue===0 && !document.querySelector('.digital-id-qr').childElementCount",'No generation on page load: '+role);
    await check("document.querySelector('[data-digital-id-toggle]').nextElementSibling.matches('[data-theme-toggle]')",'Navbar order: '+role);
    for(const theme of ['dark','light']) {
     await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);await open();
     await check(`document.querySelector('.digital-id-role span').textContent===${JSON.stringify(role)}`,'Actual role: '+role);
     await check("document.querySelector('.digital-id-name').textContent===window.lastIssuedId.profile.name",'Actual full name');
     await check("/^Refreshes in: 00:[0-5][0-9]$/.test(document.querySelector('.digital-id-countdown').textContent)",'Countdown beneath code');
     await check("document.body.style.overflow==='hidden' && document.getElementById('digitalIdDialog').contains(document.activeElement)",'Modal scroll and focus');
     await check("getComputedStyle(document.querySelector('.digital-id-qr-frame')).backgroundColor==='rgb(255, 255, 255)' && getComputedStyle(document.querySelector('.digital-id-qr canvas')).filter==='none'",'QR white quiet zone without inversion');
     await check("(()=>{const d=document.getElementById('digitalIdDialog'),r=d.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&d.scrollWidth<=d.clientWidth+1;})()",'Card fits viewport');
     await check("!document.querySelector('.digital-id-qr').hasAttribute('title') && !window.lastIssuedId.qr_url.includes(window.lastIssuedId.profile.name)",'No credential tooltip or QR personal data');
     if([1,2,6].includes(actor))await shot(`${actor}-${mobile?'mobile':'desktop'}-${theme}`);
     await ev("document.querySelector('.digital-id-qr-frame').scrollIntoView({block:'center'})");await sleep(250);
     const clip=await ev("(()=>{const r=document.querySelector('.digital-id-qr-frame').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,scale:1};})()");
     const capture=await send('Page.captureScreenshot',{format:'png',clip});
     renderedCodes.push({image:'data:image/png;base64,'+capture.data,url:await ev('window.lastIssuedId.qr_url')});
     await close();
     await check("!document.querySelector('.digital-id-qr').childElementCount && document.activeElement.matches('[data-digital-id-toggle]')",'Close clears QR and restores focus');
    }
   }
  }
  await go(1,'resident/dashboard.php');await open();
  const initial=await ev('window.lastIssuedId.qr_url'),count=await ev('window.idRequests.issue');
  const countdown=await ev("document.querySelector('.digital-id-countdown').textContent");await sleep(1200);
  await check(`document.querySelector('.digital-id-countdown').textContent!==${JSON.stringify(countdown)}`,'Countdown progresses');
  if (!process.argv.includes('--quick')) {
  console.log('Checking a real 60-second renewal...');
  // Slow the response slightly so the renewal state can be observed.
  await ev('window.idDelay=600');
  await until("document.querySelector('.digital-id-feedback').textContent==='Refreshing QR Code...'",65000);
  await check("document.querySelector('.digital-id-qr-frame').hidden",'Expired QR removed during renewal');
  await until(`window.idRequests.issue>${count} && !document.querySelector('.digital-id-qr-frame').hidden`);
  await check(`window.lastIssuedId.qr_url!==${JSON.stringify(initial)} && window.idRequests.maxActive===1`,'Fresh credential and serialized renewal');
  await check(`(await(await fetch(${JSON.stringify(base+'api/digital_id_verify.php')},{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({credential:new URL(${JSON.stringify(initial)}).hash.slice(12)})})).json()).verification.verification_result==='expired'`,'Original screenshot expires on server');
  }
  await ev('window.idDelay=0');
  const current=await ev('window.lastIssuedId.qr_url');await ev("document.querySelector('.digital-id-revoke').click()");
  await until("document.querySelector('.digital-id-feedback').textContent.startsWith('QR Code Revoked')");
  await check(`!(await(await fetch(${JSON.stringify(base+'api/digital_id_verify.php')},{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({credential:new URL(${JSON.stringify(current)}).hash.slice(12)})})).json()).verification.valid`,'Revoke action rejects active screenshot');
  await close();await open();await close();
  // Close/reopen during a pending request: the new request must wait for abort settlement.
  await ev("window.idDelay=800;document.querySelector('[data-digital-id-toggle]').click()");
  await sleep(100);await close();await ev("document.querySelector('[data-digital-id-toggle]').click()");
  await until("document.querySelector('.digital-id-qr-frame').hidden===false");
  await check('window.idRequests.maxActive===1','Rapid reopen never overlaps issuance');await close();
  await ev("window.idDelay=0;window.idFailNext=true;document.querySelector('[data-digital-id-toggle]').click()");
  await until("document.querySelector('.digital-id-retry').hidden===false");
  await check("document.querySelector('.digital-id-qr-frame').hidden",'Failed renewal cannot show stale QR');
  await close();const stopped=await ev('window.idRequests.issue');await sleep(3500);
  await check(`window.idRequests.issue===${stopped}`,'Close cancels retry timer');
  await open();
  await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
  await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
  await until("!document.getElementById('digitalIdDialog').open && document.body.style.overflow!=='hidden'");
  await check("!document.querySelector('.digital-id-qr').childElementCount",'Escape closes and clears credential');
  await open();
  await send('Input.dispatchMouseEvent',{type:'mousePressed',x:5,y:5,button:'left',clickCount:1});
  await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:5,y:5,button:'left',clickCount:1});
  await until("!document.getElementById('digitalIdDialog').open && document.body.style.overflow!=='hidden'");
  await check("!document.querySelector('.digital-id-qr').childElementCount",'Backdrop closes and clears credential');
  await open();
  const url=await ev('window.lastIssuedId.qr_url');
  await ev("document.querySelector('.digital-id-qr-frame').scrollIntoView({block:'center'})");await sleep(250);
  const clip=await ev("(()=>{const r=document.querySelector('.digital-id-qr-frame').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,scale:1};})()");
  const qrCapture=await send('Page.captureScreenshot',{format:'png',clip});
  const qrImage='data:image/png;base64,'+qrCapture.data;
  fs.writeFileSync(path.join(directory,'generated-qr.png'),Buffer.from(qrCapture.data,'base64'));await close();
  await go(5,'security/scanner.php');
  await check("document.querySelector('[data-digital-id-toggle]').nextElementSibling.matches('[data-theme-toggle]')",'Scanner navbar order');
  await until("typeof Html5Qrcode==='function'");
  await check(`(await new Html5Qrcode('reader').scanFile(new File([await(await fetch(${JSON.stringify(qrImage)})).blob()],'digital-id.png',{type:'image/png'}),false))===${JSON.stringify(url)}`,'Generated QR decodes with existing scanner');
  await ev(`document.getElementById('manualScan').value=${JSON.stringify(url)};document.getElementById('manualScanForm').requestSubmit()`);
  await until("document.getElementById('scanResult').textContent==='Digital Condo ID' && document.getElementById('scanResultCard').dataset.state==='valid'");
  await check("document.getElementById('scanResultFields').textContent.includes('Unit Owner') && document.getElementById('scanResultFields').textContent.includes('Owner One')",'Existing scanner shows identity and role');
  for(const [index,code] of renderedCodes.entries()) {
   try {
    await check(`(await new Html5Qrcode('reader').scanFile(new File([await(await fetch(${JSON.stringify(code.image)})).blob()],'digital-id.png',{type:'image/png'}),false))===${JSON.stringify(code.url)}`,'QR screenshot decodes for role/theme/layout '+index);
   } catch(error) {
    fs.writeFileSync(path.join(directory,'failed-qr-'+index+'.png'),Buffer.from(code.image.split(',')[1],'base64'));
    throw Error('QR screenshot '+index+': '+error.message);
   }
  }
  await go(5,'digital_id_verify.php'+new URL(url).hash);
  await until("document.getElementById('digitalIdVerificationStatus').dataset.state==='valid'");
  await check("document.getElementById('digitalIdVerificationDetails').textContent.includes('Owner One') && !location.hash",'Authorized page and fragment removal');
  await go(0,'digital_id_verify.php'+new URL(url).hash);
  await until("document.getElementById('digitalIdVerificationStatus').dataset.state==='valid'");
  await check("!document.getElementById('digitalIdVerificationDetails').childElementCount && !document.body.textContent.includes('Owner One')",'Anonymous page has no private identity');
  if(errors.length)throw Error(errors.join('\n'));
  if(badResources.length)throw Error('Missing resources: '+badResources.join(', '));
  console.log(JSON.stringify({checks,screenshots:directory}));
 } finally { socket?.close();chrome.kill(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
