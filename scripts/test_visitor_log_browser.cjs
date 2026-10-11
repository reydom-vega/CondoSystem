/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'superadmin/visitorlog.php')) throw Error('Visitor log fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-visitor-log-'));
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
      await ev('window.visitorLogNavigating=true');
      const url=new URL(pathname,base);url.searchParams.set('fixture_actor',actor);
      await send('Page.navigate',{url:url.href});
      await until("!window.visitorLogNavigating && document.readyState==='complete' && !!window.CelandineUi");await sleep(250);
    };
    const click=async key=>{await ev(`document.querySelector('[data-workspace-tab="${key}"]').click()`);await sleep(300);};
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    const loaded = async () => until("!window.visitorLogNavigating && document.readyState==='complete' && !!window.CelandineUi");
    for (const actor of [4,6]) {
      await go(actor,'superadmin/visitorlog.php');
      await check("[...document.querySelectorAll('[data-workspace-tab]')].map(tab=>tab.dataset.workspaceTab).join(',')==='form,inside,history' && !document.querySelector('#visitor-log-panel-form').hidden",`admin/superadmin ${actor}: check-in form, Currently Inside and History tabs`);
      await ev("window.visitorLogDraft=42;document.querySelector('#visitorName').value='Draft visitor'");
      await click('inside');await click('history');
      await check("window.visitorLogDraft===42 && !document.querySelector('#visitor-log-panel-history').hidden",`admin/superadmin ${actor}: tabs switch without reloading`);
      await ev('history.back()');await sleep(250);
      await check("!document.querySelector('#visitor-log-panel-inside').hidden",`admin/superadmin ${actor}: browser Back restores the previous tab`);
      await click('form');
      await check("document.querySelector('#visitorName').value==='Draft visitor'",`admin/superadmin ${actor}: tab changes preserve the form draft`);
      await ev("document.querySelector('[data-workspace-tab=form]').dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}))");
      await check("document.activeElement.dataset.workspaceTab==='history' && !document.querySelector('#visitor-log-panel-history').hidden",`admin/superadmin ${actor}: keyboard tab navigation`);
    }
    await go(4,'superadmin/visitorlog.php');
    await ev("const form=document.querySelector('.visitor-form');form.querySelector('[name=visitor_name]').value='Workspace Visitor';form.querySelector('[name=visitor_contact]').value='09123456789';form.querySelector('[name=unit_number]').value='0101';form.querySelector('[name=purpose]').value='Browser workflow visit';window.visitorLogNavigating=true;form.requestSubmit()");
    await loaded();
    await check("!document.querySelector('#visitor-log-panel-inside').hidden && document.querySelector('#visitor-log-panel-inside').textContent.includes('Workspace Visitor') && document.querySelector('.alert.success').textContent.includes('logged in')",'Admin check-in opens Currently Inside and shows the new visitor');
    const id=await ev("[...document.querySelectorAll('#visitor-log-panel-inside tbody tr')].find(row=>row.textContent.includes('Workspace Visitor')).querySelector('[name=visitor_log_id]').value");
    await click('history');
    await check("document.querySelector('#visitor-log-panel-history').textContent.includes('Workspace Visitor') && document.querySelector('#visitor-log-panel-history').textContent.includes('Admin Staff')",'Activity history preserves the checked-in visitor and logging staff');
    await go(6,'superadmin/visitorlog.php?tab=inside');
    await check("document.querySelector('#visitor-log-panel-inside').textContent.includes('Workspace Visitor')",'Superadmin sees the visitor checked in by Admin');
    await shot('inside-desktop');
    await ev(`window.visitorLogNavigating=true;document.querySelector('#visitor-log-panel-inside [name=visitor_log_id][value="${id}"]').form.requestSubmit()`);
    await loaded();
    await check("!document.querySelector('#visitor-log-panel-history').hidden && !document.querySelector('#visitor-log-panel-inside').textContent.includes('Workspace Visitor') && document.querySelector('.alert.success').textContent.includes('checked out')",'Superadmin checkout opens History and removes the visitor from Currently Inside');
    await check("(()=>{const row=[...document.querySelectorAll('#visitor-log-panel-history tbody tr')].find(row=>row.textContent.includes('Workspace Visitor'));return row.textContent.includes('Checked out') && row.textContent.includes('Superadmin Staff') && row.textContent.includes('Admin Staff');})()",'History retains check-in and checkout staff and completed activity');
    await ev("const search=document.querySelector('#visitorSearch');search.value='Workspace Visitor';document.querySelector('#visitorStatus').value='checked_out';window.visitorLogNavigating=true;search.form.requestSubmit()");
    await loaded();
    await check("!document.querySelector('#visitor-log-panel-history').hidden && document.querySelector('#visitorSearch').value==='Workspace Visitor' && document.querySelector('#visitorStatus').value==='checked_out' && document.querySelectorAll('#visitor-log-panel-history tbody tr').length===1",'History filtering keeps History selected with the requested visitor and status');
    await shot('history-desktop');
    for (const actor of [4,6]) {
      await go(actor,'superadmin/visitorlog.php?search=Workspace%20Visitor&status=checked_out');
      await check("!document.querySelector('#visitor-log-panel-history').hidden && document.querySelectorAll('#visitor-log-panel-history tbody tr').length===1",`admin/superadmin ${actor}: previous search URLs open History`);
      for (const width of [768,390,320]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
        for (const key of ['form','inside','history']) {
          await click(key);
          await check('document.documentElement.scrollWidth<=innerWidth+1',`admin/superadmin ${actor}: ${key} fits ${width}px`);
          if (key==='history' && width<768) await check("document.querySelector('#visitor-log-panel-history .unit-table').getBoundingClientRect().width<=document.querySelector('#visitor-log-panel-history .unit-table-wrap').getBoundingClientRect().width+1",`admin/superadmin ${actor}: history cards fit their container at ${width}px`);
          if(actor===6 && width===390)await shot(key+'-mobile');
        }
      }
      await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});
    }
    await go(4,'superadmin/visitorlog.php');
    await check("(async()=>{const form=document.querySelector('.visitor-form');const data=new FormData(form);data.set('visitor_name','');data.set('unit_number','0101');const response=await fetch(form.getAttribute('action'),{method:'POST',body:data});const doc=new DOMParser().parseFromString(await response.text(),'text/html');return !!doc.querySelector('.alert.error') && !doc.querySelector('#visitor-log-panel-form').hidden;})()",'Invalid check-in returns to the form with an error');
    await check("(async()=>{const form=document.querySelector('.visitor-form');const data=new FormData(form);data.delete('csrf_token');const response=await fetch(form.getAttribute('action'),{method:'POST',body:data});return response.status===403;})()",'Visitor check-in retains CSRF protection');
    await check("(async()=>{const response=await fetch('visitorlog.php?tab=inside');const doc=new DOMParser().parseFromString(await response.text(),'text/html');return !doc.querySelector('#visitor-log-panel-inside').hidden && doc.querySelector('#visitor-log-panel-form').hidden;})()",'Direct tab URL selects the right panel without JavaScript');
    for (const actor of [1,5]) {
      await check(`(async()=>{const response=await fetch('visitorlog.php?fixture_actor=${actor}');return response.status===403;})()`,`resident/security ${actor}: cannot access manual visitor logs`);
    }
    if(errors.length)throw Error(errors.join('\n'));
    console.log('Passed '+checks+' visitor log browser checks. Screenshots: '+directory);
  } finally {socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
