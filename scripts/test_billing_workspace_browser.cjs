/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'resident/payments.php')) throw Error('Billing fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-billing-workspace-'));
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
      await ev('window.billingNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.billingNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-billing-tab="${key}"]').click()`);await sleep(300);};
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    let ownerBill;
    for (const actor of [1,2]) {
      await go(actor,'resident/payments.php');
      await check("[...document.querySelectorAll('[data-billing-tab]')].map(t=>t.dataset.billingTab).join('|')==='billing|history'",`resident/tenant ${actor}: Billing & Payments followed by History`);
      await check("document.querySelectorAll('#billing-panel-billing form').length===1 && document.querySelectorAll('.billing-summary-card').length===3",`resident/tenant ${actor}: one authorized payment form and account summary`);
      if (actor===1) ownerBill=await ev("document.querySelector('[name=bill_id]').value");
      await ev("window.billingMarker=42;document.querySelector('[value=cash]').click()");
      await click('history');
      await check("window.billingMarker===42 && !document.querySelector('#billing-panel-history').hidden && document.querySelector('#billing-panel-billing').hidden",`resident/tenant ${actor}: History opens without reloading`);
      await check("document.querySelectorAll('.history-item').length===1 && Boolean(document.querySelector('.history-receipt-link'))==="+(actor===1),`resident/tenant ${actor}: paid history and receipt access follow existing permissions`);
      await check("getComputedStyle(document.querySelector('[data-billing-tab=history]')).backgroundColor==='rgb(48, 48, 68)' && getComputedStyle(document.querySelector('[data-billing-tab=history]')).color==='rgb(255, 225, 172)'",`resident/tenant ${actor}: matching amenity tab colors`);
      await ev("document.querySelector('.history-item').open=true");
      await shot('resident-'+actor+'-history-desktop');
      await ev('history.back()'); await sleep(250);
      await check("!document.querySelector('#billing-panel-billing').hidden && document.querySelector('[value=cash]').checked && window.billingMarker===42",`resident/tenant ${actor}: Back restores current bills and selected payment method`);
      await ev("document.querySelector('[data-billing-tab=billing]').dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}))");
      await check("document.activeElement.dataset.billingTab==='history' && !document.querySelector('#billing-panel-history').hidden",`resident/tenant ${actor}: keyboard tab navigation`);
      await click('billing');
      await shot('resident-'+actor+'-bills-desktop');
      for (const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await sleep(180);
        for (const key of ['billing','history']) {
          await click(key);
          await check('document.documentElement.scrollWidth<=innerWidth+1',`resident/tenant ${actor}: ${key} fits ${width}px`);
          if(width===390)await shot('resident-'+actor+'-'+key+'-mobile');
        }
      }
      await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    }
    await go(2,'resident/payments.php');
    await check(`(async()=>{const form=document.querySelector('#billing-panel-billing form');const data=new URLSearchParams(new FormData(form));data.set('bill_id','${ownerBill}');data.set('payment_method','cash');const response=await fetch('payments.php',{method:'POST',body:data});return response.status===403;})()`,'tenant cannot pay shared owner dues through a forged POST');
    await check("(async()=>{const form=document.querySelector('#billing-panel-billing form');const data=new URLSearchParams(new FormData(form));data.delete('csrf_token');const response=await fetch('payments.php',{method:'POST',body:data});return response.status===403;})()",'resident payment actions retain CSRF protection');
    // Cash avoids contacting any payment provider; confirmation uses the original controllers.
    await go(1,'resident/payments.php');
    await ev("(()=>{const form=document.querySelector('#billing-panel-billing form');form.querySelector('[value=cash]').click();window.billingNavigating=true;form.requestSubmit();})()");
    await until("!window.billingNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
    await check("document.querySelector('.alert.success').textContent.includes('Cash payment selected') && !document.querySelector('#billing-panel-billing').hidden",'cash selection posts through the original payment handler');
    for (const actor of [6,7]) {
      await go(actor,'superadmin/unitpayments.php');
      await check("document.querySelectorAll('[data-billing-tab]').length===2 && [...document.querySelectorAll('#billing-panel-billing .unit-status')].every(status=>status.textContent.trim()!=='Paid') && [...document.querySelectorAll('#billing-panel-history .unit-status')].every(status=>status.textContent.trim()==='Paid')",`billing staff ${actor}: outstanding bills separated from completed history`);
      await ev('window.billingMarker=84');await click('history');
      await check("window.billingMarker===84 && !document.querySelector('#billing-panel-history').hidden",`billing staff ${actor}: History switches without reloading`);
      await ev("document.getElementById('paymentSearch-history').value='Owner One';window.billingNavigating=true;document.querySelector('#billing-panel-history .unit-filters').requestSubmit()");
      await until("!window.billingNavigating && document.readyState==='complete' && !!window.CelandineUi");
      await check("!document.querySelector('#billing-panel-history').hidden && location.search.includes('tab=history') && !document.querySelector('#billing-panel-history').textContent.includes('Tenant Two')",`billing staff ${actor}: history search preserves tab and filters records`);
      await go(actor,'superadmin/unitpayments.php?status=paid');
      await check("!document.querySelector('#billing-panel-history').hidden",`billing staff ${actor}: old paid-status URL opens History`);
      await go(actor,'superadmin/unitpayments.php?status=pending');
      await check("!document.querySelector('#billing-panel-billing').hidden && [...document.querySelectorAll('#billing-panel-billing .unit-status')].every(status=>status.textContent.trim()==='Pending')",`billing staff ${actor}: existing pending filter works`);
      await shot('staff-'+actor+'-desktop');
      for (const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await sleep(180);
        for (const key of ['billing','history']) {
          await click(key);
          await check('document.documentElement.scrollWidth<=innerWidth+1',`billing staff ${actor}: ${key} fits ${width}px`);
          if(width===390)await shot('staff-'+actor+'-'+key+'-mobile');
        }
      }
      await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    }
    await go(6,'superadmin/unitpayments.php');
    await check("(async()=>{const form=document.querySelector('.manual-payment-confirm');const data=new URLSearchParams(new FormData(form));data.delete('csrf_token');const response=await fetch(form.getAttribute('action'),{method:'POST',body:data});return response.status===403;})()",'staff cash confirmation retains CSRF protection');
    await ev("window.billingNavigating=true;document.querySelector('.manual-payment-confirm').requestSubmit()");
    await until("!window.billingNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
    await check(`!document.querySelector('#billing-panel-history').hidden && !document.querySelector('#billing-panel-billing').textContent.includes('DUES-${ownerBill}') && document.querySelector('#billing-panel-history').textContent.includes('DUES-${ownerBill}')`,'cash confirmation moves the bill into History and opens the completed payment tab');
    await go(1,'resident/payments.php?tab=history');
    await check("document.querySelectorAll('.history-receipt-link').length===2 && !document.querySelector('#billing-panel-history').hidden",'resident sees the confirmed payment and receipt link in History');
    await check("(async()=>{const response=await fetch('../superadmin/unitpayments.php');return !response.url.includes('/unitpayments.php');})()",'resident cannot access staff billing');
    await go(4,'superadmin/unitpayments.php');
    await check("!location.pathname.endsWith('/unitpayments.php')",'existing Admin billing restriction remains unchanged');
    if(errors.length)throw Error(errors.join('\n'));
    console.log(`Passed ${checks} billing workspace browser checks. Screenshots: ${directory}`);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
