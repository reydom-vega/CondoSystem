/* Read-only guest browser checks. No login submissions or database fixtures. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/(?:127\.0\.0\.1|localhost)(?::\d+)?\/[^?#]*$/.test(base||'')||!base.endsWith('/'))throw Error('Pass a localhost application base URL ending in /.');
(async()=>{
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'celandine-intro-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[];
 try {
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let seq=0;const calls=new Map();socket.onmessage=e=>{const m=JSON.parse(e.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout '+method));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<200;i++){if(await ev(expression))return;await sleep(75);}throw Error('Timed out '+expression+'\n'+await ev('document.body.innerText'));};
  const shot=async name=>{const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const check=async(expression,label)=>{if(!await ev(expression)){await shot('failure');throw Error(label);}checks++;};
  const key=async(key,code,virtualKey)=>{await send('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode:virtualKey});await send('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode:virtualKey});};
  const skipHidden="document.querySelector('.skip-link').getBoundingClientRect().bottom<0&&!document.activeElement.matches('.skip-link')";
  const centered="(()=>{const svg=document.querySelector('.cel-mark'),halo=document.querySelector('.cel-intro-halo').getBoundingClientRect(),coords=[...svg.querySelectorAll('path')].flatMap(p=>p.getAttribute('d').match(/-?\\d+(?:\\.\\d+)?/g).map(Number)),xs=coords.filter((_,i)=>i%2===0),ys=coords.filter((_,i)=>i%2===1),center=new DOMPoint((Math.min(...xs)+Math.max(...xs))/2,(Math.min(...ys)+Math.max(...ys))/2).matrixTransform(svg.getScreenCTM());return Math.abs(center.x-(halo.left+halo.width/2))<.5&&Math.abs(center.y-(halo.top+halo.height/2))<.5&&Math.abs(halo.width-halo.height)<.5&&svg.viewBox.baseVal.width===svg.viewBox.baseVal.height;})()";
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  const external=['*fonts.googleapis.com*','*fonts.gstatic.com*','*www.google.com/maps/*'];
  await send('Network.setBlockedURLs',{urls:external});
  async function fresh({width=1366,height=900,reduced=false,theme='dark',blocked=[],storage=false}={}){
    await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<600});
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:reduced?'reduce':'no-preference'}]});
    await send('Network.setBlockedURLs',{urls:[...external,...blocked]});
    const source=`try{sessionStorage.removeItem('celandineIntroSeenV1');localStorage.setItem('condo-theme','${theme}');}catch(_){} ${storage?"const originalGet=Storage.prototype.getItem,originalSet=Storage.prototype.setItem;Storage.prototype.getItem=function(k){if(this===sessionStorage&&k==='celandineIntroSeenV1')throw new DOMException('Blocked','SecurityError');return originalGet.call(this,k);};Storage.prototype.setItem=function(k,v){if(this===sessionStorage&&k==='celandineIntroSeenV1')throw new DOMException('Blocked','SecurityError');return originalSet.call(this,k,v);};":''}`;
    const init=await send('Page.addScriptToEvaluateOnNewDocument',{source});
    await send('Page.navigate',{url:base+'homepage.php'});
    await until("document.querySelectorAll('#cel-intro .cel-petal').length===32&&document.readyState==='complete'");
    await send('Page.removeScriptToEvaluateOnNewDocument',{identifier:init.identifier});
  }
  const idle="document.querySelector('#cel-intro').hidden&&!document.documentElement.hasAttribute('data-cel-intro')";
  const usable="![...document.body.children].some(el=>el.inert)&&getComputedStyle(document.documentElement).overflowY!=='hidden'";
  await fresh();
  await check("document.documentElement.dataset.celIntro==='playing'&&!document.querySelector('#cel-intro').hidden",'first tab visit starts intro');
  await check("sessionStorage.getItem('celandineIntroSeenV1')==='1'",'session marked at start, including reload during animation');
  await check("!document.querySelector('#cel-intro audio,#cel-intro-entry,#cel-enter-sound,#cel-enter-silent,#cel-mute,#cel-intro-status')",'silent intro has no narration element or sound choice controls');
  await check("document.querySelector('main').inert&&document.activeElement.id==='cel-skip'",'background disabled and Skip focused during intro');
  await check("document.querySelectorAll('script[src*=gsap]').length===1&&document.querySelector('script[src*=gsap]').src.includes('/assets/vendor/gsap/')",'one existing local GSAP import');
  await check(`[...document.querySelectorAll('link[href*=celandine-intro],script[src*=celandine-intro]')].every(el=>new URL(el.href||el.src).href.startsWith(${JSON.stringify(base)}))`,'intro assets use configured application base');
  await until("Number(getComputedStyle(document.querySelector('.cel-intro-wordmark')).opacity)>.9");
  await ev('void gsap.globalTimeline.pause()');
  await check("[...document.querySelectorAll('.cel-petal')].every(p=>p.hasAttribute('data-svg-origin')&&Number(getComputedStyle(p).opacity)>.95)",'32 individual SVG petals bloom with explicit origins');
  await check(centered,'flower artwork centered in circular halo on desktop');
  await shot('desktop-radial-bloom');await ev('void gsap.globalTimeline.resume()');
  await until(idle);await check(usable,'automatic completion releases page and scroll');
  await check(skipHidden,'automatic completion does not reveal or focus the skip link');
  await key('Tab','Tab',9);
  await check("document.activeElement.matches('.skip-link')&&document.activeElement.getBoundingClientRect().top>=0",'first keyboard Tab reveals the accessibility skip link');
  await key('Enter','Enter',13);
  await check("document.activeElement.id==='main-content'&&document.querySelector('.skip-link').getBoundingClientRect().bottom<0",'skip link bypasses navigation and focuses main content');
  await send('Page.reload',{ignoreCache:true});await until("document.readyState==='complete'");await check(idle,'refresh in same tab does not replay');
  await check("document.querySelectorAll('main section[id]').length>=7&&!!document.querySelector('#interiorGallery')",'existing homepage sections and gallery preserved');
  await ev("document.querySelector('.nav-cta a[href=\"login.php\"]').click()");await until("location.pathname.endsWith('/login.php')&&document.readyState==='complete'");
  await check("!document.querySelector('#cel-intro')&&!!document.querySelector('form')",'login navigation remains normal and has no intro');
  await send('Page.navigate',{url:base+'homepage.php'});await until("document.readyState==='complete'");await check(idle,'returning from login does not replay');
  await ev("document.querySelector('.nav-cta a[href=\"signup.php\"]').click()");await until("location.pathname.endsWith('/signup.php')&&document.readyState==='complete'");await check("!document.querySelector('#cel-intro')&&!!document.querySelector('form')",'signup navigation remains normal');

  for(const [width,height,theme] of [[390,844,'light'],[768,1024,'dark'],[844,390,'dark']]){
    await fresh({width,height,theme});
    await check("document.documentElement.dataset.celIntro==='playing'",'new tab-session simulation starts intro '+width);
    await check("(()=>{const s=document.querySelector('#cel-skip').getBoundingClientRect(),r=document.querySelector('#cel-intro').getBoundingClientRect();return s.top>=0&&s.right<=innerWidth&&r.width===innerWidth&&r.height===innerHeight&&document.documentElement.scrollWidth<=innerWidth+1;})()",'responsive full-screen intro and upper-right Skip '+width);
    await check(centered,'flower artwork centered in circular halo '+width);
    if(width===390){await until("Number(getComputedStyle(document.querySelector('.cel-intro-wordmark')).opacity)>.9");await ev('void gsap.globalTimeline.pause()');await shot('mobile-radial-bloom');await ev('void gsap.globalTimeline.resume()');}
    await ev("document.querySelector('#cel-skip').click()");await check(idle,'Skip dismisses intro '+width);await check(usable,'Skip releases navigation and scroll '+width);
    await check(skipHidden,'Skip Intro does not reveal the accessibility link '+width);
  }
  await ev("document.querySelector('#navToggle').click()");await check("document.querySelector('#mobileMenu').classList.contains('open')",'mobile menu opens after intro');
  await ev("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");await check("!document.querySelector('#mobileMenu').classList.contains('open')",'existing mobile Escape handler still works');
  await fresh();await ev("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");await check(idle,'Escape skips intro');
  await fresh();await ev("window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));");await check(idle+'&&'+usable,'back-forward cache cleanup leaves no trapped overlay');
  await fresh({reduced:true});await check(idle+'&&'+usable,'reduced motion bypasses animation');
  await fresh({storage:true});await check(idle+'&&'+usable,'blocked sessionStorage leaves homepage usable');
  await fresh({blocked:['*assets/vendor/gsap/gsap.min.js*']});await check(idle+'&&'+usable,'missing GSAP fails open');
  await fresh({blocked:['*assets/js/celandine-intro.js*']});await check(idle+'&&'+usable,'blocked intro JavaScript fails open');
  await fresh({blocked:['*assets/css/celandine-intro.css*']});await check(idle+'&&'+usable,'missing intro stylesheet fails open');
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
