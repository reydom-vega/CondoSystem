/* Headless Chrome UI regression suite. Run against test_ui_redesign.php --preview. */
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const base = process.argv[2];
const interactionsOnly = process.argv.includes('--interactions-only');
const tabletOnly = process.argv.includes('--tablet-only');
const loginOnly = process.argv.includes('--login-only');
const reportFile = loginOnly ? 'inventory/login-browser-results.json' : tabletOnly ? 'inventory/ui-tablet-results.json' : interactionsOnly ? 'inventory/ui-interaction-results.json' : 'inventory/ui-browser-results.json';
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable loopback UI fixture base URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve,ms));
(async () => {
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const value=server.address().port; server.close(() => resolve(value)); }); });
  const directory = fs.mkdtempSync(path.join(os.tmpdir(),'condo-system-ui-'));
  const executable = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const chrome = spawn(executable,['--headless','--disable-gpu','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let socket, feedbackReader;
  const report = {generatedAt:new Date().toISOString(),screenshots:directory,pages:[],interactions:[],failures:[]};
  try {
    let target;
    for(let i=0;i<100;i++) { try { target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab => tab.type==='page'); if(target) break; } catch {} await sleep(100); }
    if(!target) throw Error('Chrome remote debugging did not start.');
    socket=new WebSocket(target.webSocketDebuggerUrl); await new Promise(resolve => socket.onopen=resolve);
    let sequence=0; const calls=new Map(); let errors=[],resources=[],credentialCalls=[];
    socket.onmessage=event => {
      const message=JSON.parse(event.data);
      if(message.id) { const call=calls.get(message.id); if(!call) return; calls.delete(message.id); clearTimeout(call.timer); message.error ? call.reject(message.error) : call.resolve(message.result); }
      if(message.method==='Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
      if(message.method==='Runtime.bindingCalled' && message.params.name==='loginCredentialProbe') credentialCalls.push(JSON.parse(message.params.payload));
      if(message.method==='Network.responseReceived' && message.params.response.status>=400 && message.params.response.url.startsWith(base)) resources.push({path:new URL(message.params.response.url).pathname,status:message.params.response.status});
    };
    socket.onclose=() => { calls.forEach(call=>{clearTimeout(call.timer);call.reject(Error('Chrome debugging connection closed'));});calls.clear(); };
    const send=(method,params={}) => new Promise((resolve,reject) => { const id=++sequence; const timer=setTimeout(()=>{calls.delete(id);reject(Error('Chrome command timed out: '+method));},30000); calls.set(id,{resolve,reject,timer}); socket.send(JSON.stringify({id,method,params})); });
    const evaluate=async expression => { const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); if(result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text); return result.result.value; };
    feedbackReader=() => evaluate("[...document.querySelectorAll('.alert')].map(el=>el.textContent.trim())");
    const wait=async expression => { for(let i=0;i<180;i++) { if(await evaluate(expression)) return; await sleep(100); } throw Error('Timed out: '+expression); };
    const check=async(expression,name) => { if(!await evaluate(expression)) throw Error(name); report.interactions.push(name); console.log('PASS '+name); };
    const viewport=async(width,height=900) => { await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<768}); await sleep(250); };
    const navigate=async entry => {
      errors=[]; resources=[];
      const url=base+entry.path+(entry.path.includes('?')?'&':'?')+'fixture_actor='+entry.actor;
      await send('Page.navigate',{url});
      await wait("document.body?.classList.contains('portal-ui') && !!window.CelandineUi && !!window.CelandineMotion");
      await sleep(500);
    };
    const screenshot=async name => {
      const result=await send('Page.captureScreenshot',{format:'png'});
      fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));
    };
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    if(loginOnly) {
      report.limitations=['Browser credential storage is stubbed to check verified-login timing and refusal handling. Native password-manager consent and subsequent autofill require an interactive browser with password saving enabled.'];
      await send('Runtime.addBinding',{name:'loginCredentialProbe'});
      await send('Page.addScriptToEvaluateOnNewDocument',{source:`
        const options=new URLSearchParams(location.search);
        if(options.has('storage_blocked')) ['getItem','setItem','removeItem'].forEach(method=>Object.defineProperty(Storage.prototype,method,{value:()=>{throw new DOMException('Storage blocked','SecurityError');}}));
        if(options.has('credential_api_off')) Object.defineProperty(window,'PasswordCredential',{value:undefined});
        if(navigator.credentials) Object.defineProperty(navigator.credentials,'store',{value:async credential=>{
          loginCredentialProbe(JSON.stringify({id:credential.id,passwordMatches:credential.password==='FixtureOnly!2026',blocked:options.has('credential_store_blocked')}));
          if(options.has('credential_store_blocked')) throw new DOMException('Save declined','NotAllowedError');
          return credential;
        }});
      `});
      const login=async suffix=>{await navigate({path:'login.php'+(suffix?'?'+suffix:''),actor:0});await wait("document.querySelector('#loginForm')?.dataset.loginReady==='true'");};
      const credentials=async(remember=true,password='FixtureOnly!2026')=>{
        await evaluate(`document.querySelector('#identifier').value='owner';document.querySelector('#password').value=${JSON.stringify(password)};document.querySelector('#rememberMe').checked=${remember};document.querySelector('#loginForm').requestSubmit()`);
      };
      const dashboard=async()=>{await wait("location.pathname.endsWith('/resident/dashboard.php') && !!window.CelandineUi");};
      const logout=async()=>{
        await navigate({path:'logout.php',actor:1});await evaluate("document.querySelector('form').requestSubmit()");
        await wait("location.pathname.endsWith('/login.php') && document.querySelector('#loginForm')?.dataset.loginReady==='true'");
      };
      await viewport(390,844);await login();
      await check("window.isSecureContext && typeof PasswordCredential==='function' && document.querySelector('#password').autocomplete==='current-password'",'Browser-managed credentials and native autocomplete are available');
      await credentials(true,'InvalidFixture!1');
      await wait("document.querySelector('#loginFeedback').classList.contains('error') && !document.querySelector('#loginFeedback').hidden && !document.querySelector('#loginForm').hasAttribute('aria-busy')");
      if(credentialCalls.length!==0) throw Error('Invalid credentials were offered to the password manager');
      await check("!document.querySelector('#loginForm button[type=submit]').disabled",'Invalid password does not save credentials and permits retry');
      await evaluate("document.querySelector('#password').value='FixtureOnly!2026';document.querySelector('.toggle-password').click()");
      await check("document.querySelector('#password').type==='text'",'Password visibility toggle remains usable');
      await credentials();await dashboard();
      if(credentialCalls.length!==1 || credentialCalls[0].id!=='owner' || !credentialCalls[0].passwordMatches) throw Error('Verified credentials did not reach browser password storage');
      await check("!JSON.stringify({...localStorage}).includes('FixtureOnly!2026')",'Only verified credentials reach the browser API; no password enters site storage');
      await logout();
      await check("document.querySelector('#identifier').value==='owner' && document.querySelector('#rememberMe').checked",'Logout retains the remembered account and browser-save preference');
      const cookies=await send('Network.getCookies',{urls:[base]});
      if(cookies.cookies.some(cookie=>cookie.name==='remember_token')) throw Error('Logout retained an authentication token');
      await check("document.querySelector('#password').value===''",'Logout returns to a signed-out form without placing a password in page HTML');
      await credentials(false,'InvalidFixture!1');
      await wait("document.querySelector('#loginForm')?.dataset.submitted==='true' && document.querySelector('#loginFeedback').classList.contains('error') && document.querySelector('#loginForm')?.dataset.loginReady==='true'");
      await check("!document.querySelector('#rememberMe').checked",'Failed native login preserves an unchecked Remember Me choice');
      await credentials(false);await dashboard();
      if(credentialCalls.length!==1) throw Error('Unchecked Remember Me requested password storage');
      await logout();
      await check("!document.querySelector('#rememberMe').checked && !document.querySelector('#identifier').value",'Opting out clears the site remembered identifier and preference');
      await login('storage_blocked=1');await credentials();await dashboard();
      await check("document.querySelector('.dash-title').textContent.includes('Dashboard')",'Blocked site storage does not break login');
      await logout();await login('credential_api_off=1');await credentials();await dashboard();
      await check("document.querySelector('.dash-title').textContent.includes('Dashboard')",'Unsupported credential API falls back to native form login');
      await logout();await login('credential_store_blocked=1');await credentials();await dashboard();
      await check("document.querySelector('.dash-title').textContent.includes('Dashboard')",'Declining browser password storage does not block authenticated login');
      await logout();await screenshot('mobile-login-remember-me');
      if(errors.length || resources.some(item=>item.status===404 || item.status>=500)) throw Error('Login browser console/resource failure');
      console.log('Passed '+report.interactions.length+' login/browser integration checks. Native password-manager consent/autofill needs an interactive browser.');
      return;
    }
    const manifest=interactionsOnly ? [] : await(await fetch(base+'__manifest')).json();
    const samples=new Set(['6:superadmin/admin_dashboard.php','4:admin/admin_dashboard.php','5:security/security_dashboard.php','7:treasurer/treasurer_dashboard.php','8:maintenance/maintenance_dashboard.php','1:resident/dashboard.php','2:resident/payments.php','1:resident/messages.php','6:superadmin/units.php','6:superadmin/generate_bills.php','6:superadmin/service_requests.php','1:resident/permits.php','1:resident/maintenance.php','1:resident/book_amenity.php','0:login.php','0:signup.php']);
    for(const width of tabletOnly ? [768] : [1440,390]) {
      await viewport(width,width===390 ? 844 : 1000);
      for(const [index,entry] of manifest.entries()) {
        await navigate(entry);
        const metrics=await evaluate(`(() => {
          const main=document.querySelector('.dashboard-main,main,.card,.pending-card,.rejected-card');
          const excess=[...document.querySelectorAll('body *')].filter(el=>el.getClientRects().length && !el.closest('.sidebar,dialog,.profile-dropdown,.notification-dropdown,[hidden]') && el.getBoundingClientRect().right>innerWidth+2 && getComputedStyle(el.parentElement).overflowX==='visible').slice(0,6).map(el=>el.tagName+'.'+el.className);
          const tallFilters=[...document.querySelectorAll('.unit-filters input,.unit-filters select')].filter(el=>el.getClientRects().length && el.getBoundingClientRect().height>70).map(el=>el.name);
          return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,gsap:window.gsap?.version,imports:[...document.scripts].filter(s=>s.src.includes('/gsap.min.js')).length,mainVisible:!!main && getComputedStyle(main).visibility!=='hidden' && Number(getComputedStyle(main).opacity)>0,excess,tallFilters};
        })()`);
        const result={page:entry.path.split('?')[0],actor:entry.actor,role:entry.label,width,...metrics,errors:[...errors],resources:[...resources]};
        report.pages.push(result);
        if(metrics.scrollWidth>width+2 || !metrics.mainVisible || metrics.imports!==1 || !metrics.gsap || metrics.tallFilters.length || errors.length || resources.some(r=>r.status===404 || r.status>=500)) report.failures.push(result);
        if(samples.has(entry.actor+':'+entry.path.split('?')[0])) await screenshot(width+'-'+entry.actor+'-'+entry.path.split('?')[0].replaceAll('/','-').replace('.php',''));
        if(index%15===0) console.log(`${width}px: reviewed ${index+1}/${manifest.length}`);
      }
      fs.writeFileSync(reportFile,JSON.stringify(report,null,2));
    }
    if(tabletOnly) {
      console.log(`Tablet coverage: ${report.pages.length} role/page/viewport checks; ${report.failures.length} layout/resource/console failures.`);
      if(report.failures.length) {console.log(JSON.stringify(report.failures,null,2));process.exitCode=1;}
      return;
    }
    const amenity={path:'resident/book_amenity.php',actor:2};
    await viewport(1440,1000); await navigate(amenity);
    await check("!document.querySelector('#yourBookings').hidden===false",'Amenity catalogue opens first');
    await evaluate("document.querySelector('#menuToggle').click()");
    await check("document.body.classList.contains('sidebar-collapsed') && document.querySelector('#menuToggle').getAttribute('aria-expanded')==='false'",'Desktop sidebar collapses');
    await navigate(amenity);
    await check("document.body.classList.contains('sidebar-collapsed')",'Desktop sidebar preference persists');
    await evaluate("document.querySelector('#menuToggle').click()");
    await viewport(390,844);
    await evaluate("document.querySelector('#menuToggle').click()");
    await check("document.querySelector('#sidebar').classList.contains('open') && !document.querySelector('#sidebar').inert && document.activeElement.classList.contains('sidebar-close')",'Mobile drawer opens with keyboard focus');
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    await check("!document.querySelector('#sidebar').classList.contains('open') && document.activeElement.id==='menuToggle'",'Escape closes drawer and restores focus');
    await evaluate("document.querySelector('[data-open-booking=swimming-pool]').click();{const f=document.querySelector('#poolBookingForm');const d=new Date();d.setDate(d.getDate()+12);f.elements.booking_date.value=d.toLocaleDateString('en-CA');f.elements.duration_hours.value='2';f.elements.attendees.value='4';f.elements.booking_date.dispatchEvent(new Event('change',{bubbles:true}));}");
    await wait("document.querySelector('[data-time-slots] button:not(:disabled)') && !document.querySelector('[data-time-slots]').hasAttribute('aria-busy')");
    await evaluate("document.querySelector('[data-time-slots] button:not(:disabled)').click()");
    await check("document.querySelector('[data-summary-fee]').textContent.includes('600') && !document.querySelector('[data-reservation-submit]').disabled",'Pool fee and real availability selection');
    await screenshot('mobile-pool-reservation');
    await evaluate("document.querySelector('[data-reservation-submit]').click()");
    await wait("location.hash==='#yourBookings' && document.querySelector('#yourBookings') && !document.querySelector('#yourBookings').hidden && document.querySelector('.alert.success') && !!window.CelandineUi && !!window.CelandineMotion");
    await check("document.querySelector('#yourBookings').textContent.includes('4 guests')",'Pool submission preserves backend and history');
    await evaluate("document.querySelector('#yourBookings form button[type=submit]').click()");
    await wait("document.querySelector('.system-confirm-dialog')?.open===true");
    await check("document.activeElement.classList.contains('system-confirm-cancel')",'Destructive confirmation focuses cancel');
    await evaluate("document.querySelector('.system-confirm-cancel').click()");
    await wait("document.querySelector('.system-confirm-dialog')?.open===false");
    await check("document.querySelector('#yourBookings').textContent.includes('Pending')",'Cancelling confirmation leaves reservation intact');
    await evaluate("document.querySelector('#yourBookings form button[type=submit]').click()");
    await wait("document.querySelector('.system-confirm-dialog')?.open===true");
    await evaluate("document.querySelector('.system-confirm-accept').click()");
    await wait("!!window.CelandineUi && !!document.querySelector('.alert.success') && document.querySelector('#yourBookings')?.textContent.includes('Cancelled') && document.querySelector('.system-confirm-dialog')?.open===false");
    await check("document.querySelector('#yourBookings').textContent.includes('Cancelled')",'Confirmed cancellation reaches the existing backend');
    await viewport(768,1024); await navigate({path:'security/scanner.php',actor:5});
    await check("document.documentElement.scrollWidth<=innerWidth+2 && !!document.querySelector('#reader') && document.querySelector('#startScanner').getClientRects().length>0",'Tablet scanner remains prominent');
    await evaluate("document.querySelector('#historyTab').click()");
    await wait("document.querySelector('#historyPanel')?.getAttribute('aria-busy')!== 'true'");
    await check("!document.querySelector('#historyPanel').hidden && document.querySelector('#scanPanel').hidden",'Scanner history tab loads');
    await viewport(320,740); await navigate(amenity);
    await check("document.documentElement.scrollWidth<=innerWidth+2",'320px booking page fits');
    await evaluate("document.querySelector('[data-open-booking=swimming-pool]').click()");
    await check("document.querySelector('#amenityBookingDialog').scrollWidth<=document.querySelector('#amenityBookingDialog').clientWidth+2",'320px reservation dialog fits');
    await viewport(1440,1000);
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    await navigate({path:'superadmin/admin_dashboard.php',actor:6});
    await check("matchMedia('(prefers-reduced-motion:reduce)').matches && [...document.querySelectorAll('.admin-stat-card')].every(el=>getComputedStyle(el).transform==='none' && Number(getComputedStyle(el).opacity)===1)",'Reduced motion preserves visible static cards');
    await send('Emulation.setEmulatedMedia',{features:[]});
    await send('Network.setBlockedURLs',{urls:['*assets/vendor/gsap/gsap.min.js*']});
    await navigate(amenity);
    await check("!window.gsap && document.querySelector('#exploreAmenities').getClientRects().length>0",'GSAP failure leaves content visible');
    await evaluate("document.querySelector('[data-open-booking=swimming-pool]').click()");
    await check("document.querySelector('#amenityBookingDialog').open",'Booking dialog works without GSAP');
    await send('Network.setBlockedURLs',{urls:[]});
    await viewport(390,844); await navigate({path:'signup.php',actor:0});
    await evaluate("document.querySelector('input[value=Tenant]').click();document.querySelector('#goToStep2').click()");
    await check("document.querySelector('#step-2').classList.contains('active') && [...document.querySelectorAll('#step-2 input:not([type=hidden])')].every(field=>field.labels.length>0)",'Signup second step has persistent associated labels');
    await screenshot('mobile-signup-details');
    await navigate({path:'login.php',actor:0});
    await evaluate("document.querySelector('#identifier').value='unknown@example.invalid';document.querySelector('#password').value='InvalidFixture!1';document.querySelector('form').requestSubmit()");
    await wait("document.querySelector('.alert.error') && !!window.CelandineUi");
    await check("document.querySelector('.alert.error').getAttribute('role')==='alert'",'Login validation remains accessible');
    await evaluate("document.querySelector('#identifier').value='owner';document.querySelector('#password').value='FixtureOnly!2026';document.querySelector('input[name=remember_me]').checked=false;document.querySelector('form').requestSubmit()");
    await wait("location.pathname.endsWith('/resident/dashboard.php') && !!window.CelandineUi");
    await check("document.querySelector('.dash-title').textContent.includes('Dashboard')",'Real fixture login reaches the resident dashboard');
    await navigate({path:'resident/edit_profile.php',actor:1});
    await check("getComputedStyle(document.querySelector('.form-grid-2')).gridTemplateColumns.split(' ').length===1 && [...document.querySelectorAll('.float-field')].every(wrap=>wrap.querySelector('label').getBoundingClientRect().bottom<=wrap.querySelector('input').getBoundingClientRect().top+2)",'Mobile profile uses stacked fields and readable labels');
    await screenshot('mobile-profile');
    await navigate({path:'signuppending.php',actor:3});
    await check("document.querySelector('.pending-card').getClientRects().length>0 && document.documentElement.scrollWidth<=innerWidth+2",'Pending approval screen is visible and responsive');
    await screenshot('mobile-pending');
    await navigate({path:'superadmin/analytics.php',actor:6});
    await check("innerWidth===390 && document.documentElement.scrollWidth<=390 && document.querySelector('.portal-report-toolbar a').getClientRects().length>0",'Analytics fits mobile with its report action available');
    await screenshot('mobile-analytics');
    await navigate({path:'superadmin/units.php',actor:6});
    await check("[...document.querySelectorAll('.unit-filters input,.unit-filters select')].every(field=>field.getBoundingClientRect().height<=60)",'Mobile management filters remain compact');
    await evaluate("document.querySelector('.unit-vacancy-form button').click()");
    await wait("document.querySelector('#vacancyConfirmOverlay').classList.contains('open')");
    await check("document.activeElement.id==='vacancyConfirmCancel'",'Unit vacancy confirmation focuses the safe action');
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    await check("!document.querySelector('#vacancyConfirmOverlay').classList.contains('open')",'Escape dismisses the legacy unit confirmation');
    await navigate({path:'superadmin/announcements.php',actor:6});
    await evaluate("document.querySelector('button[onclick=\"openCreateModal()\"]').click()");
    await wait("document.querySelector('#announcementModal').classList.contains('open')");
    await check("document.activeElement.classList.contains('modal-close')",'Announcement editor receives keyboard focus');
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    await check("!document.querySelector('#announcementModal').classList.contains('open')",'Escape dismisses the announcement editor');
    await navigate(amenity);
    await evaluate("document.querySelector('#amenityExploreTab').click();document.querySelector('[data-open-booking=function-hall]').click();{const f=document.querySelector('.amenity-hall-form');const d=new Date();d.setDate(d.getDate()+16);f.elements.booking_date.value=d.toLocaleDateString('en-CA');f.elements.booking_time.value='10:00';f.elements.attendees.value='12';f.requestSubmit();}");
    await wait("location.hash==='#yourBookings' && document.querySelector('#yourBookings') && !document.querySelector('#yourBookings').hidden && document.querySelector('.alert.success') && !!window.CelandineUi && !!window.CelandineMotion");
    await check("document.querySelector('#yourBookings').textContent.includes('Function Hall') && document.querySelector('#yourBookings').textContent.includes('12 guests')",'Function Hall form retains its own submission workflow');
    await evaluate("document.querySelector('#yourBookings form button[type=submit]').click()");
    await wait("document.querySelector('.system-confirm-dialog')?.open===true");
    await evaluate("document.querySelector('.system-confirm-accept').click()");
    await wait("!!window.CelandineUi && !!document.querySelector('.alert.success') && !document.querySelector('#yourBookings form')");
    await check("document.querySelector('#yourBookings').textContent.includes('Cancelled')",'Function Hall cancellation retains reservation history');
    await evaluate("document.querySelector('#amenityExploreTab').click();document.querySelector('.amenity-featured-grid [data-open-image]').click()");
    await check("document.querySelector('#amenityLightbox').open",'Local amenity image opens the lightbox');
    await evaluate("document.querySelector('#amenityLightbox [data-close-amenity]').click()");
    await wait("!document.querySelector('#amenityLightbox').open");
    await check("!document.querySelector('#amenityLightbox').open",'Animated lightbox closes successfully');
    await navigate({path:'resident/permits.php',actor:1});
    await evaluate("{const form=document.querySelector('#permitRequestForm');const category=document.querySelector('#permitCategory');category.value='Delivery';category.dispatchEvent(new Event('change',{bubbles:true}));const date=new Date();date.setDate(date.getDate()+2);form.elements.start_date.value=date.toLocaleDateString('en-CA');document.querySelector('#legacyPermitFields [name=details]').value='UI confirmation fixture';form.requestSubmit();}");
    await wait("!!window.CelandineUi && !!window.CelandineMotion && !!document.querySelector('.alert.success') && !!document.querySelector('form[data-confirm]')");
    await check("document.querySelector('#permitHistory tbody tr').textContent.includes('Delivery') && document.querySelector('#permitHistory tbody tr').dataset.permitStatus==='pending'",'Permit conditional fields retain their submission workflow');
    await evaluate("{const form=document.querySelector('form[data-confirm]');window.nativeConfirmCalls=0;window.permitSubmitCount=0;window.confirm=()=>{window.nativeConfirmCalls++;return false;};form.addEventListener('submit',event=>{event.preventDefault();window.permitSubmitCount++;});form.querySelector('button').click();}");
    await wait("document.querySelector('.system-confirm-dialog')?.open===true");
    await evaluate("document.querySelector('.system-confirm-accept').click()");
    await check("window.nativeConfirmCalls===0 && window.permitSubmitCount===1",'Permit confirmation resumes once without a duplicate native prompt');
    await navigate({path:'admin/admin_dashboard.php',actor:4});
    await check("!document.querySelector('#payments a[href*=unitpayments]')",'Admin dashboard omits inaccessible billing actions');
    fs.writeFileSync(reportFile,JSON.stringify(report,null,2));
    console.log('Screenshots: '+directory);
    console.log(`Browser coverage: ${report.pages.length} role/page/viewport checks; ${report.interactions.length} interactions; ${report.failures.length} layout/resource/console failures.`);
    if(report.failures.length) { console.log(JSON.stringify(report.failures,null,2)); process.exitCode=1; }
  } catch(error) {
    let feedback;
    // Capture useful feedback without storing field values, session tokens or URLs.
    try { feedback=await feedbackReader?.(); } catch {}
    report.failures.push({interactionError:String(error),feedback}); throw error;
  } finally {
    fs.writeFileSync(reportFile,JSON.stringify(report,null,2));
    socket?.close(); chrome.kill();
  }
})().catch(error => { console.error(error); process.exitCode=1; });
