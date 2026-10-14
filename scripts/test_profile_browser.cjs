/* Only use the disposable test_ui_redesign.php --preview fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='resident/edit_profile.php'))throw Error('Missing profile fixture');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-profile-browser-'));
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
  const go=async(actor,route='resident/edit_profile.php')=>{await ev('window.profileNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.profileNavigating&&document.readyState==='complete'&&!!window.CelandineUi");await sleep(350);};
  const post=async fields=>ev(`(async()=>{const form=document.getElementById('personalInformationForm'),body=new URLSearchParams({csrf_token:form.querySelector('[name=csrf_token]').value,...${JSON.stringify(fields)}});const response=await fetch('edit_profile.php',{method:'POST',body});const text=await response.text();return {status:response.status,html:text};})()`);
  const submit=async form=>{await ev(`window.profileSubmitting=true;document.getElementById(${JSON.stringify(form)}).requestSubmit(document.querySelector('#'+${JSON.stringify(form)}+' [type=submit]'))`);await until("!window.profileSubmitting&&document.readyState==='complete'&&!!document.querySelector('.alert')");};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  if(process.argv.includes('--controls-only')) {
   await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
   for(const actor of [1,2]) {
    await go(actor);
    await ev("document.getElementById('menuToggle').click()");await until("document.getElementById('sidebar').classList.contains('open')");
    await check("document.body.classList.contains('portal-drawer-open')&&document.querySelector('.sidebar-close').getClientRects().length>0",'Mobile sidebar opens with accessible close control');
    await ev("document.querySelector('.sidebar-close').click()");await until("!document.getElementById('sidebar').classList.contains('open')");checks++;
    await ev("document.getElementById('profileToggle').click();window.profileThemeBefore=document.documentElement.dataset.theme;document.querySelector('[data-profile-theme]').click()");
    await check("document.getElementById('profileMenu').classList.contains('open')&&document.documentElement.dataset.theme!==window.profileThemeBefore",'Profile theme switch preserves the open menu');
    await ev("document.querySelector('[data-theme-toggle]').click()");
    await check("document.documentElement.dataset.theme===window.profileThemeBefore&&document.querySelector('[data-profile-theme]').getAttribute('aria-checked')===String(window.profileThemeBefore==='light')",'Navbar theme and profile theme stay synchronized');
    await ev("document.querySelector('.dash-header-right>[data-digital-id-toggle]').click()");await until("document.getElementById('digitalIdDialog').open&&!document.querySelector('.digital-id-qr-frame').hidden");
    await check("!!document.querySelector('.digital-id-qr canvas')",'Existing navbar QR ID remains functional');
    await ev("document.querySelector('.digital-id-close').click()");await until("!document.getElementById('digitalIdDialog').open");
   }
   if(errors.length)throw Error(errors.join('\n'));
   console.log(JSON.stringify({checks,controls:true}));return;
  }
  for(const actor of [1,2]) {
   await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1100,deviceScaleFactor:1,mobile:false});
   await go(actor);
   const original=await ev("({name:document.querySelector('[name=full_name]').value,email:document.querySelector('[name=email]').value,phone:document.querySelector('[name=contact_number]').value,unit:document.getElementById('unit_number_display').value,username:document.getElementById('username_display').value})");
   await check("document.querySelector('.profile-role-badge').textContent.trim()==="+JSON.stringify(actor===1?'Unit Owner':'Tenant'), 'Correct stored account role');
   await check("document.querySelectorAll('.profile-settings>.profile-section').length===3&&document.querySelectorAll('[data-profile-form]').length===2&&document.querySelector('#personalInformationForm [name=action]').value==='update_profile'&&document.querySelector('#passwordChangeForm [name=action]').value==='update_password'",'Three cards and separate personal/security forms');
   await check("document.querySelectorAll('#personalInformationForm [type=submit]').length===1&&document.querySelectorAll('#passwordChangeForm [type=submit]').length===1&&!document.querySelector('#personalInformationForm [name=new_password]')&&!document.querySelector('#passwordChangeForm [name=full_name]')",'One Save Changes and one separate Update Password control');
   await check("document.getElementById('username_display').readOnly&&document.getElementById('unit_number_display').readOnly&&!document.querySelector('#personalInformationForm [name=username],#personalInformationForm [name=unit_number]')",'Username and unit remain readonly');
   for(const width of [1366,768,390,320]) {
    await send('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:false});
    for(const theme of ['dark','light']) {
     await ev('CelandineTheme.set('+JSON.stringify(theme)+');document.querySelector(".profile-password-details").open=true');await sleep(200);
     await check("document.documentElement.scrollWidth<=innerWidth+1&&[...document.querySelectorAll('.profile-actions')].every(node=>getComputedStyle(node).position==='static')",'No overflow or sticky action overlap '+actor+' '+width+' '+theme);
     await check("(()=>{const fields=[...document.querySelectorAll('.profile-field input')].filter(field=>field.getClientRects().length);return fields.every(field=>field.getBoundingClientRect().height>=44&&field.getBoundingClientRect().width>100)&&[...document.querySelectorAll('.profile-password-toggle')].filter(button=>button.getClientRects().length).every(button=>button.getBoundingClientRect().height>=44);})()",'Readable fields and touch controls');
     await check("[...document.querySelectorAll('.profile-field')].filter(node=>node.querySelector('input').getClientRects().length).every(node=>{const label=node.querySelector('label').getBoundingClientRect(),field=node.querySelector('input').getBoundingClientRect();return label.height>12&&label.bottom<=field.top;})",'Every visible input has a readable label above it');
     await check('getComputedStyle(document.querySelector(".profile-fields.form-grid-2")).gridTemplateColumns.split(" ").length==='+String(width<=700?1:2),'Responsive personal information columns');
     await check("document.querySelector('#personalInformationForm button[type=submit]').getBoundingClientRect().bottom<document.querySelector('.profile-security-card').getBoundingClientRect().top",'Save action does not obscure Account Security');
     if([1366,390].includes(width))await shot(actor+'-'+width+'-'+theme);
    }
   }
   await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1100,deviceScaleFactor:1,mobile:false});
   await ev("const f=document.getElementById('passwordChangeForm');f.elements.current_password.value='wrong';f.elements.new_password.value='StrongPassword!2026';f.elements.confirm_password.value='different';f.elements.confirm_password.dispatchEvent(new Event('input'))");
   await check("!document.getElementById('passwordChangeForm').checkValidity()&&document.getElementById('personalInformationForm').checkValidity()",'Mismatched passwords block security but do not block personal saving');
   await ev("document.querySelector('[data-password-target=current_password]').click()");
   await check("document.getElementById('current_password').type==='text'&&document.querySelector('[data-password-target=current_password]').getAttribute('aria-pressed')==='true'",'Password visibility control works');
   await ev("document.querySelector('[data-password-target=current_password]').click();document.getElementById('email').value='changed@example.invalid';document.getElementById('email').dispatchEvent(new Event('input'))");
   await check("!document.getElementById('emailPasswordConfirmation').hidden&&document.getElementById('email_current_password').required&&!document.getElementById('personalInformationForm').checkValidity()",'Email changes require current password');
   await ev('document.getElementById("email").value='+JSON.stringify(original.email)+';document.getElementById("email").dispatchEvent(new Event("input"))');
   // Submit twice in one event loop. Inspect the old document through sessionStorage.
   await send('Network.emulateNetworkConditions',{offline:false,latency:250,downloadThroughput:-1,uploadThroughput:-1});
   await ev("sessionStorage.removeItem('profileSubmitSnapshot');sessionStorage.removeItem('profileSpinnerSeen');document.addEventListener('submit',event=>{const states=JSON.parse(sessionStorage.getItem('profileSubmitSnapshot')||'[]');states.push(event.defaultPrevented);sessionStorage.setItem('profileSubmitSnapshot',JSON.stringify(states));});new MutationObserver(()=>{if(document.querySelector('#personalInformationForm [type=submit]').classList.contains('portal-button-loading'))sessionStorage.setItem('profileSpinnerSeen','true');}).observe(document.querySelector('#personalInformationForm [type=submit]'),{attributes:true,attributeFilter:['class']});document.querySelector('[name=full_name]').value="+JSON.stringify(original.name+' Updated')+";window.profileSubmitting=true;document.getElementById('personalInformationForm').requestSubmit(document.querySelector('#personalInformationForm [type=submit]'));document.getElementById('personalInformationForm').requestSubmit(document.querySelector('#personalInformationForm [type=submit]'))");
   await until("!window.profileSubmitting&&document.readyState==='complete'&&!!document.querySelector('.alert.success')");
   await send('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});
   await check("sessionStorage.getItem('profileSpinnerSeen')==='true'&&document.querySelector('.portal-loading-overlay').hidden",'Shared loading spinner appears during saving and cleans up');
   await check("JSON.stringify(JSON.parse(sessionStorage.getItem('profileSubmitSnapshot')))===JSON.stringify([false,true])",'Duplicate submit is prevented without removing native POST fields');
   await check("document.querySelector('.profile-overview h2').textContent==="+JSON.stringify(original.name+' Updated')+"&&document.querySelector('.profile-nav-name').textContent==="+JSON.stringify(original.name+' Updated'), 'Actual server update refreshes overview and navbar');
   await go(actor);await check("document.getElementById('full_name').value==="+JSON.stringify(original.name+' Updated'),'Profile name persists after refresh');
   const invalid=await post({action:'update_profile',full_name:'Unsaved Draft',email:original.email,contact_number:'091'});
   if(!invalid.html.includes('11 digit Philippine')||!invalid.html.includes('value="Unsaved Draft"'))throw Error('Server validation/draft preservation');checks++;
   const readonly=await post({action:'update_profile',full_name:original.name,email:original.email,contact_number:original.phone,username:'injected',unit_number:'9999',role:'admin'});
   if(!readonly.html.includes('Profile updated.'))throw Error('Profile restore failed');checks++;
   await go(actor);await check("document.getElementById('username_display').value==="+JSON.stringify(original.username)+"&&document.getElementById('unit_number_display').value==="+JSON.stringify(original.unit), 'Forged readonly fields are ignored server-side');
   for(const [current,next] of [['wrong','ProfileChanged!2026'],['FixtureOnly!2026','ProfileChanged!2026'],['ProfileChanged!2026','FixtureOnly!2026']]) {
    await ev("document.querySelector('.profile-password-details').open=true;const form=document.getElementById('passwordChangeForm');form.elements.current_password.value="+JSON.stringify(current)+";form.elements.new_password.value="+JSON.stringify(next)+";form.elements.confirm_password.value="+JSON.stringify(next)+";form.elements.confirm_password.dispatchEvent(new Event('input'))");
    await submit('passwordChangeForm');
    await check(current==='wrong'?"!!document.querySelector('.alert.error')&&document.querySelector('.profile-password-details').open":"!!document.querySelector('.alert.success')&&document.querySelector('.alert.success').textContent.includes('Password updated.')",'Server checks password and saves independently');
    await check("document.getElementById('full_name').value==="+JSON.stringify(original.name)+"&&document.getElementById('email').value==="+JSON.stringify(original.email)+"&&[...document.querySelectorAll('#passwordChangeForm input[type=password]')].every(field=>field.value==='')",'Password changes preserve personal data and clear secrets');
   }
   await ev("document.getElementById('profileToggle').click()");await check("document.querySelector('.profile-dropdown a[href*=\"edit_profile.php\"]')&&document.getElementById('profileMenu').classList.contains('open')",'Existing Edit Profile menu remains functional');
  }
  if(errors.length)throw Error(errors.join('\n'));
  console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
