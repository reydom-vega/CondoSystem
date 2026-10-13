/* Run only against the disposable test_ui_redesign.php --preview fixture. */
const {spawn,execFileSync}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],baseline=process.argv.includes('--baseline');
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const baselinePath=path.join(os.tmpdir(),'condo-theme-dark-baseline.json');
const reproduceStale=process.argv.includes('--reproduce-stale-css');
const staleCss=process.argv.includes('--stale-css')||reproduceStale;
const legacyCss=staleCss?new Map(['styles.css','resident.css','security.css','services.css','assets/css/workspace-tabs.css','assets/css/maintenance-workspace.css'].map(asset=>[asset,execFileSync('git',['show','HEAD:'+asset],{cwd:path.resolve(__dirname,'..')}).toString('base64')])):null;
function auditContrast() {
 const rgb=value=>value.match(/[\d.]+/g)?.map(Number);
 const blend=(a,b)=>a.slice(0,3).map((v,i)=>v*(a[3]??1)+b[i]*(1-(a[3]??1)));
 const lum=c=>c.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
 const issues=[];
 for(const element of document.querySelectorAll('body *')) {
  const closed=element.closest('details:not([open])');
  if(closed&&!closed.querySelector(':scope > summary')?.contains(element))continue;
  const icon=element.matches('svg.system-icon-svg,svg.sidebar-icon-svg,.notification-bell-icon svg,.theme-toggle svg,.toggle-password svg');
  const text=[...element.childNodes].some(node=>node.nodeType===3&&node.textContent.trim());
  const style=getComputedStyle(element);
  if((!icon&&!text)||!element.getClientRects().length||style.visibility==='hidden'||parseFloat(style.opacity)===0||element.closest('[hidden],:disabled,.panel-image,.hero,.amenity-image,.amenity-card-image,.amenity-card-media,.amenity-home-preview,.interior-slide,.overview-card'))continue;
  if(!icon&&element.closest('[aria-hidden=true]'))continue;
  const stack=[];let parent=element,skip=false,opacity=1;
  while(parent) {
   const s=getComputedStyle(parent);
   if(s.backgroundImage!=='none'){skip=true;break;}
   opacity*=parseFloat(s.opacity);
   stack.unshift(rgb(s.backgroundColor));parent=parent.parentElement;
  }
  if(skip||opacity===0)continue;
  let bg=[255,255,255];for(const color of stack)if(color)bg=blend(color,bg);
  const foreground=rgb(icon?style.stroke:style.color);if(!foreground)continue;
  foreground[3]=(foreground[3]??1)*opacity;
  const a=lum(blend(foreground,bg)),b=lum(bg),ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05);
  const large=parseFloat(style.fontSize)>=24||(parseFloat(style.fontSize)>=18.66&&parseFloat(style.fontWeight)>=700);
  if(ratio<(icon||large?3:4.5))issues.push({kind:icon?'icon':'text',element:element.tagName+'.'+(element.getAttribute('class')||''),text:element.textContent.trim().slice(0,65),color:icon?style.stroke:style.color,bg,ratio:+ratio.toFixed(2)});
 }
 return issues.slice(0,50);
}
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-theme-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[],badResources=[],snapshots={},contrastIssues=[];
 try{
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');
  socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(r=>socket.onopen=r);
  let seq=0;const calls=new Map();
  socket.onmessage=e=>{const m=JSON.parse(e.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&m.params.response.url.startsWith(base)&&!m.params.response.url.includes('staff.php'))badResources.push(m.params.response.url);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout '+method));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  if(staleCss) {
   const receive=socket.onmessage;
   socket.onmessage=event=>{
    receive(event);const message=JSON.parse(event.data);if(message.method!=='Fetch.requestPaused')return;
    const request=message.params,url=new URL(request.request.url),asset=url.pathname.slice(new URL(base).pathname.length);
    const old=url.origin===new URL(base).origin&&legacyCss.has(asset)&&(reproduceStale||!url.searchParams.has('v')||url.searchParams.get('v')==='2');
    const action=old?send('Fetch.fulfillRequest',{requestId:request.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/css'}],body:legacyCss.get(asset)}):send('Fetch.continueRequest',{requestId:request.requestId});
    action.catch(error=>errors.push(String(error)));
   };
   await send('Fetch.enable',{patterns:[{resourceType:'Stylesheet',requestStage:'Request'}]});
  }
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<150;i++){if(await ev(expression))return;await sleep(100);}throw Error('Timed out '+expression);};
  const check=async(expression,label)=>{if(!await ev(expression)){if(process.argv.includes('--branding-only')){await shot('branding-failure');console.log(await ev("[...document.querySelectorAll('.celandine-brand')].map(b=>{const w=b.querySelector('.celandine-wordmark'),i=b.querySelector('img');return {brand:b.getBoundingClientRect().toJSON(),word:w?.getBoundingClientRect().toJSON(),image:i.getBoundingClientRect().toJSON(),font:w&&getComputedStyle(w).fontFamily,weight:w&&getComputedStyle(w).fontWeight,src:i.getAttribute('src'),ready:i.complete,natural:i.naturalWidth};})"));}throw Error(label);}checks++;};
  const go=async entry=>{await ev('window.themeNavigating=true');const u=new URL(entry.path,base);u.searchParams.set('fixture_actor',entry.actor);await send('Page.navigate',{url:u.href});await until("!window.themeNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);};
  const shot=async name=>{const r=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const snapshot=()=>ev(`(()=>{const out={};for(const selector of ['body','.sidebar','.dash-header','.dashboard-main','.welcome-banner','.stat-card','.admin-stat-card','.service-panel','.unit-management-panel','.card','input:not([type=hidden])','textarea','.workspace-tabs','.amenity-card','.soa-card','.scanner-panel']){const e=document.querySelector(selector);if(e){const s=getComputedStyle(e);out[selector]=Object.fromEntries(['color','backgroundColor','backgroundImage','borderTopColor','borderRadius','fontSize','fontFamily','padding','boxShadow'].map(k=>[k,s[k]]));}}return out;})()`);
  const typography=()=>ev(`JSON.stringify([...document.querySelectorAll('body,.sidebar-link,.dash-title,.section-title,label,input,select,textarea,th,[role=tab]')].map(element=>{const style=getComputedStyle(element);return ['fontFamily','fontSize','fontWeight','lineHeight','letterSpacing'].map(key=>style[key]);}))`);
  const logoDimensions=()=>ev(`JSON.stringify([...document.querySelectorAll('[data-theme-logo]')].map(logo=>{const r=logo.getBoundingClientRect();return [r.width,r.height];}))`);
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  await send('Page.addScriptToEvaluateOnNewDocument',{source:"new MutationObserver(function(){if(document.body?.children.length && !window.themeBeforeContent){window.themeBeforeContent=document.documentElement.dataset.theme;this.disconnect();}}).observe(document.documentElement||document,{childList:true,subtree:true});"});
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
  if(process.argv.includes('--branding-only')) {
   const focus=process.argv.find(arg=>arg.startsWith('--routes='))?.slice(9).split(',');
   const pages=manifest.filter(entry=>(!focus||focus.includes(entry.path))&&/dashboard\.php$|^homepage\.php$|^login\.php$|^signup\.php$|^forgot_password\.php$|^reset_password\.php$|^verify\.php$|^verify_pending\.php$|^resend_verification\.php$|^signuppending\.php$|^resident_service_pass\.php\?|^parking_pass\.php\?/.test(entry.path));
   for(const width of [1366,390,320]) {
    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<1024});
    for(const entry of pages) {
     await go(entry);await ev("document.fonts.load('24px CelandineRoman')");
     for(const theme of ['dark','light']) {
      await ev('CelandineTheme.set('+JSON.stringify(theme)+')');await sleep(300);
      await until("[...document.querySelectorAll('.celandine-brand img')].every(image=>image.complete&&image.naturalWidth>0)");
      if(await ev("!!document.querySelector('.sidebar')&&innerWidth<1024")) {await ev("document.getElementById('menuToggle').click()");await sleep(250);}
      await check("document.querySelectorAll('.celandine-brand').length>0",'Brand component present: '+entry.path);
      await check("[...document.querySelectorAll('.celandine-brand')].every(brand=>{const word=brand.querySelector('.celandine-wordmark'),image=brand.querySelector('img'),b=brand.getBoundingClientRect(),w=word?.getBoundingClientRect(),i=image.getBoundingClientRect();return (!word||(getComputedStyle(word).fontFamily.includes('CelandineRoman')&&getComputedStyle(word).fontWeight==='400'&&w.right<=b.right+1&&w.left>=i.right))&&image.complete&&image.naturalWidth>0&&getComputedStyle(image).filter==='none'&&image.getAttribute('src')===image.dataset[document.documentElement.dataset.theme==='light'?'logoLight':'logoDark'];})",'Serif alignment and official logo: '+entry.path+' / '+width+' / '+theme);
      await check("!getComputedStyle(document.body).fontFamily.includes('CelandineRoman')&&document.documentElement.scrollWidth<=innerWidth+1",'Normal UI typography and responsive width preserved: '+entry.path);
      if(await ev("!!document.querySelector('.sidebar.open')")) {
       await check("(()=>{const word=document.querySelector('.sidebar-brand .celandine-wordmark').getBoundingClientRect(),close=document.querySelector('.sidebar-close').getBoundingClientRect();return word.right<=close.left;})()",'Mobile brand clears close button');
      }
      if(entry.path.includes('dashboard.php')||entry.path==='login.php'||entry.path==='homepage.php')await shot('brand-'+entry.actor+'-'+entry.path.replace(/\W/g,'-')+'-'+width+'-'+theme);
      if(await ev("!!document.querySelector('.sidebar.open')"))await ev("document.querySelector('.sidebar-close').click()");
     }
    }
   }
   if(errors.length||badResources.length)throw Error(JSON.stringify({errors,badResources}));
   console.log(JSON.stringify({checks,screenshots:directory,branding:true}));return;
  }
  const original=!baseline&&fs.existsSync(baselinePath)?JSON.parse(fs.readFileSync(baselinePath,'utf8')):null;
  const routes=process.argv.find(arg=>arg.startsWith('--routes='))?.slice(9).split(',');
  const entries=process.argv.includes('--interactions-only')?[]:manifest.filter(entry=>!routes||routes.includes(entry.path)||routes.includes(entry.path.split('?')[0]));
  for(const entry of entries){
   const key=entry.actor+':'+entry.path;await go(entry);
   if(baseline){snapshots[key]=await snapshot();continue;}
   await check("document.documentElement.dataset.theme==='dark'",'Dark default/restoration: '+key);
   await check("window.themeBeforeContent==='dark'",'Dark applied before content: '+key);
   if(original?.[key]){const current=await snapshot();if(JSON.stringify(current)!==JSON.stringify(original[key])){fs.writeFileSync(path.join(directory,'dark-mismatch.json'),JSON.stringify({key,before:original[key],after:current},null,2));throw Error('Original dark palette changed: '+key);}checks++;}
   await check("document.querySelectorAll('[data-theme-toggle]').length===1",'One shared toggle: '+key);
   await check("[...document.querySelectorAll('[data-theme-logo]')].every(logo=>logo.getAttribute('src')===logo.dataset.logoDark && logo.complete && logo.naturalWidth>0)",'White logo in dark: '+key);
   const darkTypography=await typography(),darkLogoDimensions=await logoDimensions();
   await check("[...document.querySelectorAll('link[rel=stylesheet]')].filter(link=>new URL(link.href).origin===location.origin).every(link=>Number(new URL(link.href).searchParams.get('v'))>2)",'Local stylesheets invalidate legacy caches: '+key);
   await check("!document.querySelector('.notification-menu') || document.querySelector('.notification-menu').previousElementSibling.matches('[data-theme-toggle]')",'Toggle precedes notification: '+key);
   await ev("window.themeDocumentMarker=123; document.querySelector('[data-theme-toggle]').click()");await sleep(250);
   await ev("Promise.all(document.getAnimations().filter(animation=>animation instanceof CSSTransition).map(animation=>animation.finished.catch(()=>{})))");
   await check("document.documentElement.dataset.theme==='light' && localStorage.getItem('condo-theme')==='light' && window.themeDocumentMarker===123",'Instant light preference: '+key);
   await until("getComputedStyle(document.body).colorScheme==='light' && getComputedStyle(document.body).backgroundColor!=='rgb(10, 15, 29)'");checks++;
   await check("document.querySelector('[data-theme-toggle]').getAttribute('aria-label')==='Switch to dark mode'",'Accessible light action: '+key);
   await until("[...document.querySelectorAll('[data-theme-logo]')].every(logo=>logo.getAttribute('src')===logo.dataset.logoLight && logo.complete && logo.naturalWidth>0)");checks++;
   if(await typography()!==darkTypography)throw Error('Theme changed typography: '+key);checks++;
   if(await logoDimensions()!==darkLogoDimensions)throw Error('Theme changed logo dimensions: '+key);checks++;
   if(!reproduceStale) {
    await check("[...document.querySelectorAll('.workspace-tabs,.announcement-content,.category-tag')].every(element=>getComputedStyle(element).backgroundColor.match(/[\\d.]+/g).slice(0,3).map(Number).every(channel=>channel>=180))",'Light panels and tabs have no legacy dark fill: '+key);
    await check("[...document.querySelectorAll('.admin-list-row strong,.announcement-title')].every(element=>getComputedStyle(element).color==='rgb(26, 34, 51)')",'Dashboard and announcement headings readable: '+key);
   }
   const contrast=await ev('('+auditContrast.toString()+')()');
   if(contrast.length)contrastIssues.push({key,issues:contrast});
   if(['resident/announcements.php','resident/visitors.php','superadmin/admin_dashboard.php'].includes(entry.path)) {
    await ev("document.querySelector('.announcements-container,.workspace-tabs,.admin-overview-grid')?.scrollIntoView({block:'center',behavior:'instant'})");
    await shot('details-light-'+entry.actor+'-'+entry.path.replace(/[^\w]/g,'-'));await ev('scrollTo(0,0)');
   }
   if(['resident/dashboard.php','resident/edit_profile.php','superadmin/admin_dashboard.php','security/security_dashboard.php','resident/payments.php','resident/book_amenity.php','resident/maintenance.php','resident/permits.php','resident/parking.php','resident/messages.php','superadmin/violations.php','superadmin/staff.php','superadmin/service_requests.php?kind=visitor','superadmin/bookingrequest.php','security/scanner.php','superadmin/scanner.php','login.php','homepage.php'].includes(entry.path))await shot('light-'+entry.actor+'-'+entry.path.replace(/[^\w]/g,'-'));
   await ev("document.querySelector('[data-theme-toggle]').click()");await sleep(220);
   await check("document.documentElement.dataset.theme==='dark' && localStorage.getItem('condo-theme')==='dark'",'Restore dark: '+key);
   console.log('PASS both themes '+key);
  }
  if(baseline){fs.writeFileSync(baselinePath,JSON.stringify(snapshots,null,2));console.log('Saved original dark appearance for '+manifest.length+' role/page combinations: '+baselinePath);return;}
  if(process.argv.includes('--appearance-only')) {
   fs.writeFileSync(path.join(directory,'contrast-review.json'),JSON.stringify(contrastIssues,null,2));
   if(staleCss&&!reproduceStale&&contrastIssues.length)throw Error('Stale-cache appearance regression: '+JSON.stringify(contrastIssues));
   if(errors.length||badResources.length)throw Error(JSON.stringify({errors,badResources}));
   console.log('Passed '+checks+' appearance checks. Contrast review and screenshots: '+directory);return;
  }
  const dashboard={actor:1,path:'resident/dashboard.php'};await go(dashboard);
  await ev("document.querySelector('[data-theme-toggle]').click()");await send('Page.reload');await until("document.readyState==='complete' && !!window.CelandineTheme");
  await check("document.documentElement.dataset.theme==='light'",'Reload restores preference');
  await check("window.themeBeforeContent==='light'",'Light restored before content');
  await go({actor:2,path:'resident/payments.php'});await check("document.documentElement.dataset.theme==='light'",'Navigation restores preference');
  // Toggle back to a known theme before exercising narrow headers and menus.
  await ev("window.CelandineTheme.set('light')");
  for(const width of [768,390,320]){
   await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
   for(const actor of [1,2,4,5,6,7,8]){
    const entry=manifest.find(e=>e.actor===actor&&/dashboard\.php/.test(e.path));await go(entry);
    await check("document.documentElement.scrollWidth<=innerWidth+1",'No mobile overflow '+actor+'/'+width);
    await check("(()=>{const t=document.querySelector('[data-theme-toggle]').getBoundingClientRect(),n=document.querySelector('#notificationToggle')?.getBoundingClientRect();return t.width>=36&&t.right<=innerWidth&&(!n||t.right<=n.left);})()",'Mobile toggle placement '+actor+'/'+width);
   }
   await shot('mobile-light-'+width);
   for(const entry of [{actor:0,path:'homepage.php'},{actor:0,path:'login.php'},{actor:5,path:'security/scanner.php'}]){
    await go(entry);await check("document.documentElement.scrollWidth<=innerWidth+1 && (()=>{const r=document.querySelector('[data-theme-toggle]').getBoundingClientRect();return r.width>=36&&r.x>=0&&r.right<=innerWidth;})()",'Public/scanner control fits '+entry.path+'/'+width);
    if(entry.path==='homepage.php'){
     await check("[...document.querySelectorAll('.nav-cta > a')].every(a=>getComputedStyle(a).display==='none') && getComputedStyle(document.querySelector('.nav-toggle')).flexDirection==='column'",'Mobile public navigation fits without overlapping links');
     await ev("document.querySelector('#navToggle').click()");await check("document.querySelector('#mobileMenu').classList.contains('open')",'Public mobile menu opens');await ev("document.querySelector('#navToggle').click()");
    }
    if(width===320)await shot('mobile-'+entry.path.replace(/[^\w]/g,'-'));
   }
  }
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});await go(dashboard);
  await ev("document.querySelector('#notificationToggle').click()");await check("document.querySelector('.notification-menu').classList.contains('open')",'Notification still opens');
  await ev("document.querySelector('#notificationToggle').click();document.querySelector('#profileToggle').click()");await check("document.querySelector('.profile-menu').classList.contains('open')",'Profile still opens');
  await go({actor:1,path:'resident/edit_profile.php'});await ev("window.CelandineTheme.set('light');document.querySelector('.pw-body').closest('details').open=true");await sleep(350);
  await check("getComputedStyle(document.querySelector('label[for=current_password]')).color==='rgb(71, 85, 105)' && getComputedStyle(document.querySelector('.password-rules li')).color==='rgb(180, 35, 53)'",'Expanded password labels and validation use light colors');
  await shot('expanded-profile-light');
  await go({actor:6,path:'superadmin/announcements.php'});await ev("window.CelandineTheme.set('light');openCreateModal()");await sleep(350);
  await check("document.querySelector('#announcementModal').classList.contains('open') && getComputedStyle(document.querySelector('#announcementModal button[onclick=\"closeModal()\"]:not(.modal-close)')).color==='rgb(26, 34, 51)'",'Announcement cancel text stays readable in light modal');
  const announcementContrast=await ev('('+auditContrast.toString()+')()');if(announcementContrast.length)contrastIssues.push({key:'expanded-announcement-modal',issues:announcementContrast});
  await shot('announcement-modal-light');await ev('closeModal()');
  await go({actor:0,path:'login.php'});await ev("window.CelandineTheme.set('light')");await sleep(350);
  await check("getComputedStyle(document.querySelector('input[type=text],input[type=email]')).borderTopColor==='rgb(122, 137, 158)'",'Light form outlines retain a visible control boundary');
  await check("getComputedStyle(document.querySelector('input[type=text],input[type=email]')).fontFamily===getComputedStyle(document.body).fontFamily",'Inputs retain shared UI typography');
  for(const entry of [{actor:1,path:'resident/maintenance.php?tab=requests&view=1'},{actor:4,path:'superadmin/maintenancerequests.php?tab=requests&view=1'}]) {
   await go(entry);await ev("window.CelandineTheme.set('light')");await sleep(350);
   const detailContrast=await ev('('+auditContrast.toString()+')()');
   if(detailContrast.length)contrastIssues.push({key:'maintenance-details:'+entry.path,issues:detailContrast});
   await check("!!document.querySelector('.maintenance-detail-actions,.maintenance-admin-detail')",'Maintenance details opened for '+entry.actor);
   await shot('maintenance-details-light-'+entry.actor);
  }
  await go({actor:4,path:'superadmin/bookingrequest.php'});await ev("document.querySelector('.amenity-decision-details').open=true");await sleep(300);
  await check("getComputedStyle(document.querySelector('.amenity-decision-buttons .service-btn-danger')).backgroundColor==='rgb(255, 240, 241)' && getComputedStyle(document.querySelector('.amenity-decision-buttons .service-btn-secondary')).backgroundColor!=='rgb(36, 42, 56)'",'Expanded booking actions use light surfaces');
  await go({actor:6,path:'superadmin/parkinginventory.php'});await ev("document.querySelector('.parking-config-details').open=true");await sleep(300);
  await check("getComputedStyle(document.querySelector('.parking-config-details .service-btn-secondary')).backgroundColor!=='rgb(36, 42, 56)'",'Expanded parking settings use light controls');
  for(const theme of ['dark','light']){
   await ev(`window.CelandineTheme.set('${theme}')`);
   await go({actor:1,path:'resident/book_amenity.php'});
   await ev("(()=>{document.querySelector('[data-open-booking=swimming-pool]').click();const f=document.querySelector('#poolBookingForm');const d=new Date();d.setDate(d.getDate()+5);f.elements.booking_date.value=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');f.elements.duration_hours.value='2';f.elements.attendees.value='5';f.elements.booking_date.dispatchEvent(new Event('change',{bubbles:true}));})()");
   await until("!!document.querySelector('#poolBookingForm [data-time-slots] button:not(:disabled)')");
   await ev("document.querySelector('#poolBookingForm [data-time-slots] button:not(:disabled)').click()");
   await check("document.querySelector('#amenityBookingDialog').open && document.querySelector('[data-summary-fee]').textContent.includes('2,000') && !document.querySelector('[data-reservation-submit]').disabled",'Booking modal/availability work in '+theme);
   await ev(`window.CelandineTheme.set('${theme==='dark'?'light':'dark'}')`);
   await check("document.querySelector('#amenityBookingDialog').open && document.querySelector('#poolBookingForm').elements.attendees.value==='5' && document.querySelector('#poolBookingForm').elements.duration_hours.value==='2'",'Theme change keeps reservation draft in '+theme);
   await sleep(300);await ev("Promise.all(document.getAnimations().filter(animation=>animation instanceof CSSTransition).map(animation=>animation.finished.catch(()=>{})))");
   await check("getComputedStyle(document.querySelector('#amenityBookingTitle')).color==="+JSON.stringify(theme==='dark'?'rgb(26, 34, 51)':'rgb(255, 255, 255)'),'Modal title follows active theme');
   await shot('booking-modal-'+(theme==='dark'?'light':'dark'));
   await ev("document.querySelector('#amenityBookingDialog').close()");
   await go({actor:1,path:'resident/payments.php'});await ev(`window.CelandineTheme.set('${theme}')`);
   await ev("window.billingThemeMarker=42;document.querySelector('[value=cash]').click();document.querySelector('[data-billing-tab=history]').click()");
   await check("window.billingThemeMarker===42 && !document.querySelector('#billing-panel-history').hidden",'Billing History switches without reload in '+theme);
   await ev("document.querySelector('[data-billing-tab=billing]').click()");await check("document.querySelector('[value=cash]').checked",'Payment method preserved in '+theme);
   const pass=manifest.find(e=>e.path.startsWith('resident_service_pass.php?id=1'));
   await go(pass);await until("!!document.querySelector('#serviceQr canvas,#serviceQr img')");
   const qr=await ev("document.querySelector('#serviceQr canvas')?.toDataURL() || document.querySelector('#serviceQr img').src");
   await ev(`window.CelandineTheme.set('${theme==='dark'?'light':'dark'}')`);
   await check("getComputedStyle(document.querySelector('#serviceQr')).backgroundColor==='rgb(255, 255, 255)' && (document.querySelector('#serviceQr canvas')?.toDataURL() || document.querySelector('#serviceQr img').src)==="+JSON.stringify(qr),'QR paper and pixels preserved in '+theme);
  }
  await go(dashboard);await send('Page.bringToFront');await ev("document.querySelector('[data-theme-toggle]').focus()");
  await check("document.activeElement.matches('[data-theme-toggle]')",'Theme control receives keyboard focus');
  const keyboardTheme=await ev('document.documentElement.dataset.theme');
  await send('Input.dispatchKeyEvent',{type:'rawKeyDown',key:' ',code:'Space',windowsVirtualKeyCode:32,nativeVirtualKeyCode:32});await send('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32,nativeVirtualKeyCode:32});
  await until("document.documentElement.dataset.theme!=="+JSON.stringify(keyboardTheme));checks++;
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
  await ev("window.CelandineTheme.set('dark')");await check("!document.documentElement.classList.contains('theme-changing')",'Reduced motion skips color transition');
  await ev("dispatchEvent(new StorageEvent('storage',{key:'condo-theme',newValue:'light'}))");await check("document.documentElement.dataset.theme==='light'",'Cross-tab preference sync');
  await send('Page.addScriptToEvaluateOnNewDocument',{source:"Object.defineProperty(Storage.prototype,'getItem',{value:()=>{throw new DOMException('Blocked','SecurityError')}});Object.defineProperty(Storage.prototype,'setItem',{value:()=>{throw new DOMException('Blocked','SecurityError')}});"});
  await go(dashboard);await check("document.documentElement.dataset.theme==='dark'",'Blocked storage safely defaults dark');await ev("document.querySelector('[data-theme-toggle]').click()");await check("document.documentElement.dataset.theme==='light'",'Toggle works with blocked storage');
  fs.writeFileSync(path.join(directory,'contrast-review.json'),JSON.stringify(contrastIssues,null,2));
  if(errors.length||badResources.length)throw Error(JSON.stringify({errors,badResources}));
  console.log('Passed '+checks+' theme checks; route sweep: '+entries.length+' role/page combinations. Screenshots: '+directory);
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
