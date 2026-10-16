/* Run against test_ui_redesign.php --resident-dashboard-only --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.label === 'Resident dashboard')) throw Error('Parking fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-resident-dashboard-'));
  const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let socket; let checks=0; const errors=[],dashboardResponses=[];
  try {
    let target;
    for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab=>tab.type==='page');if(target)break;}catch{}await sleep(100);}
    if(!target)throw Error('Chrome unavailable');
    socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
    let number=0;const calls=new Map();
    socket.onmessage=event=>{const message=JSON.parse(event.data),call=calls.get(message.id);if(call){calls.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Network.responseReceived'&&message.params.response.url.includes('/api/dashboard.php'))dashboardResponses.push({url:message.params.response.url,status:message.params.response.status});if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);};
    const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++number,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout: '+method));},20000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
    const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
    const until=async expression=>{for(let i=0;i<150;i++){try{if(await ev(expression))return;}catch(error){if(error.code!==-32000||!/navigated|context.*destroyed|Cannot find context/i.test(error.message||''))throw error;}await sleep(100);}throw Error('Timed out: '+expression);};
    const check=async(expression,label)=>{if(!await ev(expression)){await shot('failure');throw Error(label);}checks++;};
    const shot=async name=>{const result=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
    const go=async(actor,pathname)=>{
      await ev('window.parkingNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.parkingNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-parking-tab="${key}"]').click()`);await sleep(300);};


    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    for(const actor of [1,2])for(const theme of ['dark','light'])for(const width of [1366,768,390,320]) {
      await send('Emulation.setDeviceMetricsOverride',{width,height:950,deviceScaleFactor:1,mobile:false});
      await go(actor,'resident/dashboard.php');await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);
      await check('document.documentElement.scrollWidth<=innerWidth+1',`no overflow ${actor}/${theme}/${width}`);
      await check("document.querySelector('.resident-dashboard-grid').firstElementChild.matches('.resident-dashboard-announcements')&&document.querySelector('.resident-dashboard-announcements').getBoundingClientRect().top<innerHeight",`announcements are prominent in the first viewport ${actor}/${theme}/${width}`);
      await check("document.querySelectorAll('.resident-announcement').length===2&&document.querySelector('.resident-announcement-featured').dataset.announcementPriority==='high'&&document.querySelector('.resident-announcement-featured .badge').textContent==='Important'",`active announcements and important badge ${actor}/${theme}/${width}`);
      await check("!document.querySelector('.resident-dashboard-announcements').textContent.includes('Expired notice must stay private')&&!document.querySelector('.resident-dashboard-announcements').textContent.includes('Inactive notice must stay private')&&!window.announcementInjected",`expired and inactive notices excluded; text escaped ${actor}/${theme}/${width}`);
      await check(`document.querySelector('.welcome-role-badge').textContent.trim()===${JSON.stringify(actor===1?'Unit Owner':'Tenant')}&&document.querySelector('.stats-grid a[href="book_amenity.php"] .stat-value').textContent.trim()===${JSON.stringify(actor===1?'1':'0')}`,`role and upcoming booking totals ${actor}/${theme}/${width}`);
      await ev("document.querySelector('.resident-announcement-full summary').click()");
      await check("document.querySelector('.resident-announcement-full').open&&document.querySelector('.dashboard-announcement-content').textContent.includes('Complete notice end.')",`complete announcement can be expanded ${actor}/${theme}/${width}`);
      await ev("document.querySelector('.resident-announcement-full summary').click()");
      if(width===1366||width===390){await ev('scrollTo(0,0)');await shot(`dashboard-${actor}-${theme}-${width}`);}
      await check(`!!document.querySelector('.resident-dashboard-services a[href="reports.php"]')&&!!document.querySelector('.resident-dashboard-services a[href="messages.php"]')&&!!document.querySelector('.resident-dashboard-side .notice-banner')===${actor===2}`,`services and tenant billing guidance ${actor}/${theme}/${width}`);
    }
    await go(1,'resident/dashboard.php');
    await ev("document.querySelector('.resident-announcement-full summary').focus()");
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:' ',code:'Space',windowsVirtualKeyCode:32});await send('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32});
    await until("document.querySelector('.resident-announcement-full').open");
    await check("document.querySelector('.resident-announcement-full').open",'announcement expands using the keyboard');
    await ev(`document.querySelector('.resident-dashboard-announcements a[href="announcements.php"]').click()`);await until("location.pathname.endsWith('/announcements.php')&&document.readyState==='complete'");
    await check("document.querySelector('.dash-title').textContent==='All Announcements'",'View all uses the existing announcements page');
    await go(3,'resident/dashboard.php');
    await check("!!document.querySelector('#approvalModal')&&!document.querySelector('.resident-announcement')&&!document.querySelector('.resident-dashboard-side')&&document.querySelector('.resident-dashboard-announcements').textContent.includes('once your account is approved')",'pending applicants retain approval gate and cannot see notices or service shortcuts');
    await check("!document.querySelector('script[data-endpoint]')",'pending applicants do not poll the approved dashboard endpoint');
    await go(1,'resident/dashboard.php');await sleep(300);
    await check(`${JSON.stringify(dashboardResponses)}.length>0`,'dashboard endpoint was exercised');
    if(dashboardResponses.some(r=>r.status!==200||!r.url.startsWith(base+'api/dashboard.php')))throw Error('Incorrect dashboard endpoint: '+JSON.stringify(dashboardResponses));
    checks++;
    await send('Emulation.setScriptExecutionDisabled',{value:true});
    await send('Page.navigate',{url:base+'resident/dashboard.php?fixture_actor=1'});await until("document.readyState==='complete'");
    await check("getComputedStyle(document.querySelector('.resident-dashboard-announcements')).display!=='none'&&!!document.querySelector('.resident-announcement-full summary')",'announcements remain readable without JavaScript');
    await send('Emulation.setScriptExecutionDisabled',{value:false});
    if(errors.length)throw Error(errors.join('\n'));
    console.log(`Passed ${checks} resident dashboard browser checks. Screenshots: ${directory}`);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
