/* Uses only test_visitor_identity.php --preview's disposable database and accounts. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2];
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='resident/visitors.php'))throw Error('Resident/tenant fixture unavailable.');
 const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const value=server.address().port;server.close(()=>resolve(value));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-visitor-identity-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket;const report={checks:[],errors:[],screenshots:directory};
 try{
  let target;
  for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab=>tab.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');
  socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let number=0;const calls=new Map();
  socket.onmessage=event=>{const message=JSON.parse(event.data),call=calls.get(message.id);if(call){calls.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')report.errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++number,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout: '+method));},20000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const until=async expression=>{for(let i=0;i<100;i++){if(await ev(expression))return;await sleep(100);}throw Error('Timed out: '+expression);};
  const check=async(expression,label)=>{if(!await ev(expression))throw Error(label);report.checks.push(label);console.log('PASS '+label);};
  const shot=async name=>{const result=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
  await send('Page.enable');await send('Runtime.enable');
  await send('Network.enable');
  await send('Network.setBlockedURLs',{urls:['*cdnjs.cloudflare.com/ajax/libs/qrcodejs/*']});
  // Unique names allow retries against the same disposable preview database.
  await send('Page.addScriptToEvaluateOnNewDocument',{source:'window.visitorFixtureName='+JSON.stringify('Browser Upload Visitor '+Date.now().toString(36))});
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});

  const go=async(actor,pathname)=>{
   await ev('window.residentUiNavigating=true');
   await send('Page.navigate',{url:base+pathname+(pathname.includes('?')?'&':'?')+'fixture_actor='+actor});
   await until("!window.residentUiNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
  };
  const setFile=async(name,type,size)=>ev("(()=>{const form=document.querySelector('form[data-visitor-id-form]'),input=form.querySelector('[name=valid_id]'),data=new DataTransfer(),bytes="+(size?'new Uint8Array('+size+')':"Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jwXcAAAAASUVORK5CYII='),c=>c.charCodeAt(0))")+";data.items.add(new File([bytes],"+JSON.stringify(name)+",{type:"+JSON.stringify(type)+"}));input.files=data.files;input.dispatchEvent(new Event('change',{bubbles:true}));})()");
  if (!process.argv.includes('--parking-only')) {
  await go(1,'resident/visitors.php');
  await check("[...document.querySelectorAll('[data-workspace-tab]')].map(tab=>tab.dataset.workspaceTab).join(',')==='form,requests,history' && !document.querySelector('#visitor-panel-form').hidden",'Resident visitor tabs show Registration Form, Requests and History in order');
  await check("[...document.querySelectorAll('[data-workspace-tab]')].every(tab=>!tab.querySelector('.workspace-tab-count') || Number(tab.querySelector('.workspace-tab-count').textContent)===document.querySelectorAll('#visitor-panel-'+tab.dataset.workspaceTab+' .service-request').length)",'Request and history badges match their records');
  await check("(()=>{const form=document.querySelector('[data-visitor-id-form]');return form.enctype==='multipart/form-data' && form.querySelector('[name=id_type]').required && form.querySelector('[name=valid_id]').required && !form.querySelector('[name=id_number]').required;})()",'Identity fields have required type and upload, optional number');
  await check("document.querySelector('[name=id_type]').options.length===7 && document.querySelector('.visitor-id-privacy').textContent.includes('30 days')",'All six ID options and retention privacy notice appear');
  await setFile('document.pdf','application/pdf');
  await check("document.querySelector('[name=valid_id]').validationMessage.includes('JPG, PNG or WEBP')",'Browser rejects unsupported image types');
  await setFile('large.png','image/png',5*1024*1024+1);
  await check("document.querySelector('[name=valid_id]').validationMessage.includes('5 MB')",'Browser rejects files over 5 MB');
  await setFile('valid.png','image/png');
  await ev("const form=document.querySelector('[data-visitor-id-form]');form.querySelector('[name=visitor_name]').value=window.visitorFixtureName;form.querySelector('[name=start_date]').value=new Date().toLocaleDateString('en-CA');form.querySelector('[name=details]').value='Browser verified visit';form.querySelector('[name=id_type]').value='National ID'");
  await check("document.querySelector('[data-visitor-id-form]').checkValidity()",'Valid image and registration fields pass browser validation');
  await ev("window.visitorTabDraft=true;document.querySelector('[data-workspace-tab=requests]').click();document.querySelector('[data-workspace-tab=history]').click();document.querySelector('[data-workspace-tab=form]').click()");
  await check("window.visitorTabDraft && document.querySelector('[name=visitor_name]').value===window.visitorFixtureName && document.querySelector('[name=valid_id]').files.length===1 && !document.querySelector('#visitor-panel-form').hidden",'Tab switching preserves the draft and ID upload without reloading');
  await ev("document.querySelector('[data-workspace-tab=form]').dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}))");
  await check("document.activeElement.dataset.workspaceTab==='history' && !document.querySelector('#visitor-panel-history').hidden",'Keyboard navigation selects and focuses History');
  await ev("document.querySelector('[data-workspace-tab=form]').click()");
  await shot('resident-desktop');
  await ev("window.residentUiNavigating=true;document.querySelector('[data-visitor-id-form]').requestSubmit()");
  await until("!window.residentUiNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
  await check("[...document.querySelectorAll('.service-request')].some(row=>row.textContent.includes(window.visitorFixtureName) && row.textContent.includes('Pending'))",'Browser multipart submission creates a pending identity registration');
  await check("new URL(location.href).searchParams.get('tab')==='requests' && !document.querySelector('#visitor-panel-requests').hidden && document.querySelector('#visitor-panel-requests').textContent.includes(window.visitorFixtureName)",'Successful registration opens Requests');
  for(const width of [390,320]) {
   await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:true});
   await go(2,'resident/visitors.php');
   await check("document.documentElement.scrollWidth<=innerWidth+2 && document.querySelector('[name=valid_id]').getBoundingClientRect().right<=innerWidth",'Resident and tenant upload UI fits '+width+'px');
   await check("document.querySelector('.workspace-tabs').getBoundingClientRect().right<=innerWidth && [...document.querySelectorAll('[data-workspace-tab]')].every(tab=>tab.getBoundingClientRect().width>0) && !document.querySelector('.visitor-workspace').textContent.includes(window.visitorFixtureName)",'Tenant tabs fit '+width+'px and exclude another account registrations');
   if(width===390)await shot('tenant-mobile');
  }
  await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
  await go(4,'superadmin/service_requests.php?kind=visitor');
  await check("[...document.querySelectorAll('[data-workspace-tab]')].map(tab=>tab.dataset.workspaceTab).join(',')==='requests,history' && !document.querySelector('#visitor-panel-requests').hidden && !document.querySelector('#visitor-panel-history').textContent.includes(window.visitorFixtureName)",'Admin defaults to Requests with History separate');
  await check("[...document.querySelectorAll('.visitor-identity-summary')].some(row=>row.textContent.includes('Review ID document') && row.querySelector('[value=verified]'))",'Admin sees protected document link and identity review controls');
  await shot('admin-review');
  const incident=await ev("[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)).querySelector('[name=request_id]').value");
  await ev('window.residentUiNavigating=true');
  await send('Page.navigate',{url:base+'visitor_id_document.php?request_id='+incident+'&fixture_actor=4'});
  await until("!window.residentUiNavigating && document.readyState==='complete' && !!document.querySelector('img')");
  await check("document.querySelector('img').naturalWidth>0",'Protected ID image renders for an authorized reviewer');
  await go(4,'superadmin/service_requests.php?kind=visitor');
  await ev("[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)).querySelector('[name=identity_decision][value=rejected]').click()");
  await check("[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)).querySelector('[name=identity_review_note]').validationMessage.includes('reason')",'Rejecting an ID requires a reason in the browser');
  await ev("window.residentUiNavigating=true;[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)).querySelector('[name=identity_decision][value=verified]').click()");
  await until("!window.residentUiNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
  await check("[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)).textContent.includes('Verified')",'Admin can verify identity from the existing registration page');
  await ev("const row=[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)),button=row.querySelector('[name=decision][value=approved]');window.residentUiNavigating=true;button.form.requestSubmit(button)");
  await until("!window.residentUiNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
  await go(1,'resident/visitors.php');
  const passUrl=await ev("[...document.querySelectorAll('.service-request')].find(row=>row.textContent.includes(window.visitorFixtureName)).querySelector('a[href*=resident_service_pass]').href");
  await go(1,passUrl.slice(base.length));
  await check("!!document.querySelector('#serviceQr') && document.querySelector('.visitor-pass-details').textContent.includes('Verified') && !document.body.textContent.includes('ID reference ending') && !document.querySelector('img[src*=visitor_id_document]')",'Approved pass shows verification without ID documents or numbers');
  await check("!!document.querySelector('#serviceQr canvas,#serviceQr img') && document.querySelector('#passQrFallback').hidden",'Visitor QR renders when the external QR CDN is unavailable');
  await check("!document.querySelector('#serviceQr').hasAttribute('title') && document.querySelectorAll('script[src*=\"qrcode.min.js\"]').length===1",'One local QR library without an access-token tooltip');
  const qrImage=await ev("document.querySelector('#serviceQr canvas').toDataURL()");
  await go(5,'security/scanner.php');
  await until("typeof Html5Qrcode==='function'");
  await check("(async()=>await new Html5Qrcode('reader').scanFile(new File([await(await fetch("+JSON.stringify(qrImage)+")).blob()],'visitor-pass.png',{type:'image/png'}),false)==="+JSON.stringify(passUrl)+")()",'Generated visitor QR decodes to the approved pass in the existing scanner');
  await ev("document.querySelector('#manualScan').value="+JSON.stringify(passUrl)+";document.querySelector('#manualScanForm').requestSubmit()");
  await until("document.querySelector('#scanResultCard').dataset.state==='valid'");
  await check("document.querySelector('#scanResultFields').textContent.includes(window.visitorFixtureName) && document.querySelector('#scanResultFields').textContent.includes('Identity verificationVerified') && document.querySelector('#scanResultFields').textContent.includes('Browser verified visit')",'Scanner renders visitor name, purpose and identity status');
  await shot('security-scan');
  }
  await go(1,'resident/parking.php?tab=requests');
  const parkingUrl=await ev("[...document.querySelectorAll('.booking-item')].find(row=>row.textContent.includes('Browser Parking Guest')).querySelector('a[href*=parking_pass]').href");
  await go(1,parkingUrl.slice(base.length));
  await check("!!document.querySelector('#qrCode canvas')&&!document.getElementById('downloadPass').disabled&&document.getElementById('passQrFallback').hidden",'Approved parking pass opens and enables download without the external CDN');
  const parkingImages=[];
  for(const width of [1366,390,320]){
   await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<1024});
   for(const theme of ['dark','light']){
    await ev('CelandineTheme.set('+JSON.stringify(theme)+');document.getElementById("qrCode").scrollIntoView({block:"center"})');await sleep(350);
    await check("document.documentElement.scrollWidth<=innerWidth+2&&getComputedStyle(document.getElementById('qrCode')).backgroundColor==='rgb(255, 255, 255)'",'Parking pass fits '+width+'px in '+theme);
    const clip=await ev("(()=>{const r=document.getElementById('qrCode').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,scale:1};})()");
    const picture=await send('Page.captureScreenshot',{format:'png',clip});parkingImages.push('data:image/png;base64,'+picture.data);
   }
  }
  const download=await ev("(()=>{const original=HTMLAnchorElement.prototype.click;let result;try{HTMLAnchorElement.prototype.click=function(){result={name:this.download,image:this.href};};document.getElementById('downloadPass').click();return result;}finally{HTMLAnchorElement.prototype.click=original;}})()");
  await check(JSON.stringify(download.name)+".startsWith('visitor-parking-pass-')&&"+JSON.stringify(download.image)+".startsWith('data:image/png;base64,')",'Download generates the existing PNG pass');
  parkingImages.push(download.image);
  await go(5,'security/scanner.php');await until("typeof Html5Qrcode==='function'");
  for(const [index,image] of parkingImages.entries()) await check("(async()=>await new Html5Qrcode('reader').scanFile(new File([await(await fetch("+JSON.stringify(image)+")).blob()],'parking-pass.png',{type:'image/png'}),false)==="+JSON.stringify(parkingUrl)+")()",'Parking QR decodes from screen/download '+index);
  if (process.argv.includes('--parking-only')) {
   if(report.errors.length)throw Error(report.errors.join('\n'));
   console.log('Passed '+report.checks.length+' visitor parking browser checks. Screenshots: '+directory);return;
  }
  await send('Emulation.setDeviceMetricsOverride',{width:390,height:1000,deviceScaleFactor:1,mobile:true});
  await go(5,'superadmin/service_requests.php?kind=visitor');
  await check("document.documentElement.scrollWidth<=innerWidth+2 && !!document.querySelector('.visitor-identity-review')",'Security identity review fits mobile');
  await go(6,'superadmin/service_requests.php?kind=visitor&tab=history');
  await check("!document.querySelector('#visitor-panel-history').hidden && document.querySelector('#visitor-panel-requests').hidden && document.querySelector('#visitor-panel-history').textContent.includes('Checked out')",'Superadmin opens visitor history directly and sees completed visits');
  await go(4,'superadmin/service_requests.php?kind=visitor');
  await ev("const row=[...document.querySelectorAll('#visitor-panel-requests .service-request')].find(row=>row.textContent.includes('Browser Identity Guest'));window.residentUiNavigating=true;row.querySelector('[name=decision][value=rejected]').click()");
  await until("!window.residentUiNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
  await check("!document.querySelector('#visitor-panel-history').hidden && document.querySelector('#visitor-panel-history').textContent.includes('Browser Identity Guest') && !document.querySelector('#visitor-panel-requests').textContent.includes('Browser Identity Guest')",'Rejected visitor moves to History and opens the admin History tab');
  await go(1,'resident/visitors.php?tab=requests');
  await ev("const row=[...document.querySelectorAll('#visitor-panel-requests .service-request')].find(row=>row.textContent.includes(window.visitorFixtureName));row.querySelector('[name=action][value=cancel]').form.requestSubmit()");
  await until("!!document.querySelector('.system-confirm-accept')");
  await ev("window.residentUiNavigating=true;document.querySelector('.system-confirm-accept').click()");
  await until("!window.residentUiNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
  await check("!document.querySelector('#visitor-panel-history').hidden && document.querySelector('#visitor-panel-history').textContent.includes(window.visitorFixtureName) && !document.querySelector('#visitor-panel-requests').textContent.includes(window.visitorFixtureName)",'Cancelled visitor moves to History and opens the resident History tab');
  await shot('resident-history-mobile');
  if(report.errors.length)throw Error(report.errors.join('\n'));
  console.log('Passed '+report.checks.length+' visitor identity browser checks. Screenshots: '+directory);
 }finally{fs.writeFileSync(path.join(directory,'report.json'),JSON.stringify(report,null,2));socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
