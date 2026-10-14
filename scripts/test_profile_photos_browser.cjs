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

  const choose=async()=>{
    const document=await send('DOM.getDocument');const node=await send('DOM.querySelector',{nodeId:document.root.nodeId,selector:'#profilePhotoInput'});
    await send('DOM.setFileInputFiles',{nodeId:node.nodeId,files:[path.join(directory,'sample.png')]});
    await sleep(100);await until("document.getElementById('profilePhotoEditor').open");
  };
  await go(1);await ev("(async()=>{if(CelandineProfile.get().photo_url){const d=document.getElementById('profilePhotoEditor'),r=await fetch(d.dataset.endpoint,{method:'POST',body:new URLSearchParams({action:'remove',csrf_token:d.dataset.csrf})}),data=await r.json();CelandineProfile.update(data.profile);document.getElementById('removeProfilePhoto').hidden=true;}})()");const sample=await ev("(()=>{const c=document.createElement('canvas');c.width=900;c.height=600;const g=c.getContext('2d');g.fillStyle='#e57110';g.fillRect(0,0,900,600);g.fillStyle='#1855cc';g.fillRect(60,40,300,520);g.fillStyle='#35cc80';g.fillRect(540,100,260,350);return c.toDataURL('image/png').split(',')[1];})()");
  fs.writeFileSync(path.join(directory,'sample.png'),Buffer.from(sample,'base64'));
  if(!process.argv.includes('--editor-only'))for(const [actor,role] of [[1,'Unit Owner'],[2,'Tenant'],[4,'Admin'],[5,'Security Personnel'],[6,'Super Admin'],[7,'Treasurer'],[8,'Maintenance']]){
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});await go(actor,actor<=2?'resident/edit_profile.php':'edit_profile.php');
    await check('document.querySelector(".profile-role-badge").textContent.trim()==='+JSON.stringify(role),'Role label '+role);
    await check("JSON.stringify([...document.querySelectorAll('.profile-dropdown .profile-dropdown-item')].map(n=>n.textContent.trim()))===JSON.stringify(['Edit Profile','My QR ID','Light/Dark Mode','Logout'])",'Shared menu order '+role);
    await check("[...document.querySelectorAll('[data-avatar-initial]')].every(n=>Array.from(n.textContent).length===1)",'Single initial everywhere '+role);
    await check('!!document.getElementById("unit_number_display")==='+String(actor<=2),'Role appropriate readonly unit '+role);
    await ev("document.getElementById('notificationToggle').click()");await check("document.getElementById('notificationMenu').classList.contains('open')",'Notifications work from the shared profile route '+role);await ev("document.getElementById('notificationToggle').click()");
    for(const width of [1366,768,390,320])for(const theme of ['dark','light']){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<700});await ev('CelandineTheme.set('+JSON.stringify(theme)+')');
      await check("document.documentElement.scrollWidth<=innerWidth+1",'Profile fits '+role+' '+width+' '+theme);
      await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});await ev("document.getElementById('changeProfilePhoto').focus()");await sleep(220);
      await check("(()=>{const b=document.getElementById('changeProfilePhoto').getBoundingClientRect(),s=document.querySelector('.profile-photo-camera svg').getBoundingClientRect();return Math.abs(b.x+b.width/2-s.x-s.width/2)<1&&Math.abs(b.y+b.height/2-s.y-s.height/2)<1&&getComputedStyle(document.querySelector('.profile-photo-camera')).opacity==='1';})()",'Keyboard camera centered '+role+' '+width+' '+theme);
    }
    await choose();await check("document.getElementById('profilePhotoEditor').scrollWidth<=document.getElementById('profilePhotoEditor').clientWidth&&document.getElementById('profilePhotoEditor').getBoundingClientRect().left>=0",'Crop fits mobile '+role);
    await check("document.getElementById('profilePhotoCanvas').width===512&&document.getElementById('profilePhotoCanvas').height===512",'Square crop '+role);
    await ev("document.querySelector('[data-photo-cancel]').click()");await check("!document.querySelector('[data-avatar-image]')&&!document.getElementById('profilePhotoEditor').open",'Cancel preserves initial '+role);
    await sleep(150);await choose();await ev("var z=document.getElementById('profilePhotoZoom');z.value='2';z.dispatchEvent(new Event('input'));window.beforeCrop=document.getElementById('profilePhotoCanvas').toDataURL();document.getElementById('profilePhotoStage').dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}))");
    await check("window.beforeCrop!==document.getElementById('profilePhotoCanvas').toDataURL()",'Zoom and reposition '+role);
    await ev("document.getElementById('resetProfilePhoto').click()");await check("document.getElementById('profilePhotoZoom').value==='1'",'Reset '+role);
    await ev("var z=document.getElementById('profilePhotoZoom');z.value='1.6';z.dispatchEvent(new Event('input'));window.cropPixels=Array.from(document.getElementById('profilePhotoCanvas').getContext('2d').getImageData(0,0,512,512).data);document.getElementById('applyProfilePhoto').click()");
    await until("!document.getElementById('profilePhotoEditor').open&&document.getElementById('profilePhotoFeedback').textContent==='Profile picture updated.'");
    await until("[...document.querySelectorAll('[data-profile-avatar]')].every(n=>n.querySelector('img')?.naturalWidth===512)");
    await check("document.querySelectorAll('[data-avatar-image]').length>=4&&!document.getElementById('removeProfilePhoto').hidden&&document.querySelector('.portal-loading-overlay').hidden",'All own avatars synchronized without fullscreen loading '+role);
    await check("(async()=>{const im=new Image();im.src=CelandineProfile.get().photo_url;await im.decode();const c=document.createElement('canvas');c.width=c.height=512;c.getContext('2d').drawImage(im,0,0);const p=c.getContext('2d').getImageData(0,0,512,512).data;let d=0;for(let i=0;i<p.length;i+=4)d+=Math.abs(p[i]-window.cropPixels[i])+Math.abs(p[i+1]-window.cropPixels[i+1])+Math.abs(p[i+2]-window.cropPixels[i+2]);return d/(512*512*3)<5;})()",'Saved JPEG matches preview '+role);
    await ev("document.querySelector('[data-digital-id-toggle]').click()");await until("!document.querySelector('.digital-id-qr-frame').hidden&&document.querySelector('.digital-id-avatar img')?.naturalWidth===512");checks++;
    await ev("document.querySelector('.digital-id-close').click()");
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});await shot('photo-'+actor+'-desktop');
    await go(actor,actor<=2?'resident/edit_profile.php':'edit_profile.php');await until("document.querySelector('.profile-overview-avatar img')?.naturalWidth===512");checks++;
    await ev("window.nativeConfirm=window.confirm;window.confirm=()=>false;document.getElementById('removeProfilePhoto').click();window.confirm=window.nativeConfirm");await check("!!document.querySelector('.profile-overview-avatar img')",'Removal cancellation retains photo');
    await ev("window.confirm=()=>true;document.getElementById('removeProfilePhoto').click();window.confirm=window.nativeConfirm");await until("document.getElementById('profilePhotoFeedback').textContent==='Profile picture removed.'");
    await check("!document.querySelector('[data-avatar-image]')&&document.getElementById('removeProfilePhoto').hidden",'Removal restores all initials '+role);
    // Staff ordinary profile saving uses the same controller and ignores privileged fields.
    if(actor>2){const original=await ev("({name:document.getElementById('full_name').value,email:document.getElementById('email').value,phone:document.getElementById('contact_number').value})");const response=await post({action:'update_profile',full_name:original.name,email:original.email,contact_number:original.phone,role:'superadmin',unit_number:'9999',staff_id:'HACK'});if(response.status!==200||!response.html.includes('Profile updated.'))throw Error('Staff personal update failed');checks++;for(const [password,next]of [['wrong','ProfileStaff!2026'],['FixtureOnly!2026','ProfileStaff!2026'],['ProfileStaff!2026','FixtureOnly!2026']]){const result=await post({action:'update_password',current_password:password,new_password:next,confirm_password:next,full_name:'Injected',role:'superadmin'});if(!result.html.includes(password==='wrong'?'Current password is incorrect.':'Password updated.'))throw Error('Staff password verification failed '+role);checks++;}await go(actor,'edit_profile.php');await check('document.querySelector(".profile-role-badge").textContent.trim()==='+JSON.stringify(role),'Staff role unchanged');}
  }

  await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});await go(1);
  let fileChoosers=0;const receive=socket.onmessage;socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.method==='Page.fileChooserOpened')fileChoosers++;receive(e);};
  await send('Page.setInterceptFileChooserDialog',{enabled:true});
  await ev("document.getElementById('changeProfilePhoto').scrollIntoView({block:'center'});document.getElementById('changeProfilePhoto').focus()");
  await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13,text:'\r',unmodifiedText:'\r'});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});await sleep(150);
  if(fileChoosers!==1)throw Error('Keyboard avatar did not open the native image picker '+fileChoosers+' '+await ev("document.activeElement.outerHTML"));checks++;
  await choose();await shot('crop-dark-desktop');
  await ev("var z=document.getElementById('profilePhotoZoom');z.value='2';z.dispatchEvent(new Event('input'));window.cropBeforePointer=document.getElementById('profilePhotoCanvas').toDataURL()");
  let box=await ev("document.getElementById('profilePhotoStage').getBoundingClientRect().toJSON()");
  await send('Input.dispatchMouseEvent',{type:'mousePressed',x:box.x+box.width/2,y:box.y+box.height/2,button:'left',clickCount:1});
  await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:box.x+box.width/2+35,y:box.y+box.height/2-20,button:'left',buttons:1});
  await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:box.x+box.width/2+35,y:box.y+box.height/2-20,button:'left',clickCount:1});
  await check("window.cropBeforePointer!==document.getElementById('profilePhotoCanvas').toDataURL()",'Real mouse drag adjusts crop');
  await ev("document.querySelector('[data-photo-cancel]').click()");await sleep(100);
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await ev("CelandineTheme.set('light')");await choose();await shot('crop-light-mobile');
  box=await ev("document.getElementById('profilePhotoStage').getBoundingClientRect().toJSON()");
  const cx=box.x+box.width/2,cy=box.y+box.height/2;
  await send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:cx-30,y:cy,id:1},{x:cx+30,y:cy,id:2}]});
  await send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:cx-65,y:cy,id:1},{x:cx+65,y:cy,id:2}]});
  await send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await check("Number(document.getElementById('profilePhotoZoom').value)>1.5",'Real touch pinch zooms crop');
  await ev("window.cropBeforePointer=document.getElementById('profilePhotoCanvas').toDataURL()");
  await send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:cx,y:cy,id:3}]});await send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:cx+25,y:cy+15,id:3}]});await send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  await check("window.cropBeforePointer!==document.getElementById('profilePhotoCanvas').toDataURL()",'Real touch drag adjusts crop');
  // A rejected API request keeps the preview open for retry and does not change the avatar.
  await ev("window.photoOriginalFetch=window.fetch;window.fetch=(input,options)=>String(input).includes('api/profile_photo.php')?Promise.resolve(new Response(JSON.stringify({success:false,error:'Fixture upload failure'}),{status:503,headers:{'Content-Type':'application/json'}})):window.photoOriginalFetch(input,options);document.getElementById('applyProfilePhoto').click()");
  await until("document.getElementById('profilePhotoEditorFeedback').textContent==='Fixture upload failure'");
  await check("document.getElementById('profilePhotoEditor').open&&!document.querySelector('.profile-overview-avatar img')&&!document.getElementById('applyProfilePhoto').disabled&&document.querySelector('.portal-loading-overlay').hidden",'Failed upload preserves picture and allows retry with no overlay');
  await ev("window.fetch=window.photoOriginalFetch;document.querySelector('[data-photo-cancel]').click()");await sleep(100);
  await ev("var input=document.getElementById('profilePhotoInput'),transfer=new DataTransfer();transfer.items.add(new File(['<svg></svg>'],'fake.jpg',{type:'image/jpeg'}));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}))");
  await until("document.getElementById('profilePhotoFeedback').dataset.error==='true'");await check("!document.getElementById('profilePhotoEditor').open&&!document.querySelector('.profile-overview-avatar img')",'Frontend actual-content validation prevents spoofed images');

  // A second real browser tab receives only the storage signal and reloads its own profile.
  const created=await send('Target.createTarget',{url:base+'resident/edit_profile.php?fixture_actor=1'});
  const secondTarget=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.id===created.targetId);
  const second=new WebSocket(secondTarget.webSocketDebuggerUrl);await new Promise(resolve=>second.onopen=resolve);
  let secondId=0;const secondCalls=new Map();second.onmessage=e=>{const m=JSON.parse(e.data),c=secondCalls.get(m.id);if(c){secondCalls.delete(m.id);m.error?c.reject(m.error):c.resolve(m.result);}};
  const secondSend=(method,params={})=>new Promise((resolve,reject)=>{const id=++secondId;secondCalls.set(id,{resolve,reject});second.send(JSON.stringify({id,method,params}));});
  const secondEv=async expression=>(await secondSend('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result.value;
  try{
    for(let i=0;i<100;i++){if(await secondEv("document.readyState==='complete'&&!!window.CelandineProfile"))break;await sleep(100);}
    await send('Page.bringToFront');await choose();await ev("document.getElementById('applyProfilePhoto').click()");await until("document.getElementById('profilePhotoFeedback').textContent==='Profile picture updated.'");
    let synced=false;for(let i=0;i<100;i++){if(await secondEv("document.querySelector('.profile-overview-avatar img')?.naturalWidth===512&&!document.getElementById('removeProfilePhoto').hidden")){synced=true;break;}await sleep(100);}
    if(!synced)throw Error('Other open tab did not fetch the updated picture');checks++;
    const issued=await ev("(async()=>{const response=await fetch(new URL('api/digital_id.php',document.getElementById('profilePhotoEditor').dataset.endpoint.replace('api/profile_photo.php','')),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'issue',csrf_token:document.getElementById('profilePhotoEditor').dataset.csrf})});return response.json();})()");

    const browserInfo=await(await fetch(`http://127.0.0.1:${port}/json/version`)).json();
    const browserSocket=new WebSocket(browserInfo.webSocketDebuggerUrl);await new Promise(resolve=>browserSocket.onopen=resolve);
    let browserSeq=0;const browserCalls=new Map();browserSocket.onmessage=e=>{const m=JSON.parse(e.data),c=browserCalls.get(m.id);if(c){browserCalls.delete(m.id);m.error?c.reject(m.error):c.resolve(m.result);}};
    const browserSend=(method,params={})=>new Promise((resolve,reject)=>{const id=++browserSeq;browserCalls.set(id,{resolve,reject});browserSocket.send(JSON.stringify({id,method,params}));});
    const isolated=await browserSend('Target.createBrowserContext');
    let reviewer;
    try{
      const verifyUrl=new URL(issued.qr_url);verifyUrl.searchParams.set('fixture_actor','5');
      const reviewTarget=await browserSend('Target.createTarget',{url:verifyUrl.href,browserContextId:isolated.browserContextId});
      const info=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.id===reviewTarget.targetId);
      reviewer=new WebSocket(info.webSocketDebuggerUrl);await new Promise(resolve=>reviewer.onopen=resolve);
      let n=0;const pending=new Map();reviewer.onmessage=e=>{const m=JSON.parse(e.data),c=pending.get(m.id);if(c){pending.delete(m.id);m.error?c.reject(m.error):c.resolve(m.result);}};
      const reviewSend=(method,params={})=>new Promise((resolve,reject)=>{const id=++n;pending.set(id,{resolve,reject});reviewer.send(JSON.stringify({id,method,params}));});
      const reviewEv=async expression=>(await reviewSend('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true})).result.value;
      let visible=false;for(let i=0;i<100;i++){if(await reviewEv("document.querySelector('#digitalIdVerificationDetails [data-profile-avatar=\"1\"] img')?.naturalWidth===512")){visible=true;break;}await sleep(100);}
      if(!visible)throw Error('Authorized verification page did not display the latest photo');checks++;
      await reviewSend('Page.navigate',{url:base+'security/scanner.php?fixture_actor=5'});
      for(let i=0;i<100;i++){if(await reviewEv("document.readyState==='complete'&&!!document.getElementById('manualScanSubmit')"))break;await sleep(100);}
      await reviewEv('document.getElementById("manualScan").value='+JSON.stringify(issued.qr_url)+';document.getElementById("manualScanSubmit").click()');
      visible=false;for(let i=0;i<100;i++){if(await reviewEv("document.querySelector('#scanResultFields [data-profile-avatar=\"1\"] img')?.naturalWidth===512")){visible=true;break;}await sleep(100);}
      if(!visible)throw Error('Authorized scanner did not display the latest photo');checks++;
    }finally{reviewer?.close();await browserSend('Target.disposeBrowserContext',{browserContextId:isolated.browserContextId});browserSocket.close();}

    await ev("window.confirm=()=>true;document.getElementById('removeProfilePhoto').click();window.confirm=window.nativeConfirm");await until("document.getElementById('profilePhotoFeedback').textContent==='Profile picture removed.'");
    synced=false;for(let i=0;i<100;i++){if(await secondEv("!document.querySelector('[data-avatar-image]')&&document.getElementById('removeProfilePhoto').hidden")){synced=true;break;}await sleep(100);}
    if(!synced)throw Error('Other open tab did not restore the initial');checks++;
  }finally{second.close();await send('Target.closeTarget',{targetId:created.targetId});}
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
