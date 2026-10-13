/* Loopback presentation fixture using the real PHP shared head; no database requests. */
const {spawn,execFileSync}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),http=require('node:http'),net=require('node:net'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),sleep=ms=>new Promise(r=>setTimeout(r,ms));
const roles=['resident','tenant','admin','superadmin','security','treasurer','maintenance','public'];
const hashes=()=>['celandine.svg','celandineorange.svg'].map(file=>crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex'));
(async()=>{
 const beforeHashes=hashes(),heads=new Map(),nativeRequests=[];
 let base;
 const head=role=>{
  if(heads.has(role))return heads.get(role);
  const sessionRole=role==='tenant'?'resident':role;
  const route=role==='security'?'security/security_dashboard.php':role==='resident'||role==='tenant'?'resident/dashboard.php':'superadmin/admin_dashboard.php';
  const code="require "+JSON.stringify(path.join(root,'config.php').replaceAll('\\','/'))+"; $_SESSION="+(role==='public'?"[]":"['user_id'=>1,'role'=>'"+sessionRole+"','account_type'=>'"+(role==='tenant'?'Tenant':'Resident Owner')+"']")+"; $_SERVER['SCRIPT_FILENAME']="+JSON.stringify(path.join(root,route).replaceAll('\\','/'))+"; renderPortalUiHead(); echo '<template data-fixture-brand>'.brandHtml().'</template>';";
  const html=execFileSync('C:/xampp/php/php.exe',['-r',code],{env:{...process.env,CONDO_APP_ENV:'test',CONDO_APP_URL:base.slice(0,-1)},encoding:'utf8'});
  heads.set(role,html);return html;
 };
 const page=role=>'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="'+(role==='resident'||role==='tenant'?'resident.css':role==='security'?'security.css':'styles.css')+'">'+head(role)+'</head><body class="portal-ui dashboard-page"><main class="dashboard-main"><header class="dash-header"><div class="dash-header-left"><h1 class="dash-title">Loading fixture</h1></div><div class="dash-header-right"><div class="notification-menu"><button class="btn-icon-nav" type="button">Notifications</button></div><div class="profile-menu" id="profileMenu"><button id="profileToggle" class="btn-profile-nav" type="button">Profile</button></div></div></header><section class="service-panel"><h2>Records</h2><a id="slowLink" href="slow.php?role='+role+'">Open dashboard</a><a id="tabLink" href="fixture.php?tab=second">Switch tab</a><form id="nativeForm" method="post" action="slow.php?role='+role+'"><input name="reference" value="fixture-reference"><button name="action" value="continue" type="submit">Submit request</button></form><form id="ajaxForm"><button type="submit">Save request</button></form><button id="operationButton" type="button">Load records</button><div id="testRegion"></div></section></main></body></html>';
 const server=http.createServer(async(req,res)=>{
  try{
   const url=new URL(req.url,base),relative=decodeURIComponent(url.pathname).replace(/^\/CondoSystem3\//,'');
   if(['fixture.php','slow.php'].includes(relative)){
    const role=roles.includes(url.searchParams.get('role'))?url.searchParams.get('role'):'resident';
    let body='';for await(const chunk of req)body+=chunk;nativeRequests.push({path:relative,method:req.method,body});
    if(relative==='slow.php')await sleep(2200);
    res.writeHead(200,{'Content-Type':'text/html','Cache-Control':'no-store'});res.end(page(role));return;
   }
   if(relative==='background.json'){await sleep(700);res.writeHead(200,{'Content-Type':'application/json'});res.end('{"success":true}');return;}
   if(!/^(?:assets\/(?:css|js|fonts|branding|vendor)\/[\w./-]+|js\/[\w.-]+|(?:styles|resident|security)\.css|celandine(?:orange|-favicon)?\.svg)$/.test(relative)){res.writeHead(404);res.end();return;}
   const file=path.resolve(root,relative);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
   const type={'.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.ttf':'font/ttf','.png':'image/png'}[path.extname(file)]||'application/octet-stream';
   res.writeHead(200,{'Content-Type':type,'Cache-Control':'public,max-age=3600'});res.end(fs.readFileSync(file));
  }catch(error){res.writeHead(500);res.end('Fixture failed.');console.error(error);}
 });
 await new Promise(r=>server.listen(0,'127.0.0.1',r));base='http://127.0.0.1:'+server.address().port+'/CondoSystem3/';
 const port=await new Promise(r=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>r(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-loading-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox','--remote-debugging-port='+port,'--user-data-dir='+directory,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;
 try{
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch('http://127.0.0.1:'+port+'/json')).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(r=>socket.onopen=r);
  let seq=0;const calls=new Map(),errors=[],badResources=[];
  socket.onmessage=e=>{const m=JSON.parse(e.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&m.params.response.url.startsWith(base))badResources.push(m.params.response.url);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('CDP timeout '+method));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async(expression,timeout=10000)=>{const end=Date.now()+timeout;while(Date.now()<end){if(await ev(expression))return;await sleep(50);}console.log(await ev("({href:location.href,ready:document.readyState,loader:!!document.querySelector('.portal-loading-overlay'),hidden:document.querySelector('.portal-loading-overlay')?.hidden,api:typeof CelandineLoading})"));console.log({nativeRequests,errors,badResources});throw Error('Timed out '+expression);};
  const shot=async name=>{const r=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const check=async(expression,label)=>{if(!await ev('(async()=>('+expression+'))()')){await shot('failure');throw Error(label);}checks++;};
  const go=async role=>{await ev('window.loadingNavigating=true');await send('Page.navigate',{url:base+'fixture.php?role='+role});await until("!window.loadingNavigating && document.readyState==='complete' && !!window.CelandineUi && !!document.querySelector('.portal-loading-overlay')");await sleep(70);};
  const start=async options=>ev('window.testLoading=CelandineLoading.begin('+options+');void 0');
  const stop=async()=>ev('window.testLoading.end()');
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  await send('Page.addScriptToEvaluateOnNewDocument',{source:"new MutationObserver(function(){if(document.body&&!window.themeBeforeLoadingContent){window.themeBeforeLoadingContent=document.documentElement.dataset.theme;this.disconnect();}}).observe(document,{subtree:true,childList:true});"});
  if (process.argv.includes('--branding-only')) {
   await send('Emulation.setDeviceMetricsOverride',{width:1600,height:500,deviceScaleFactor:1,mobile:false});
   await go('resident');
   await ev("document.body.replaceChildren(document.querySelector('template[data-fixture-brand]').content.cloneNode(true));document.body.className='';document.body.style.cssText='margin:0;padding:0;background:transparent';document.documentElement.style.background='transparent';const style=document.createElement('style');style.textContent='body::before,body::after{display:none!important}';document.head.append(style);document.querySelector('.celandine-brand').style.cssText='--brand-height:120px;--brand-type:65px;--brand-gap:20px';");
   await send('Emulation.setDefaultBackgroundColorOverride',{color:{r:0,g:0,b:0,a:0}});
   await ev("document.fonts.load('80px CelandineRoman')");
   fs.mkdirSync(path.join(root,'assets/branding'),{recursive:true});
   for(const theme of ['dark','light']) {
    await ev('CelandineTheme.set('+JSON.stringify(theme)+')');
    await until("document.querySelector('.celandine-brand-emblem').complete&&document.querySelector('.celandine-brand-emblem').naturalWidth>0");
    await check("document.fonts.check('80px CelandineRoman')&&getComputedStyle(document.querySelector('.celandine-wordmark')).fontFamily.includes('CelandineRoman')",'Local licensed brand font loaded');
    await check("(()=>{const b=document.querySelector('.celandine-brand').getBoundingClientRect(),w=document.querySelector('.celandine-wordmark').getBoundingClientRect();return w.right<=b.right+.5;})()",'Email wordmark fits its captured bounds');
    const clip=await ev("(()=>{const r=document.querySelector('.celandine-brand').getBoundingClientRect();return {x:r.x,y:r.y,width:Math.ceil(r.width),height:Math.ceil(r.height),scale:1};})()");
    const capture=await send('Page.captureScreenshot',{format:'png',clip,captureBeyondViewport:true});
    fs.writeFileSync(path.join(root,'assets/branding/email-brand-'+theme+'.png'),Buffer.from(capture.data,'base64'));
   }
   assert.deepEqual(hashes(),beforeHashes);
   if(errors.length||badResources.length)throw Error(JSON.stringify({errors,badResources}));
   console.log('Generated email headers from the shared component; original SVG assets unchanged.');return;
  }
  if(!process.argv.includes('--interactions-only')) for(const mobile of [false,true]){
   await send('Emulation.setDeviceMetricsOverride',{width:mobile?390:1366,height:mobile?844:900,deviceScaleFactor:1,mobile});
   for(const role of roles){
    await go(role);
    await check("document.querySelectorAll('.portal-loading-overlay').length===1 && document.querySelector('.portal-loading-overlay').hidden",'One hidden loader on initial page: '+role);
    for(const theme of ['dark','light']){
     await ev('CelandineTheme.set('+JSON.stringify(theme)+')');
     await start('{fullscreen:true}');await sleep(120);
     await check("document.querySelector('.portal-loading-overlay').hidden",'Fast requests do not flash');
     await stop();await sleep(350);
     await check("document.querySelector('.portal-loading-overlay').hidden",'Delayed appearance is canceled on completion');
     await start('{fullscreen:true,delay:0}');
     await until("!document.querySelector('.portal-loading-overlay').hidden && document.querySelector('.portal-loading-logo').dataset.ready==='true'");
     await check("document.querySelector('.portal-loading-logo').getAttribute('src')===document.querySelector('meta[name=\"loading-logo-"+theme+"\"]').content",'Official logo for '+role+' '+theme);
     await check("document.querySelectorAll('.portal-loading-emblem img').length===1 && getComputedStyle(document.querySelector('.portal-loading-logo')).filter==='none' && getComputedStyle(document.querySelector('.portal-loading-logo')).transform==='none'",'Single stationary logo without filters');
     await check("getComputedStyle(document.querySelector('.portal-loading-ring')).animationName==='portal-loading-turn' && getComputedStyle(document.querySelector('.portal-loading-ring')).animationDuration==='1.1s'",'Separate rotating ring');
     await check("document.querySelectorAll('link[rel=preload][as=image]').length===2 && document.querySelector('.portal-loading-message').textContent==='Preparing your dashboard...'",'Preloading and loading label');
     await check("(()=>{const r=document.querySelector('.portal-loading-content').getBoundingClientRect();return Math.abs(r.x+r.width/2-innerWidth/2)<1 && Math.abs(r.y+r.height/2-innerHeight/2)<1 && document.documentElement.scrollWidth<=innerWidth;})()",'Centered responsive loader');
     if(role==='resident')await shot((mobile?'mobile-':'desktop-')+theme);
     await stop();await check("document.querySelector('.portal-loading-overlay').hidden",'Cleanup after completion');
    }
   }
  }
  if(process.argv.includes('--interactions-only')) { await go('resident');await ev("CelandineTheme.set('light')"); }
  await go('resident');
  await check("(()=>{CelandineTheme.set('dark');const style=document.createElement('style');style.textContent='#themeTransitionProbe,#themeTransitionProbe::before{background:var(--surface-card,#1a1e2b);color:var(--text-primary,#fff);border:1px solid var(--border-color,#343d50);transition:background-color 2s,color 2s,border-color 2s}#themeTransitionProbe::before{content:\"Theme\";display:block}';document.head.append(style);const probe=document.createElement('div');probe.id='themeTransitionProbe';document.body.append(probe);getComputedStyle(probe).backgroundColor;window.themeProbeMatches=theme=>{const expected=theme==='light'?['rgb(255, 255, 255)','rgb(26, 34, 51)','rgb(199, 208, 221)']:['rgb(26, 30, 43)','rgb(255, 255, 255)','rgb(52, 61, 80)'];return [null,'::before'].every(pseudo=>{const css=getComputedStyle(probe,pseudo);return [css.backgroundColor,css.color,css.borderTopColor].every((value,i)=>value===expected[i]);})&&!document.documentElement.classList.contains('theme-changing')&&getComputedStyle(document.querySelector(theme==='light'?'.theme-moon':'.theme-sun')).opacity==='1';};CelandineTheme.set('light');return themeProbeMatches('light');})()", 'API theme change applies final colors and icon synchronously, including legacy transitions and pseudo elements');
  await check("(()=>{document.querySelector('[data-theme-toggle]').click();return themeProbeMatches('dark')&&localStorage.getItem('condo-theme')==='dark';})()", 'Toggle switches to dark immediately and saves preference');
  await check("(()=>{for(let i=0;i<10;i++){const theme=i%2?'dark':'light';CelandineTheme.set(theme);if(!themeProbeMatches(theme))return false;}return getComputedStyle(document.getElementById('themeTransitionProbe')).transitionDuration.split(',').every(value=>value.trim()==='2s');})()", 'Rapid switching stays immediate and restores normal component transitions');
  await check("(()=>{window.dispatchEvent(new StorageEvent('storage',{key:'condo-theme',newValue:'light',storageArea:localStorage}));return themeProbeMatches('light');})()", 'Cross-tab preference updates apply immediately');
  await ev("document.getElementById('themeTransitionProbe').remove();CelandineTheme.set('light')");
  await go('resident');await check("document.documentElement.dataset.theme==='light' && window.themeBeforeLoadingContent==='light'",'Saved theme restored before content');
  await start('{fullscreen:true,delay:0}');await until("!document.querySelector('.portal-loading-overlay').hidden");
  await ev("CelandineTheme.set('dark')");await until("document.querySelector('.portal-loading-logo').src.includes('/celandine.svg') && document.querySelector('.portal-loading-logo').dataset.ready==='true'");
  await ev("CelandineTheme.set('light')");await until("document.querySelector('.portal-loading-logo').src.includes('/celandineorange.svg') && document.querySelector('.portal-loading-logo').dataset.ready==='true'");checks+=2;await stop();
  await ev("window.backgroundDone=false;fetch('background.json').then(()=>window.backgroundDone=true)");await sleep(500);
  await check("document.querySelector('.portal-loading-overlay').hidden",'Background fetch never triggers overlay');await until('window.backgroundDone');
  await ev("document.getElementById('tabLink').addEventListener('click',event=>event.preventDefault());document.getElementById('tabLink').click()");await sleep(500);
  await check("document.querySelector('.portal-loading-overlay').hidden",'Prevented tab navigation remains local');
  // Chrome can defer CDP evaluation while a native navigation is pending.
  // Observe the old document itself and inspect its snapshot after the response.
  const nativeObserver="sessionStorage.removeItem('loading-native-snapshot');new MutationObserver(()=>{const overlay=document.querySelector('.portal-loading-overlay');if(!overlay.hidden){const button=document.querySelector('#nativeForm button');sessionStorage.setItem('loading-native-snapshot',JSON.stringify({shown:true,buttonBusy:button.classList.contains('portal-button-loading'),buttonDisabled:button.disabled}));}}).observe(document.querySelector('.portal-loading-overlay'),{attributes:true,attributeFilter:['hidden']});";
  await ev(nativeObserver+"setTimeout(()=>document.getElementById('slowLink').click(),0)");
  await until("location.pathname.endsWith('/slow.php') && document.readyState==='complete' && document.querySelector('.portal-loading-overlay')?.hidden");checks++;
  await check("JSON.parse(sessionStorage.getItem('loading-native-snapshot')).shown",'Slow native navigation shows delayed feedback');
  await ev(nativeObserver+"window.nativeSubmitting=true;setTimeout(()=>document.getElementById('nativeForm').requestSubmit(document.querySelector('#nativeForm button')),0)");
  await until("!window.nativeSubmitting && document.readyState==='complete' && document.querySelector('.portal-loading-overlay')?.hidden && !document.querySelector('#nativeForm button').classList.contains('portal-button-loading')");
  await check("(()=>{const snapshot=JSON.parse(sessionStorage.getItem('loading-native-snapshot'));return snapshot.shown&&snapshot.buttonBusy&&!snapshot.buttonDisabled;})()",'Native submit feedback preserves submitter');
  assert(nativeRequests.some(r=>r.method==='POST'&&new URLSearchParams(r.body).get('action')==='continue'&&new URLSearchParams(r.body).get('reference')==='fixture-reference'));checks++;
  await ev("document.getElementById('ajaxForm').addEventListener('submit',event=>{event.preventDefault();const button=event.submitter;button.disabled=true;button.textContent='Saving...';setTimeout(()=>{button.disabled=false;button.textContent='Save request';},800);});document.getElementById('ajaxForm').requestSubmit(document.querySelector('#ajaxForm button'))");await sleep(350);
  await check("document.querySelector('#ajaxForm button').classList.contains('portal-button-loading') && document.querySelector('.portal-loading-overlay').hidden",'AJAX form gets button feedback only');
  await until("!document.querySelector('#ajaxForm button').disabled && !document.querySelector('#ajaxForm button').classList.contains('portal-button-loading')");checks++;
  await start("{button:document.getElementById('operationButton'),target:document.getElementById('testRegion'),delay:0}");
  await until("!!document.querySelector('#testRegion .portal-loading-skeleton')");
  await ev("window.secondLoading=CelandineLoading.begin({button:document.getElementById('operationButton'),target:document.getElementById('testRegion'),delay:0})");await sleep(50);await stop();
  await check("document.querySelector('#testRegion .portal-loading-skeleton') && document.getElementById('operationButton').classList.contains('portal-button-loading')",'Concurrent operations keep indicators until final completion');
  await ev("document.getElementById('testRegion').replaceChildren(document.createTextNode('Loaded records'));window.secondLoading.end()");
  await check("document.getElementById('testRegion').textContent==='Loaded records' && !document.getElementById('testRegion').hasAttribute('aria-busy') && !document.getElementById('operationButton').classList.contains('portal-button-loading')",'Cleanup preserves returned content and restores attributes');
  await ev("document.getElementById('testRegion').replaceChildren();document.getElementById('testRegion').setAttribute('aria-busy','true')");await until("!!document.querySelector('#testRegion .portal-loading-skeleton')");
  await ev("document.getElementById('testRegion').setAttribute('aria-busy','false')");await until("!document.querySelector('#testRegion .portal-loading-skeleton')");checks++;
  await start('{fullscreen:true,delay:0}');await sleep(5100);
  await check("document.querySelector('.portal-loading-message').textContent==='This is taking a little longer...'",'Slow request feedback updates');await stop();
  await start('{fullscreen:true,delay:0,timeout:1000}');await sleep(1200);
  await check("document.querySelector('.portal-loading-overlay').hidden && document.querySelector('.portal-loading-notice').textContent.includes('taking longer')",'Timeout removes blocking UI without retrying request');
  await start('{fullscreen:true,delay:0}');await sleep(50);await ev("window.testLoading.fail('Could not load records.')");
  await check("document.querySelector('.portal-loading-overlay').hidden && document.querySelector('.portal-loading-notice').getAttribute('role')==='alert'",'Errors release loading state');
  await start('{fullscreen:true,delay:0}');await sleep(50);
  await send('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});await sleep(100);
  await check("document.querySelector('.portal-loading-overlay').hidden && document.querySelector('.portal-loading-notice').textContent.includes('offline')",'Offline cleanup');
  await send('Network.emulateNetworkConditions',{offline:false,latency:0,downloadThroughput:-1,uploadThroughput:-1});await sleep(100);
  await check("document.querySelector('.portal-loading-notice').textContent.includes('back online')",'Online feedback');
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await start('{fullscreen:true,delay:0}');await sleep(50);
  await check("[...document.querySelectorAll('.portal-loading-ring,.portal-loading-logo,.portal-loading-dots span')].every(e=>getComputedStyle(e).animationName==='none')",'Reduced motion respected');
  await ev("window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))");
  await check("document.querySelector('.portal-loading-overlay').hidden && !document.querySelector('.portal-button-loading')",'History restoration cleanup');
  await ev("document.getElementById('nativeForm').dispatchEvent(new SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:document.querySelector('#nativeForm button')}));window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));");
  await sleep(550);
  await check("document.querySelector('.portal-loading-overlay').hidden && !document.querySelector('.portal-button-loading')",'History cleanup also cancels deferred event feedback');
  assert.deepEqual(hashes(),beforeHashes);checks++;
  if(errors.length)throw Error(errors.join('\n'));if(badResources.length)throw Error('Missing assets: '+badResources.join(', '));
  console.log(JSON.stringify({checks,screenshots:directory,logosUnchanged:true}));
 }finally{socket?.close();chrome.kill();await new Promise(r=>server.close(r));}
})().catch(error=>{console.error(error);process.exitCode=1;});
