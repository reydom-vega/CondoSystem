/* Read-only public homepage regression checks. No login submissions or database fixtures. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/(?:127\.0\.0\.1|localhost)(?::\d+)?\/[^?#]*$/.test(base||'')||!base.endsWith('/'))throw Error('Pass a localhost application base URL ending in /.');
(async()=>{
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'celandine-homepage-browser-'));
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
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  const external=['*fonts.googleapis.com*','*fonts.gstatic.com*','*www.google.com/maps/*'];
  await send('Network.setBlockedURLs',{urls:external});

  async function visit({width=1366,height=900,theme='dark',blocked=[],reduced=true}={}) {
    await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<600});
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:reduced?'reduce':'no-preference'}]});
    await send('Network.setBlockedURLs',{urls:[...external,...blocked]});
    const init=await send('Page.addScriptToEvaluateOnNewDocument',{source:`sessionStorage.setItem('celandineIntroSeenV1','1');localStorage.setItem('condo-theme','${theme}');document.addEventListener('DOMContentLoaded',()=>{window.__homepageHeroTweenOnReady=!!window.gsap&&gsap.getTweensOf(document.querySelector('.hero-text h1')).length>0;},{once:true});`});
    await send('Page.navigate',{url:base+'homepage.php'});
    await until("document.readyState==='complete'&&!!document.querySelector('[data-theme-toggle]')");
    await send('Page.removeScriptToEvaluateOnNewDocument',{identifier:init.identifier});
  }
  async function checkNavigation(id, expected, label) {
    await ev(`document.querySelector('#${id}').scrollIntoView({block:'start',behavior:'instant'})`);
    const expression = `['.nav-links','#mobileMenu'].every(selector=>{const links=[...document.querySelectorAll(selector+' > a[href^="#"]')];return JSON.stringify(links.filter(a=>a.classList.contains('active')).map(a=>a.hash))===${JSON.stringify(JSON.stringify(expected))}&&links.every(a=>a.classList.contains('active')===(a.getAttribute('aria-current')==='location'));})`;
    await until(expression);
    await check(expression,label);
  }
  for(const [width,height] of [[1366,900],[1024,768],[768,1024],[390,844],[320,740],[844,390]]) {
    for(const theme of ['dark','light']) {
      await visit({width,height,theme});
      await check('document.documentElement.scrollWidth<=innerWidth+1',`no horizontal overflow ${width} ${theme}`);
      await check("(()=>{const brand=document.querySelector('.brand').getBoundingClientRect(),toggle=document.querySelector('[data-theme-toggle]').getBoundingClientRect(),menu=document.querySelector('#navToggle').getBoundingClientRect();return brand.left>=0&&brand.right<=toggle.left&&toggle.right<=innerWidth&&(menu.width===0||menu.right<=innerWidth);})()",`header controls align ${width} ${theme}`);
      await check(`document.documentElement.dataset.theme==='${theme}'&&document.querySelector('.brand img').src.includes('${theme==='light'?'celandineorange':'celandine'}.svg')`, `shared theme and official logo ${width} ${theme}`);
      await check("parseFloat(getComputedStyle(document.querySelector('h1')).fontSize)>=46&&getComputedStyle(document.querySelector('h1')).color==='rgb(255, 255, 255)'",`hero typography contrast ${width} ${theme}`);
      await check("document.querySelector('#location iframe').getBoundingClientRect().width<=innerWidth&&document.querySelector('.map-frame').getBoundingClientRect().height>=280",`responsive map ${width} ${theme}`);
      await check("document.querySelectorAll('[data-home-preview]').length===12&&document.querySelectorAll('.service-grid article').length===4",`all photos and service cards ${width} ${theme}`);
      await checkNavigation('interiors',width>900?['#interiors','#portal']:['#interiors'],`interiors navigation matches shared or stacked layout ${width} ${theme}`);
      await checkNavigation('portal',width>900?['#interiors','#portal']:['#portal'],`portal navigation matches shared or stacked layout ${width} ${theme}`);
      if(width===1366) await shot(`shared-row-navigation-${theme}`);
      await checkNavigation('location',['#location'],`leaving the shared row clears both highlights ${width} ${theme}`);
      await ev("window.scrollTo({top:0,behavior:'instant'})");await sleep(75);
      if(width===1366||width===390) {await shot(`hero-${width}-${theme}`); await ev("window.scrollTo(0,document.querySelector('#amenities').offsetTop-95)");await until("[...document.querySelectorAll('#amenityGallery img')].filter(img=>{const r=img.getBoundingClientRect();return r.right>0&&r.left<innerWidth}).every(img=>img.complete&&img.naturalWidth>0)");await ev("Promise.all([...document.querySelectorAll('#amenityGallery img')].filter(img=>img.complete).map(img=>img.decode().catch(()=>{})))");await sleep(100);await shot(`amenities-${width}-${theme}`);}
      if(width===1366) {await ev("window.scrollTo(0,document.querySelector('#location').offsetTop-95)");await sleep(100);await shot(`location-${theme}`);}
      if(width===390&&theme==='light') {
        await ev("document.querySelector('#navToggle').click()");
        await check("!document.querySelector('#mobileMenu').hidden&&document.querySelector('#navToggle').getAttribute('aria-expanded')==='true'",'mobile menu opens');
        await ev("document.querySelector('#mobileMenu a[href=\"#interiors\"]').click()");
        await check("document.querySelector('#mobileMenu').hidden&&document.activeElement.id==='interiors'",'mobile link closes menu and transfers focus');await sleep(150);
        await ev("document.querySelector('#navToggle').click()");await key('Escape','Escape',27);
        await check("document.querySelector('#mobileMenu').hidden&&document.activeElement.id==='navToggle'",'Escape closes mobile menu and restores focus');
      }
    }
  }
  await visit();
  await check("[...document.querySelectorAll('.gallery-controls .system-icon-svg,.service-grid .system-icon-svg')].every(svg=>svg.getBoundingClientRect().width===20&&svg.getBoundingClientRect().height===20)",'gallery and portal icons have visible dimensions');
  await check("getComputedStyle(document.querySelector('.hero .home-btn-outline')).borderTopColor!=='rgba(0, 0, 0, 0)'",'secondary action keeps visible border');
  await ev("window.scrollTo(0,document.querySelector('#amenities').offsetTop-90)");
  await ev("document.querySelector('#amenityNext').click()");await until("document.querySelector('#amenityGallery').scrollLeft>150&&!document.querySelector('#amenityPrev').disabled");
  await check("!document.querySelector('#amenityPrev').disabled",'amenity arrows scroll and update enabled state');
  await ev("document.querySelector('#amenityPrev').click()");await until("document.querySelector('#amenityGallery').scrollLeft<2&&document.querySelector('#amenityPrev').disabled");
  await check("document.querySelector('#amenityPrev').disabled",'amenity previous arrow reaches start');
  await ev("document.querySelector('#galleryNext').click()");await until("document.querySelector('#interiorGallery').scrollLeft>100&&!document.querySelector('#galleryPrev').disabled");
  await check("!document.querySelector('#galleryPrev').disabled",'interior gallery arrows scroll');
  await ev("document.querySelector('#interiorGallery').focus()");await key('End','End',35);
  await until("document.querySelector('#galleryNext').disabled");
  await check("document.querySelector('#interiorGallery').scrollLeft>500",'gallery keyboard End reaches last photo');
  await key('Home','Home',36);await until("document.querySelector('#galleryPrev').disabled");
  await ev("document.querySelector('[data-home-preview]').click()");await until("document.querySelector('#homePhoto').complete&&document.querySelector('#homePhoto').naturalWidth>0");
  await check("document.querySelector('#homeLightbox').open&&document.querySelector('#homePhotoTitle').textContent==='Swimming Pool'",'pool lightbox opens real image');
  await check("document.activeElement.classList.contains('lightbox-close')&&document.querySelector('#homePhotoStatus').textContent===''",'lightbox focuses close control and completes loading');
  await key('ArrowRight','ArrowRight',39);await check("document.querySelector('#homePhotoTitle').textContent==='Fitness Gym'&&document.querySelector('#homePhotoCount').textContent==='2 / 6'",'lightbox keyboard moves photos');
  for(let i=0;i<6;i++)await key('Tab','Tab',9);
  await check("document.querySelector('#homeLightbox').contains(document.activeElement)",'native modal traps keyboard focus');
  await shot('lightbox');await key('Escape','Escape',27);
  await check("!document.querySelector('#homeLightbox').open&&document.activeElement.matches('[data-home-preview]')&&document.body.style.overflow!== 'hidden'",'Escape closes lightbox and restores focus and scroll');
  await ev("document.querySelector('#interiorGallery [data-home-preview]').click()");await until("document.querySelector('#homePhoto').complete&&document.querySelector('#homePhoto').naturalWidth>0");
  await check("document.querySelector('#homePhotoTitle').textContent==='Function Hall'&&document.querySelector('#homePhotoCount').textContent==='1 / 6'",'interior lightbox uses its own gallery');
  await ev("document.querySelector('#homePhoto').src='assets/homepage/nonexistent-test-photo.jpg'");await until("document.querySelector('#homePhotoStatus').textContent.includes('unavailable')");
  await ev("document.querySelector('#photoNext').click()");await until("document.querySelector('#homePhotoStatus').textContent===''");
  await check("document.querySelector('#homePhoto').naturalWidth>0",'photo error recovery loads next image');
  await ev("document.querySelector('.lightbox-close').click()");
  await ev("document.querySelector('[data-theme-toggle]').click()");
  await check("CelandineTheme.get()==='light'&&localStorage.getItem('condo-theme')==='light'&&document.querySelector('.brand img').src.includes('celandineorange.svg')",'navbar uses existing persistent theme controller');
  await send('Page.reload',{ignoreCache:true});await until("document.readyState==='complete'");
  await check("document.documentElement.dataset.theme==='light'&&document.querySelector('.brand img').src.includes('celandineorange.svg')",'theme persists through reload');
  await ev("document.querySelector('.faq-item summary').click()");
  await check("document.querySelector('.faq-item').open",'native FAQ accordion opens');
  await ev("[...document.querySelectorAll('.faq-item')].find(el=>el.querySelector('summary').textContent==='Privacy Policy').open=true");
  await check("document.querySelector('.legal-inline').textContent.includes('Data Privacy Act of 2012 (RA 10173)')",'full Privacy Policy present');
  await visit({blocked:['*assets/vendor/gsap/gsap.min.js*'],reduced:false});
  await check("!window.gsap&&getComputedStyle(document.querySelector('h1')).opacity==='1'&&getComputedStyle(document.querySelector('.photo-card')).opacity==='1'",'missing GSAP leaves content visible');
  await ev("document.querySelector('[data-home-preview]').click()");await check("document.querySelector('#homeLightbox').open",'lightbox works without GSAP');await key('Escape','Escape',27);
  await visit({reduced:false});
  await check("window.__homepageHeroTweenOnReady===true",'hero entrance uses existing GSAP');
  await sleep(1100);await ev('window.scrollTo(0,200)');await sleep(100);
  await check("document.querySelector('.hero-bg').style.transform.includes('translate3d')",'subtle parallax active');
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await until("document.querySelector('.hero-bg').style.transform===''");
  await check("document.querySelector('.hero-bg').style.transform===''",'live reduced-motion preference stops parallax');
  await send('Emulation.setScriptExecutionDisabled',{value:true});
  await send('Page.navigate',{url:base+'homepage.php'});await until("document.readyState==='complete'");
  await check("getComputedStyle(document.querySelector('h1')).opacity==='1'&&getComputedStyle(document.querySelector('.cel-intro')).display==='none'",'no JavaScript leaves homepage visible');
  await check("document.querySelector('[data-home-preview]').getAttribute('target')==='_blank'&&document.querySelector('.home-btn[href=\"login.php\"]')",'original image and login links remain native fallbacks');
  await send('Emulation.setScriptExecutionDisabled',{value:false});
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
