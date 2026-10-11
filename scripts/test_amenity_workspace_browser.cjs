/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'resident/book_amenity.php')) throw Error('Amenity fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-amenity-workspace-'));
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
    const check=async(expression,label)=>{if(!await ev(expression)){console.error('Browser errors:',errors);console.error(await ev('document.body.innerText'));throw Error(label);}checks++;console.log('PASS '+label);};
    const shot=async name=>{const result=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(result.data,'base64'));};
    const go=async(actor,pathname)=>{
      await ev('window.amenityNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.amenityNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-amenity-view="${key}"]').click()`);await sleep(300);};
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    const active = key => "!document.getElementById('"+key+"').hidden";
    const day = offset => new Date(Date.now()+offset*86400000).toLocaleDateString('en-CA',{timeZone:'Asia/Manila'});
    const poolDate=day(4),hallDate=day(3);
    const loaded = "!window.amenityNavigating && document.readyState==='complete' && !!window.CelandineUi";
    const setDate = async(amenity,date) => {
      if (amenity === 'Function Hall') {
        await ev("(()=>{const f=document.querySelector('[data-hall-calendar]');f.elements.month.value='"+date.slice(0,7)+"';f.elements.month.dispatchEvent(new Event('change',{bubbles:true}));})()");
        await until("document.querySelector('[data-calendar-days]').children.length>=28 && !document.querySelector('[data-calendar-days]').hasAttribute('aria-busy')");
        return;
      }
      await ev("(()=>{const f=document.querySelector('[data-amenity-schedule=\""+amenity+"\"]');f.elements.booking_date.value='"+date+"';f.elements.booking_date.dispatchEvent(new Event('change',{bubbles:true}));})()");
      await until("document.querySelector('[data-amenity-schedule=\""+amenity+"\"] [data-time-slots]').children.length===15 && !document.querySelector('[data-amenity-schedule=\""+amenity+"\"] [data-time-slots]').hasAttribute('aria-busy')");
    };
    await go(1,'resident/book_amenity.php');
    await check("[...document.querySelectorAll('[data-amenity-view]')].map(t=>t.dataset.amenityView).join('|')==='exploreAmenities|yourBookings|bookingHistory' && "+active('exploreAmenities'),'owner sees Explore amenities, My reservations, History');
    await ev('window.tabMarker=1');await click('yourBookings');
    await check("window.tabMarker===1 && "+active('yourBookings')+" && document.querySelectorAll('#yourBookings .amenity-history-card').length===1",'My reservations opens without reload and contains the active booking');
    await click('bookingHistory');
    await check(active('bookingHistory')+" && document.querySelectorAll('#bookingHistory .amenity-history-card').length===0",'active owner booking is excluded from History');
    await ev('history.back()');await until(active('yourBookings'));
    await check(active('yourBookings'),'browser Back restores the previous tab');
    await go(2,'resident/book_amenity.php');
    await check("document.querySelectorAll('.amenity-history-card').length===0",'tenant sees only their own reservations');
    await ev("document.querySelector('[data-open-booking=swimming-pool]').click()");
    await check("document.querySelector('#amenityBookingDialog').open",'existing swimming pool reservation dialog opens');
    await ev("(()=>{const f=document.querySelector('[data-pool-reservation]');f.elements.booking_date.value='"+poolDate+"';f.elements.duration_hours.value='2';f.elements.booking_date.dispatchEvent(new Event('change',{bubbles:true}));})()");
    await until("!!document.querySelector('[data-pool-reservation] [data-start=\"12:00\"]:not(:disabled)')");
    await ev("document.querySelector('[data-pool-reservation] [data-start=\"12:00\"]').click()");
    await check("!document.querySelector('[data-reservation-submit]').disabled && document.querySelector('[data-summary-fee]').textContent.includes('600')",'pool availability, time selection and fee summary remain functional');
    await ev("window.amenityNavigating=true;document.querySelector('[data-pool-reservation]').requestSubmit()");
    await until(loaded);
    await check(active('yourBookings')+" && document.querySelector('#yourBookings').textContent.includes('Swimming Pool')",'pool submission opens My reservations');
    const poolId=await ev("Number(document.querySelector('#yourBookings .amenity-history-card .amenity-kicker').textContent.match(/[0-9]+/)[0])");
    await go(4,'superadmin/bookingrequest.php');
    await check("[...document.querySelectorAll('[data-amenity-view]')].map(t=>t.textContent.replace(/[0-9]/g,'').trim()).join('|')==='Booking Requests|Availability|History' && "+active('bookingRequests'),'admin sees Booking Requests, Availability, History in order');
    await ev("(()=>{const f=document.querySelector('[data-booking-decision=\""+poolId+"\"]');f.closest('details').open=true;window.amenityNavigating=true;f.requestSubmit(f.querySelector('[value=approved]'));})()");
    await until(loaded);
    await check("document.querySelector('[data-booking-decision=\""+poolId+"\"]').closest('tr').textContent.includes('Bill #') && document.querySelector('[data-booking-decision=\""+poolId+"\"]').closest('tr').textContent.includes('Approved')",'pool approval keeps its unit-account billing integration');
    await go(2,'resident/book_amenity.php#yourBookings');
    await ev("window.amenityNavigating=true;document.querySelector('#yourBookings form').requestSubmit()");
    await ev("document.querySelector('.system-confirm-accept')?.click()");
    await until(loaded);
    await check(active('bookingHistory')+" && document.querySelector('#bookingHistory').textContent.includes('Cancelled') && document.querySelectorAll('#yourBookings .amenity-history-card').length===0",'resident cancellation moves the booking into History and opens it');
    await click('exploreAmenities');
    await ev("document.querySelector('[data-open-booking=function-hall]').click();(()=>{const f=document.querySelector('.amenity-hall-form');f.elements.booking_date.value='"+hallDate+"';f.elements.booking_time.value='08:00';f.elements.attendees.value='20';window.amenityNavigating=true;f.requestSubmit()})()");
    await until(loaded);
    await check(active('yourBookings')+" && document.querySelector('#yourBookings').textContent.includes('Function Hall')",'existing hall reservation form submits into My reservations');
    const hallId=await ev("Number(document.querySelector('#yourBookings .amenity-kicker').textContent.match(/[0-9]+/)[0])");
    await go(6,'superadmin/bookingrequest.php');
    await click('amenityAvailability');
    await check(active('amenityAvailability')+" && document.querySelectorAll('[data-amenity-schedule]').length===1 && !!document.querySelector('[data-hall-calendar]')",'Availability displays both Swimming Pool and Function Hall schedules');
    await setDate('Function Hall',hallDate);
    await check("!!document.querySelector('[data-calendar-days] [data-date=\""+hallDate+"\"][data-state=booked]')",'pending hall request marks its full day as Booked');
    await setDate('Swimming Pool',hallDate);
    await check("document.querySelectorAll('[data-amenity-schedule=\"Swimming Pool\"] [data-state=available]').length===15 && !!document.querySelector('[data-calendar-days] [data-date=\""+hallDate+"\"][data-state=booked]')",'pool and hall schedule requests remain independent');
    await shot('availability-desktop');
    await click('bookingRequests');
    await ev("(()=>{const f=document.querySelector('[data-booking-decision=\""+hallId+"\"]');f.closest('details').open=true;f.elements.reason.value='Event cannot proceed';window.amenityNavigating=true;f.requestSubmit(f.querySelector('[value=rejected]'));})()");
    await until(loaded);
    await check(active('bookingHistory')+" && document.querySelector('#bookingHistory').textContent.includes('Rejected') && !document.querySelector('#bookingRequests').textContent.includes('#"+hallId+"')",'superadmin rejection moves the booking into History');
    await click('amenityAvailability');await setDate('Function Hall',hallDate);
    await check("!!document.querySelector('[data-calendar-days] [data-date=\""+hallDate+"\"][data-state=available]')",'hall rejection marks its day as Available');
    await setDate('Function Hall','2028-02-01');
    await check("document.querySelectorAll('[data-calendar-days] [data-date]').length===29 && document.querySelector('[data-calendar-days] [data-date]').style.gridColumnStart==='3' && !document.querySelector('[data-hall-calendar] [data-time-slots]')",'hall calendar shows leap-year days aligned to weekdays without time slots');
    await ev("document.querySelector('[data-calendar-step=\"1\"]').click()");
    await until("document.querySelectorAll('[data-calendar-days] [data-date]').length===31 && !document.querySelector('[data-calendar-days]').hasAttribute('aria-busy')");
    await check("document.querySelector('[data-hall-calendar]').elements.month.value==='2028-03'",'next month updates the daily calendar');
    await setDate('Function Hall','2028-12-01');
    await ev("document.querySelector('[data-calendar-step=\"1\"]').click()");
    await until("!!document.querySelector('[data-date=\"2029-01-01\"]')");
    await check("document.querySelector('[data-hall-calendar]').elements.month.value==='2029-01'",'calendar month navigation crosses year boundaries');
    await setDate('Function Hall','2020-01-01');
    await check("document.querySelectorAll('[data-calendar-days] [data-state=past]').length===31",'past unbooked days are marked Past');
    await ev("document.querySelector('[data-hall-calendar]').elements.month.value='';document.querySelector('[data-hall-calendar]').elements.month.dispatchEvent(new Event('change',{bubbles:true}))");
    await check("!document.querySelector('[data-calendar-days]').children.length && document.querySelector('[data-calendar-message]').textContent.includes('valid month')",'clearing the month removes stale days and asks for a valid month');
    await setDate('Function Hall',hallDate);
    await ev("document.querySelector('[data-hall-calendar]').closest('section').scrollIntoView({block:'start'})");
    await shot('hall-calendar-desktop');
    await go(4,'superadmin/bookingrequest.php?status=rejected');
    await check(active('bookingHistory')+" && document.querySelectorAll('#bookingHistory tbody tr').length===1 && document.querySelector('#bookingHistory').textContent.includes('Rejected')",'legacy rejected-status URL opens filtered History');
    await go(2,'resident/book_amenity.php#bookingHistory');
    await check("document.querySelectorAll('#bookingHistory .amenity-history-card').length===2 && document.querySelectorAll('#yourBookings .amenity-history-card').length===0",'tenant History contains their cancelled pool booking and rejected hall booking');
    for(const width of [768,390,320]){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
      for(const [actor,route,key] of [[2,'resident/book_amenity.php','bookingHistory'],[4,'superadmin/bookingrequest.php','amenityAvailability']]){
        await go(actor,route);await click(key);
        await check("document.documentElement.scrollWidth<=innerWidth+1 && [...document.querySelectorAll('[data-amenity-view]')].every(t=>{const r=t.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.height>=40})",'amenity tabs fit '+width+'px for actor '+actor);
        if(actor===4){
          await until("document.querySelector('[data-calendar-days]').children.length>=28");
          await check("[...document.querySelectorAll('.amenity-calendar-day')].every(cell=>cell.scrollWidth<=cell.clientWidth+1)",'calendar day labels fit '+width+'px');
          await ev("document.querySelector('[data-hall-calendar]').closest('section').scrollIntoView({block:'start'})");
        }
        if(width===320)await shot('amenity-mobile-'+actor);
      }
    }
    await go(5,'superadmin/bookingrequest.php');
    await check("!document.querySelector('#amenityApp')",'Security cannot access the admin booking workspace');
    if(errors.length)throw Error(errors.join('\n'));
    console.log('Passed '+checks+' amenity workspace browser checks. Screenshots: '+directory);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
