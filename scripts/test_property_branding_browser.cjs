/* Run only against test_ui_redesign.php --preview --property-documents-only. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable document fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.label==='Paid visitor parking receipt PDF'))throw Error('Property document fixture unavailable.');
 const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-property-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[];
 try{
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab=>tab.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let number=0;const pending=new Map();
  socket.onmessage=event=>{const message=JSON.parse(event.data),call=pending.get(message.id);if(call){pending.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++number,timer=setTimeout(()=>{pending.delete(id);reject(Error('Timeout '+method));},30000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const until=async expression=>{for(let i=0;i<150;i++){if(await ev(expression))return;await sleep(100);}throw Error('Timed out '+expression);};
  const check=async(expression,label)=>{if(!await ev(expression))throw Error(label);checks++;};
  const go=async(actor,route)=>{await ev('window.propertyNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.propertyNavigating&&document.readyState==='complete'&&!!window.CelandineTheme");await sleep(300);};
  const shot=async name=>{const metrics=await send('Page.getLayoutMetrics');const result=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:metrics.cssContentSize.width,height:metrics.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
  await send('Page.enable');await send('Runtime.enable');await send('Page.setDownloadBehavior',{behavior:'allow',downloadPath:directory});
  const address='1115-A A. Bonifacio Ave., Brgy. Balintawak, Quezon City';
  const passes=manifest.filter(entry=>entry.path.startsWith('resident_service_pass.php')||entry.path.startsWith('parking_pass.php'));
  for(const [index,entry] of passes.entries()){
   await go(entry.actor,entry.path);
   for(const theme of ['dark','light'])for(const width of [1366,390]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:false});await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);await sleep(200);
    await check(`document.querySelector('.celandine-property-address').textContent===${JSON.stringify(address)}`,'Pass '+index+' complete address '+theme+'/'+width);
    await check("document.documentElement.scrollWidth<=innerWidth+1",'Pass '+index+' fits viewport '+theme+'/'+width);
    await check(`document.querySelector('.celandine-brand-emblem').src.endsWith(${JSON.stringify(theme==='light'?'celandineorange.svg':'celandine.svg')})`,'Pass original theme logo '+index+'/'+theme+'/'+width);
    await shot('pass-'+index+'-'+theme+'-'+width);
   }
   await ev("CelandineTheme.set('dark')");
   await send('Emulation.setEmulatedMedia',{media:'print'});await check("getComputedStyle(document.querySelector('.celandine-property-address')).color==='rgb(88, 101, 122)'",'Printed pass address uses readable paper color');
   await check("getComputedStyle(document.body).backgroundColor==='rgb(255, 255, 255)'",'Pass prints on white paper while screen is dark');
   await check("getComputedStyle(document.documentElement).backgroundColor==='rgb(255, 255, 255)'",'Printed page margins remain white');
   await check("getComputedStyle(document.documentElement).colorScheme==='light'&&getComputedStyle(document.body).colorScheme==='light'",'Browser print canvas uses light color scheme');
   const printed=await send('Page.printToPDF',{printBackground:true,preferCSSPageSize:true});fs.writeFileSync(path.join(directory,'printed-pass-'+index+'.pdf'),Buffer.from(printed.data,'base64'));
   if((Buffer.from(printed.data,'base64').toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length!==1)throw Error('Short pass splits onto an unnecessary second page');checks++;
   await send('Emulation.setEmulatedMedia',{media:''});await sleep(250);
   if(entry.path.startsWith('parking_pass.php')){
    await until("!!document.querySelector('#qrCode canvas')&&!document.querySelector('#downloadPass').disabled");
    const file=await ev("document.getElementById('downloadPass').dataset.downloadName");await ev("document.getElementById('downloadPass').click()");
    for(let i=0;i<100&&!fs.existsSync(path.join(directory,file));i++)await sleep(100);
    if(!fs.existsSync(path.join(directory,file)))throw Error('Parking image was not downloaded');
    const bytes=fs.readFileSync(path.join(directory,file));if(bytes.subarray(1,4).toString()!=='PNG')throw Error('Invalid parking PNG');checks++;
    await check(`(async()=>{const image=new Image();image.src=${JSON.stringify('data:image/png;base64,'+bytes.toString('base64'))};await image.decode();const copy=document.createElement('canvas');copy.width=image.width;copy.height=image.height;const ctx=copy.getContext('2d');ctx.drawImage(image,0,0);const qr=document.querySelector('#qrCode canvas'),expected=qr.getContext('2d').getImageData(0,0,qr.width,qr.height).data,actual=ctx.getImageData(Math.round((image.width-qr.width)/2),160,qr.width,qr.height).data;return expected.every((pixel,index)=>pixel===actual[index]);})()`,'Parking image preserves every QR pixel outside property header');
    await check("!document.getElementById('downloadPass').disabled",'Parking download control recovers');
   }
  }
  await go(1,passes[0].path);
  for(const entry of manifest.filter(entry=>entry.path.startsWith('resident/payment_receipt.php'))){
   const receipt=await ev(`(async()=>{const response=await fetch(${JSON.stringify(new URL(entry.path,base).href)},{cache:'no-store'});const bytes=new Uint8Array(await response.arrayBuffer());let binary='';for(let i=0;i<bytes.length;i+=8192)binary+=String.fromCharCode(...bytes.subarray(i,i+8192));return {status:response.status,type:response.headers.get('content-type'),data:btoa(binary)};})()`);
   if(receipt.status!==200||!receipt.type.includes('application/pdf'))throw Error('Paid receipt unavailable');checks++;
   fs.writeFileSync(path.join(directory,entry.label.includes('parking')?'parking-receipt.pdf':'billing-receipt.pdf'),Buffer.from(receipt.data,'base64'));
  }
  for(const actor of [4,5,6,7,8]){
   await go(actor,'job_report.php');await until("document.querySelector('[data-job-reports]').getAttribute('aria-busy')==='false'");
   await ev("document.querySelector('[data-jr-preview]').click()");await until("document.querySelector('[data-jr-dialog]').open&&!!document.querySelector('[data-jr-document] .celandine-property-address')");
   for(const theme of ['dark','light']){
    await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);await sleep(200);
    await check(`document.querySelector('[data-jr-document] .celandine-property-address').textContent===${JSON.stringify(address)}`,'Staff '+actor+' preview address '+theme);
    await check(`document.querySelector('[data-jr-document] .celandine-brand-emblem').src.endsWith(${JSON.stringify(theme==='light'?'celandineorange.svg':'celandine.svg')})`,'Staff '+actor+' preview logo '+theme);
   }
   await shot('job-preview-'+actor);await ev("document.querySelector('[data-jr-close]').click()");
  }
  if(errors.length)throw Error(errors.join('\n'));
  console.log('Passed '+checks+' property document preview, theme, responsive, print and download checks. Files: '+directory);
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
