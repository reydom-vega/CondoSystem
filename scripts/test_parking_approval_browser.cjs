/* Only use the disposable test_ui_redesign.php --preview fixture. */
const {spawn}=require('node:child_process');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),net=require('node:net');
const base=process.argv[2],sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
if(!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base||''))throw Error('Pass the disposable UI fixture URL.');
(async()=>{
 const manifest=await(await fetch(base+'__manifest')).json();
 if(!manifest.some(entry=>entry.path==='resident/parking.php'))throw Error('Missing profile fixture');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-visitor-parking-browser-'));
 const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--no-sandbox',`--remote-debugging-port=${port}`,`--user-data-dir=${directory}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let socket,checks=0;const errors=[];
 try {
  let target;for(let i=0;i<100;i++){try{target=(await(await fetch(`http://127.0.0.1:${port}/json`)).json()).find(t=>t.type==='page');if(target)break;}catch{}await sleep(100);}
  if(!target)throw Error('Chrome unavailable');socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.onopen=resolve);
  let seq=0;const calls=new Map();socket.onmessage=e=>{const m=JSON.parse(e.data),c=calls.get(m.id);if(c){calls.delete(m.id);clearTimeout(c.timer);m.error?c.reject(m.error):c.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);};
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{calls.delete(id);reject(Error('Timeout '+method));},30000);calls.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<200;i++){if(await ev(expression))return;await sleep(75);}throw Error('Timed out '+expression+'\n'+await ev('document.body.innerText'));};
  const shot=async name=>{const metrics=await send('Page.getLayoutMetrics');const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:metrics.cssContentSize.width,height:metrics.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(directory,name+'.png'),Buffer.from(r.data,'base64'));};
  const check=async(expression,label)=>{if(!await ev(expression)){await shot('failure');throw Error(label);}checks++;};
  const go=async(actor,route='resident/parking.php')=>{await ev('window.parkingFeeNavigating=true');const url=new URL(route,base);url.searchParams.set('fixture_actor',actor);await send('Page.navigate',{url:url.href});await until("!window.parkingFeeNavigating&&document.readyState==='complete'&&!!window.CelandineUi");await sleep(350);};
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
  const post=(action,values={})=>ev(`(async()=>{const token=document.querySelector('[data-csrf]')?.dataset.csrf||document.querySelector('[name=csrf_token]')?.value,d=new FormData();d.set('csrf_token',token);d.set('action',${JSON.stringify(action)});Object.entries(${JSON.stringify(values)}).forEach(([k,v])=>d.set(k,v));const r=await fetch(${JSON.stringify(base+'api/visitor_parking.php')},{method:'POST',body:d,cache:'no-store'});return {status:r.status,data:await r.json(),cache:r.headers.get('cache-control')};})()`);
  await go(4,'superadmin/parking.php?tab=visitors');await until("document.querySelectorAll('.vp-session').length>0");
  const sessions=(await post('list')).data.data.sessions,owner=sessions.find(s=>s.plate==='APPROVAL-OWNER'),tenant=sessions.find(s=>s.plate==='APPROVAL-TENANT');if(!owner||!tenant)throw Error('Missing pending approval fixtures');
  for(const actor of [1,2,5]){
    await go(actor,actor<=2?'resident/visitors.php':'superadmin/service_requests.php?kind=visitor');
    await check("!document.querySelector('[data-vp-review-request]')",'Combined approval button hidden for role '+actor);
    if((await post('review_approval',{id:owner.id})).status!==422)throw Error('Forbidden combined review '+actor);checks++;
    if((await post('approve_all',{id:owner.id})).status!==422)throw Error('Forbidden combined action '+actor);checks++;
  }
  for(const [actor,record] of [[4,owner],[6,tenant]]){
    const route=actor===4?'superadmin/parking.php?tab=visitors':'superadmin/service_requests.php?kind=visitor';
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});await go(actor,route);
    const selector=actor===4?`.parking-request-row [data-vp-review-request="${record.id}"]`:`.service-request [data-vp-review-request="${record.id}"]`;
    await check(`!!document.querySelector(${JSON.stringify(selector)})`,'One review entry point on '+route);
    if(actor===4){
      await send('Network.setBlockedURLs',{urls:['*visitor_id_document.php*']});await ev(`document.querySelector(${JSON.stringify(selector)}).click()`);
      await until("document.querySelector('.vp-approval-feedback')?.textContent.includes('could not be displayed')");
      await check("document.querySelector('.vp-approval-dialog [type=submit]').disabled",'Approval disabled when ID image cannot be displayed');
      await ev("document.querySelector('.vp-approval-close').click()");await send('Network.setBlockedURLs',{urls:[]});
    }
    await ev(`document.querySelector(${JSON.stringify(selector)}).click()`);
    await until("document.querySelector('.vp-approval-dialog img')?.naturalWidth===640&&!document.querySelector('.vp-approval-dialog [type=submit]').disabled");
    await check("document.querySelectorAll('.vp-approval-dialog').length===1",'One shared review dialog');
    await check("document.querySelector('.vp-approval-dialog').textContent.includes('Family visit')&&document.querySelector('.vp-approval-dialog').textContent.includes('Silver')",'Review shows visit, vehicle, sponsor, fee and ID');
    await check(`Boolean(document.querySelector('.vp-approval-dialog [name=include_extension]')?.checked)===${actor===6}`,'Requested extended stay included explicitly');
    for(const width of [1366,768,390,320])for(const theme of ['dark','light']){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<700});await ev('CelandineTheme.set('+JSON.stringify(theme)+')');await sleep(120);
      await check("(()=>{const d=document.querySelector('.vp-approval-dialog').getBoundingClientRect();return d.left>=0&&d.right<=innerWidth+1&&d.height<=innerHeight;})()",'Review dialog fits '+actor+' '+width+' '+theme);
      await check("document.querySelector('.vp-approval-dialog select').getBoundingClientRect().height>=40&&document.querySelector('.vp-approval-dialog textarea').getBoundingClientRect().height>=40",'Slot and note visibly usable '+width+' '+theme);
      if(width===390||width===1366)await shot('approval-'+actor+'-'+width+'-'+theme);
    }
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});await sleep(250);
    const reviewed=(await post('review_approval',{id:record.id})).data.data;
    await ev("document.querySelector('.vp-approval-dialog [name=approval_note]').value='Reviewed identification and visit details';window.parkingApprovalSaving=true;document.querySelector('.vp-approval-dialog [type=submit]').click()");
    await until("!window.parkingApprovalSaving&&document.readyState==='complete'&&!!window.CelandineUi");checks++;
    const detail=(await post('detail',{id:record.id})).data.data;
    if(detail.status!=='approved'||detail.arrival!==null||detail.quote.rate!=='50.00')throw Error('Combined approval changed vehicle timing or failed');checks++;
    if(actor===6&&!detail.extensions.some(e=>e.status==='approved'))throw Error('One action did not approve planned extension');checks++;
    const replay=await post('approve_all',{id:record.id,identity_version:reviewed.visitor.identity_version,review_version:reviewed.review_version,slot_id:reviewed.selected_slot,include_extension:reviewed.extension?'1':'0',extension_id:reviewed.extension?.id||0});
    if(!replay.data.data.already_approved)throw Error('Duplicate approval not idempotent');checks++;
    await go(actor,'superadmin/service_requests.php?kind=visitor');await check(`![...document.querySelectorAll('.service-request')].find(n=>n.textContent.includes(${JSON.stringify(record.plate)})).querySelector('[data-vp-review-request]')`,'Completed request needs no more ID, visit or parking approvals');
  }
  if(errors.length)throw Error(errors.join('\n'));console.log(JSON.stringify({checks,screenshots:directory}));
 }finally{socket?.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
