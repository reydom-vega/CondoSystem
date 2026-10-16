/* Read-only layout audit; staff routes require disposable UI fixtures. */
const {spawn}=require('node:child_process');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const net=require('node:net');
const base=process.argv[2];
const hosted=process.argv.includes('--hosted');
if(hosted ? base!=='https://thecelandinesystem.online/' : !/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||'')) throw Error('Use the disposable UI fixture URL or the exact approved public domain with --hosted.');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const port=await new Promise(r=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>r(p));});});
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'celandine-hostinger-browser-'));
 const out=path.resolve(hosted?'inventory/hostinger-public-browser':'inventory/hostinger-browser');fs.mkdirSync(out,{recursive:true});
 const chrome=spawn(process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket;const reportFile=path.join(out,'results.json');
 const report=process.argv.includes('--resume')&&fs.existsSync(reportFile)?JSON.parse(fs.readFileSync(reportFile,'utf8')):{started:new Date().toISOString(),base,pages:[],failures:[],screenshots:[]};
 if(report.base!==base)throw Error('Resume requires the same disposable fixture.');
 const completed=new Set(report.pages.map(p=>p.actor+':'+p.path));
 try{
  let target;
  for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome did not start');
  socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(r=>socket.onopen=r);
  let seq=0;const calls=new Map();let errors=[],resources=[];
  socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}}
   if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
   if(m.method==='Network.responseReceived'){const r=m.params.response;if(r.status>=400&&r.url.startsWith(base))resources.push({path:new URL(r.url).pathname,status:r.status});}
  };
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error(method+' timed out'));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  const manifest=hosted?['homepage.php','login.php','signup.php'].map(path=>({path,actor:0,label:'Hosted guest'})):await(await fetch(base+'__manifest')).json();
  for(const [index,entry]of manifest.entries()){
   if(completed.has(entry.actor+':'+entry.path))continue;
   errors=[];resources=[];
   await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
   await send('Page.navigate',{url:base+entry.path+(hosted?'':(entry.path.includes('?')?'&':'?')+'fixture_actor='+entry.actor)});
   let ready=false;for(let i=0;i<600;i++){if(await ev(hosted?"document.readyState==='complete' && !!document.body":"document.readyState==='complete' && !!window.CelandineUi && !!window.CelandineTheme")){ready=true;break;}await sleep(100);}
   if(!ready)throw Error('Page did not become ready: '+entry.path+' '+JSON.stringify(await ev("({path:location.pathname,title:document.title,ui:!!window.CelandineUi,theme:!!window.CelandineTheme})"))+' '+JSON.stringify({errors,resources}));
   await sleep(180);
   for(const theme of ['dark','light']){
    await ev(`window.CelandineTheme?.set('${theme}')`);
    for(const width of [360,390,430,768,1024,1366,1920]){
     await send('Emulation.setDeviceMetricsOverride',{width,height:width<768?844:900,deviceScaleFactor:1,mobile:width<768});
     await ev('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))');await sleep(70);
     const metrics=await ev(`(()=>{const main=document.querySelector('.dashboard-main,main,.card,.pending-card,.rejected-card');return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme,mainVisible:!!main&&getComputedStyle(main).visibility!=='hidden'&&Number(getComputedStyle(main).opacity)>0,gsapImports:[...document.scripts].filter(s=>s.src.includes('/gsap.min.js')).length,brokenImages:[...document.images].filter(i=>i.getClientRects().length&&i.complete&&!i.naturalWidth).map(i=>i.getAttribute('src')),mixedImages:[...document.images].filter(i=>i.currentSrc.startsWith('http:')&&!i.currentSrc.startsWith(location.origin)).map(i=>i.getAttribute('src'))};})()`);
     const row={path:entry.path,actor:entry.actor,label:entry.label,requestedTheme:theme,...metrics,errors:[...errors],resources:[...resources]};report.pages.push(row);
     const failed=metrics.scrollWidth>metrics.width+2||!metrics.mainVisible||metrics.theme!==theme||metrics.gsapImports!==1||metrics.brokenImages.length||metrics.mixedImages.length||errors.length||resources.some(r=>r.status===404||r.status>=500);
     if(failed)report.failures.push(row);
     const sample=/dashboard\.php$|scanner\.php$|login\.php$|signup\.php$/.test(entry.path)&&[390,1366].includes(width);
     if(sample||failed&&width===360){const name=`${index}-${entry.actor}-${width}-${theme}.png`;const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,name),Buffer.from(shot.data,'base64'));report.screenshots.push({path:entry.path,actor:entry.actor,width,theme,file:name});}
    }
   }
   fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(report,null,2));
   if(index%10===0)console.log(`Reviewed ${index+1}/${manifest.length} pages; ${report.pages.length} layouts; ${report.failures.length} findings.`);
  }
  report.finished=new Date().toISOString();fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify({layouts:report.pages.length,findings:report.failures.length,screenshots:report.screenshots.length}));if(report.failures.length)process.exitCode=1;
 }finally{if(socket)socket.close();chrome.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
