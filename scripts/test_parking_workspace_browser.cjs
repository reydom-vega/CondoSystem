/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'resident/parking.php')) throw Error('Parking fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-parking-workspace-'));
  const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let socket; let checks=0; const errors=[];
  try {
    let target;
    for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(tab=>tab.type==='page');if(target)break;}catch{}await sleep(100);}
    if(!target)throw Error('Chrome unavailable');
    socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
    let number=0;const calls=new Map();
    socket.onmessage=event=>{const message=JSON.parse(event.data),call=calls.get(message.id);if(call){calls.delete(message.id);clearTimeout(call.timer);message.error?call.reject(message.error):call.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);};
    const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++number,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout: '+method));},20000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
    const ev=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
    const until=async expression=>{for(let i=0;i<150;i++){if(await ev(expression))return;await sleep(100);}throw Error('Timed out: '+expression);};
    const check=async(expression,label)=>{if(!await ev(expression)){console.error('Browser errors:',errors);console.error(await ev("JSON.stringify({count:document.querySelector('[data-record-count]')?.textContent,appCount:document.querySelector('.parking-workspace [data-record-count]')?.textContent,post:window.parkingPostResult,rows:[...document.querySelectorAll('[data-parking-record]')].map(row=>({hidden:row.hidden,text:row.textContent})),panels:[...document.querySelectorAll('[data-parking-panel]')].map(p=>({id:p.id,inMain:!!p.closest('.parking-workspace')}))})"));throw Error(label);}checks++;console.log('PASS '+label);};
    const shot=async name=>{const result=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
    const go=async(actor,pathname)=>{
      await ev('window.parkingNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.parkingNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-parking-tab="${key}"]').click()`);await sleep(300);};
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    for (const actor of [1,2]) {
      await go(actor,'resident/parking.php');
      await check("document.querySelectorAll('[data-parking-tab]').length===3 && document.querySelectorAll('.sidebar-link[href=\"parking.php\"]').length===1 && !document.querySelector('.sidebar-link[href=\"vehicles.php\"]')",`resident/tenant ${actor}: three tabs and one sidebar item`);
      await ev("window.parkingDocumentMarker=123;document.querySelector('[data-parking-tab=vehicles]').click();document.querySelector('[name=make]').value='Unsaved vehicle'");
      await click('parking'); await click('vehicles');
      await check("window.parkingDocumentMarker===123 && document.querySelector('[name=make]').value==='Unsaved vehicle' && !document.querySelector('#parking-panel-vehicles').hidden",`resident/tenant ${actor}: smooth tabs preserve inputs without reload`);
      await ev("history.back()"); await sleep(300);
      await check("!document.querySelector('#parking-panel-parking').hidden",`resident/tenant ${actor}: Back restores tab`);
      await ev("history.back()"); await sleep(300);
      await ev("history.back()"); await sleep(300);
      await check("!document.querySelector('#parking-panel-parking').hidden && !new URL(location.href).searchParams.has('tab')",`resident/tenant ${actor}: Back restores the initial page tab`);
      await ev("document.querySelector('[data-parking-tab=parking]').dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}))");
      await check("document.querySelector('[data-parking-tab=requests]').getAttribute('aria-selected')==='true' && document.activeElement.dataset.parkingTab==='requests'",`resident/tenant ${actor}: keyboard tabs`);
      await check("document.querySelectorAll('#parking-panel-parking .booking-item').length===0 && [...document.querySelectorAll('.booking-item')].every(row=>row.closest('#parking-panel-requests'))",`resident/tenant ${actor}: request history lives in Requests`);
      await check("Number(document.querySelector('[data-parking-tab=requests] .parking-tab-count').textContent)===document.querySelectorAll('#parking-panel-requests .booking-item,#parking-panel-requests .sticker-order-summary').length",`resident/tenant ${actor}: Requests badge matches visible records`);
      await check("getComputedStyle(document.querySelector('[data-parking-tab=requests]')).backgroundColor==='rgb(48, 48, 68)' && getComputedStyle(document.querySelector('[data-parking-tab=requests]')).color==='rgb(255, 225, 172)'",`resident/tenant ${actor}: tabs match the amenity design`);
      await shot('resident-'+actor+'-requests-desktop');
      await go(actor,'resident/vehicles.php');
      await check("!document.querySelector('#parking-panel-vehicles').hidden && document.querySelector('.sidebar-link[href=\"parking.php\"]').getAttribute('aria-current')==='page'",`resident/tenant ${actor}: old vehicle URL and active menu`);
    }
    await go(1,'resident/parking.php?tab=requests');
    await check("(async()=>{const form=document.querySelector('#parking-panel-requests form');const response=await fetch(form.getAttribute('action'),{method:'POST',body:new URLSearchParams(new FormData(form))});return response.ok && response.url.includes('tab=requests') && (await response.text()).includes('Cancelled');})()",'parking cancellation returns to Requests through the existing handler');
    for (const actor of [4,6]) {
      await go(actor,'superadmin/parking.php');
      await check("[...document.querySelectorAll('[data-parking-tab]')].map(t=>t.textContent).join('|')==='Overview|Parking Slots|Registered Vehicles|Sticker Requests|Visitor Parking|Parking Records'",`staff ${actor}: requested six tabs`);
      await ev('window.parkingDocumentMarker=456');
      for (const key of ['slots','vehicles','stickers','visitors','records','overview']) await click(key);
      await check('window.parkingDocumentMarker===456',`staff ${actor}: all tabs switch without page reload`);
      await check(`Boolean(document.querySelector('[name=sticker_price]'))===${actor===6} && Boolean(document.querySelector('.parking-status-form'))===${actor===6}`,`staff ${actor}: inventory changes and configuration remain Superadmin-only`);
      for (const route of ['registeredvehicles.php','parkinginventory.php']) {
        await go(actor,'superadmin/'+route);
        await check(`!document.querySelector('#parking-panel-${route==='registeredvehicles.php'?'vehicles':'slots'}').hidden && document.querySelectorAll('.sidebar-link[aria-current=page]').length===1`,`staff ${actor}: ${route} opens matching tab`);
      }
      await click('records');
      await ev("document.querySelector('[name=record_search]').value='no-such-parking-record';document.querySelector('[name=record_search]').dispatchEvent(new Event('input',{bubbles:true}))");
      await check("document.querySelector('[data-record-count]').textContent==='0 records' && [...document.querySelectorAll('[data-parking-record]')].every(row=>row.hidden)",`staff ${actor}: records search filters locally`);
      await click('overview'); await shot('staff-'+actor+'-desktop');
    }
    await go(6,'superadmin/parking.php?tab=slots');
    await check("(async()=>{const form=document.querySelector('.parking-status-form');const data=new URLSearchParams(new FormData(form));data.set('status','occupied');const response=await fetch(form.getAttribute('action'),{method:'POST',body:data});const html=await response.text();window.parkingPostResult={status:response.status,url:response.url,slotsTag:html.match(/id=.parking-panel-slots[^>]*>/)?.[0],alerts:[...new DOMParser().parseFromString(html,'text/html').querySelectorAll('.alert')].map(a=>a.textContent)};return response.ok && html.includes('Parking slot status updated.') && /id=\"parking-panel-slots\"[^>]*data-parking-panel=\"slots\"(?! hidden)/.test(html);})()",'Superadmin inventory POST keeps the selected tab and updates occupancy');
    await check("(async()=>{const form=document.querySelector('form[action=\"parking_configuration.php\"]');const response=await fetch(form.getAttribute('action'),{method:'POST',body:new URLSearchParams(new FormData(form))});return response.ok && response.url.includes('parking.php?tab=slots') && (await response.text()).includes('Parking configuration saved.');})()",'Superadmin configuration POST returns to Parking Slots');
    await go(1,'resident/vehicles.php');
    await ev(`(()=>{const form=document.querySelector('form[action="vehicles.php"]');for(const [key,value] of Object.entries({make:'Toyota',model:'Vios',color:'Silver',year:'2025',plate_number:'TAB1234'}))form.elements[key].value=value;const data=new DataTransfer();data.items.add(new File([Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jwXcAAAAASUVORK5CYII='),c=>c.charCodeAt(0))],'registration.png',{type:'image/png'}));form.elements.or_cr.files=data.files;window.parkingNavigating=true;form.requestSubmit();})()`);
    await until("!window.parkingNavigating && document.readyState==='complete' && !!document.querySelector('.vehicle-card')");
    await check("document.querySelector('.vehicle-card').textContent.includes('TAB1234') && !document.querySelector('#parking-panel-vehicles').hidden",'vehicle multipart form submits through existing URL and returns to vehicle tab');
    await go(1,'resident/parking.php#vehicle-1');
    await check("!document.querySelector('#parking-panel-vehicles').hidden",'legacy vehicle bookmark reveals its tab');
    await go(2,'resident/parking.php?tab=vehicles');
    await check("![...document.querySelectorAll('.vehicle-card')].some(card=>card.textContent.includes('TAB1234'))",'tenant cannot view owner vehicle in their registry');
    await go(4,'superadmin/parking.php?tab=vehicles');
    await ev("(()=>{const form=[...document.querySelectorAll('.vehicle-card form')].find(form=>form.closest('.vehicle-card').textContent.includes('TAB1234'));window.parkingNavigating=true;form.requestSubmit(form.querySelector('[value=approved]'));})()");
    await until("!window.parkingNavigating && document.readyState==='complete' && !!document.querySelector('.vehicle-card')");
    await check("document.querySelector('.vehicle-card .unit-status').textContent.trim()==='Approved' && !document.querySelector('#parking-panel-vehicles').hidden",'Admin vehicle approval retains controller and selected tab');
    await go(1,'resident/parking.php');
    await check("document.querySelector('#stickerOrderForm').textContent.includes('TAB1234')",'approved vehicle becomes eligible for a sticker');
    await ev("(()=>{const form=document.getElementById('stickerOrderForm');form.querySelector('[name=\"vehicle_ids[]\"]').checked=true;window.parkingNavigating=true;form.requestSubmit();})()");
    await until("!window.parkingNavigating && document.readyState==='complete' && location.pathname.endsWith('/payments.php')");
    await check("document.body.textContent.toLowerCase().includes('parking sticker')",'sticker request creates the existing parking bill and opens Billing & Payments');
    await go(1,'resident/parking.php?tab=requests');
    await check("document.querySelector('#parking-panel-requests .sticker-order-summary').textContent.includes('TAB1234') && document.querySelector('#parking-panel-requests .sticker-order-summary').textContent.includes('Awaiting payment') && !document.querySelector('#parking-panel-parking .sticker-order-summary')",'submitted sticker requests and payment status appear in Requests');
    await go(2,'resident/parking.php?tab=requests');
    await check("!document.querySelector('#parking-panel-requests .sticker-order-summary') && !document.querySelector('#parking-panel-requests .booking-item')",'tenant Requests does not expose another resident records');
    await go(4,'superadmin/parking.php?tab=stickers');
    await check("document.querySelector('#parking-panel-stickers').textContent.includes('TAB1234') && !document.querySelector('[name=action][value=issue_sticker]')",'unpaid sticker is visible to Admin and cannot be issued');
    for (const actor of [1,2,4,6]) {
      await go(actor,(actor<3?'resident':'superadmin')+'/parking.php');
      for(const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await sleep(180);
        await click(actor<3?'requests':'slots');
        await check('document.documentElement.scrollWidth<=innerWidth+1',`actor ${actor}: no page overflow at ${width}px`);
        if(width===390)await shot('actor-'+actor+'-mobile');
      }
    }
    await go(4,'security/scanner.php');
    await check("(async()=>{const token=document.querySelector('[data-csrf]').dataset.csrf;const response=await fetch('../superadmin/parkinginventory.php',{method:'POST',body:new URLSearchParams({csrf_token:token,action:'update_status',slot_id:'1',status:'occupied'})});return response.status===403;})()",'Admin cannot forge a Superadmin inventory mutation');
    await check("(async()=>{const response=await fetch('../superadmin/parking.php',{method:'POST',body:new URLSearchParams({action:'review_vehicle',vehicle_id:'1',decision:'approved'})});return response.status===403;})()",'consolidated actions still require CSRF');
    if(errors.length)throw Error(errors.join('\n'));
    console.log(`Passed ${checks} parking workspace browser checks. Screenshots: ${directory}`);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
