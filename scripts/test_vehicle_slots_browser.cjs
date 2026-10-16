/* Run against test_ui_redesign.php --vehicle-slots-only --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.label === 'Registered vehicle slot assignment')) throw Error('Parking fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-vehicle-slots-'));
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
    const until=async expression=>{for(let i=0;i<150;i++){try{if(await ev(expression))return;}catch(error){if(error.code!==-32000||!/navigated|context.*destroyed|Cannot find context/i.test(error.message||''))throw error;}await sleep(100);}throw Error('Timed out: '+expression);};
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
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    for(const actor of [4,6]) {
      await send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
      await go(actor,'superadmin/parking.php?tab=vehicles');
      await check("document.querySelectorAll('#parking-panel-vehicles .vehicle-slot-form').length===2",`manager ${actor}: paid issued owner and tenant vehicles expose slot controls`);
      await check("!document.querySelector('[data-parking-search-text*=SLOT-NOSTICKER] .vehicle-slot-form')",`manager ${actor}: vehicle without an issued sticker has no assignment control`);
      await check("(()=>{const c=document.querySelector('[data-parking-search-text*=SLOT-PENDING-ACCOUNT]');return c.querySelector('.badge').textContent==='Pending account'&&c.querySelector('.badge').classList.contains('pending')&&!c.querySelector('.badge').classList.contains('approved')&&!c.querySelector('.vehicle-slot-form');})()",`manager ${actor}: pending account overrides vehicle approval and hides slot assignment`);
      await check("[...document.querySelectorAll('.vehicle-slot-form select option')].every(o=>!o.textContent.includes('SLOT-MAINTENANCE')&&!o.textContent.includes('UI-V01')&&!o.textContent.includes('SLOT-CURRENT'))",`manager ${actor}: only free resident slots are offered`);
      for(const theme of ['dark','light'])for(const width of [1366,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
        await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);
        await check("[...document.querySelectorAll('#parking-panel-vehicles .vehicle-slot-form')].every(f=>{const s=f.querySelector('select').getBoundingClientRect(),b=f.querySelector('button').getBoundingClientRect();return s.left>=0&&s.right<=innerWidth&&b.left>=0&&b.right<=innerWidth&&s.width>100&&b.height>=40&&(Math.abs(s.y-b.y)<4||b.top>=s.bottom);})&&document.documentElement.scrollWidth<=innerWidth+1",`manager ${actor}: slot controls fit ${theme} ${width}px`);
        if(width===390) {await ev("document.querySelector('.vehicle-slot-form').scrollIntoView({block:'center'})");await shot(`slot-controls-${actor}-${theme}-mobile`);}
      }
      const plate=actor===4?'SLOT-TENANT':'SLOT-OWNER',slot=actor===4?'SLOT-FREE-A':'SLOT-FREE-B';
      await ev(`window.__slotForm=[...document.querySelectorAll('.vehicle-card')].find(n=>n.querySelector('h3').textContent===${JSON.stringify(plate)}).querySelector('.vehicle-slot-form');window.__slotForm.elements.slot_id.value=[...window.__slotForm.elements.slot_id.options].find(o=>o.textContent.includes(${JSON.stringify(slot)})).value;`);
      await check("(async()=>{const d=new URLSearchParams(new FormData(window.__slotForm));d.set('csrf_token','invalid');return (await fetch(window.__slotForm.getAttribute('action'),{method:'POST',body:d})).status===403;})()",`manager ${actor}: saving requires CSRF`);
      await ev("window.parkingNavigating=true;window.__slotForm.requestSubmit()");
      await until("!window.parkingNavigating&&document.readyState==='complete'&&!!document.querySelector('.parking-workspace')");
      await check(`!document.querySelector('#parking-panel-vehicles').hidden&&document.querySelector('.parking-workspace').textContent.includes('Resident vehicle parking slot updated.')&&[...document.querySelectorAll('.vehicle-card')].find(n=>n.querySelector('h3').textContent===${JSON.stringify(plate)}).textContent.includes(${JSON.stringify(slot)})`,`manager ${actor}: Save slot assigns inventory and stays in Registered Vehicles`);
      await check(`![...document.querySelectorAll('#parking-panel-vehicles .vehicle-slot-form select option')].some(o=>o.textContent.includes(${JSON.stringify(slot)}))`,`manager ${actor}: saved slot disappears from available choices`);
      if(actor===6)await check("[...document.querySelectorAll('#parking-panel-vehicles .vehicle-slot-form select option')].some(o=>o.textContent.includes('SLOT-CURRENT'))",'reassigning an existing vehicle releases its previous slot');
      await go(actor,'superadmin/registeredvehicles.php');
      await check("!document.querySelector('#parking-panel-vehicles').hidden&&document.querySelectorAll('.vehicle-slot-form').length===2",`manager ${actor}: legacy Registered Vehicles route also has slot controls`);
    }
    for(const actor of [1,2]) {
      await go(actor,'resident/parking.php?tab=vehicles');
      await check("!document.querySelector('.vehicle-slot-form')",`resident ${actor}: management slot controls remain staff-only`);
    }
    if(errors.length)throw Error(errors.join('\n'));
    console.log(`Passed ${checks} registered vehicle slot browser checks. Screenshots: ${directory}`);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
