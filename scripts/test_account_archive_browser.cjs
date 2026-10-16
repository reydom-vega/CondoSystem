/* Run against test_ui_redesign.php --preview; never the live database. */
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), net = require('node:net');
const base = process.argv[2];
const staffOnly = process.argv.includes('--staff-only');
if (!/^http:\/\/127\.0\.0\.1:\d+\/CondoSystem3\/$/.test(base || '')) throw Error('Pass the disposable UI fixture URL.');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const manifest = await (await fetch(base+'__manifest')).json();
  if (!manifest.some(entry => entry.path === 'superadmin/archive.php')) throw Error('Archive fixture unavailable.');
  const port = await new Promise(resolve => { const server=net.createServer(); server.listen(0,'127.0.0.1',() => { const port=server.address().port; server.close(() => resolve(port)); }); });
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'condo-archive-browser-'));
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
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false});

    let tenantArchive;
    if(!staffOnly) {
    for(const actor of [0,1,2,4,5,6,7,8]) {
      await go(6,'superadmin/archive.php');
      const status=await ev(`fetch('../api/account_archive.php?fixture_actor=${actor}&mode=archive_staff&user_id=8').then(r=>r.status)`);
      if(status!==(actor===6?200:actor===0?401:403))throw Error('Archive permission failure for actor '+actor);checks++;
    }
    await go(6,'superadmin/archive.php');
    await check("(async()=>{const response=await fetch('../api/account_archive.php',{method:'POST',body:new URLSearchParams({mode:'archive_staff',user_id:8})});return response.status===403;})()",'Archive mutations require CSRF');
    await check("[...document.querySelectorAll('.archive-tabs a')].map(n=>n.textContent).join('|')==='Residents|Tenants|Staff'",'Three Archive categories use the existing shared layout');
    await check("document.querySelectorAll('.sidebar-link[href$=\"superadmin/archive.php\"]').length===1 && document.querySelector('.sidebar-link[href$=\"superadmin/archive.php\"]').getAttribute('aria-current')==='page'",'Super Admin sidebar has one active Archive item');
    for(const theme of ['dark','light'])for(const width of [1366,768,390,320]) {
      await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);
      await check('document.documentElement.scrollWidth<=innerWidth+1','Archive list fits '+theme+' '+width+'px');
      const expected=theme==='dark'?'rgb(26, 30, 43)':'rgb(255, 255, 255)';
      await check(`getComputedStyle(document.querySelector('.service-panel')).backgroundColor===${JSON.stringify(expected)}`,'Archive preserves original theme '+theme+' '+width+'px');
      if(width===1366||width===390)await shot('archive-'+theme+'-'+width);
    }
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
    for(const tab of ['residents','tenants','staff']) {
      await go(6,'superadmin/archive.php?tab='+tab);
      await check(`document.querySelector('.archive-tabs [aria-current=page]').textContent===${JSON.stringify(tab==='residents'?'Residents':tab==='tenants'?'Tenants':'Staff')} && document.querySelector('.archive-home .unit-table tbody').textContent.includes(${JSON.stringify(tab==='residents'?'Former Owner':tab==='tenants'?'Former Tenant':'Former Staff')})`,'Real archived records appear in '+tab);
    }
    await go(6,'superadmin/archive.php?tab=residents&search=ARC-999');
    await check("document.querySelector('.unit-table tbody').textContent.includes('Former Owner')",'Server-side Archive search finds old plates');
    await go(6,'superadmin/archive.php?tab=residents&search=no-such-archive');
    await check("document.querySelector('.unit-empty').textContent.includes('No archive records match')",'Search has an honest empty state');
    const details=manifest.filter(entry=>entry.label==='Archive history');
    for(const entry of details) {
      await go(6,entry.path);
      await check("document.querySelector('.archive-home').textContent.includes('Archived Account') && document.querySelectorAll('.archive-history').length>0 && !document.querySelector('.archive-home').textContent.includes('password_hash') && !document.querySelector('.archive-home').textContent.includes('access_token')",'Read-only Archive details preserve linked history without credentials');
      if(await ev("document.querySelector('.archive-home').textContent.includes('ARC-999')")) await check("[...document.querySelectorAll('.archive-history')].some(n=>n.open&&n.querySelector('summary').textContent.includes('Registered Vehicle History')&&n.textContent.includes('ARC-999'))",'Archived registered vehicles are visible in their expanded account history');
      if(await ev("!!document.querySelector('.archive-history .vehicle-status-archived')")) {
        for(const theme of ['dark','light']) {
          await ev(`CelandineTheme.set(${JSON.stringify(theme)})`);
          await check(`(()=>{const b=document.querySelector('.vehicle-status-archived');return b.textContent==='Archived'&&!b.classList.contains('approved')&&getComputedStyle(b).color===${JSON.stringify(theme==='dark'?'rgb(251, 191, 36)':'rgb(133, 77, 14)')};})()`,'Archived vehicle badge is yellow in '+theme+' mode');
        }
      }
      for(const width of [1366,390,320]){await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});await check('document.documentElement.scrollWidth<=innerWidth+1','Archive detail fits '+width+'px');}
    }
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
    for(const actor of [4,6]) {
      await go(actor,'superadmin/parking.php?tab=vehicles');
      await check("!document.querySelector('#parking-panel-vehicles').textContent.includes('ARC-999')&&!document.querySelector('#parking-panel-vehicles').textContent.includes('Former Owner')",'Archived vehicle is absent from Registered Vehicles for manager '+actor);
      await check(`!!document.querySelector('#parking-panel-vehicles a[href="archive.php"]')===${actor===6}`,'Vehicle history shortcut respects existing Archive permissions for manager '+actor);
    }
    for(const route of ['superadmin/residents.php','superadmin/staff.php?tab=information','superadmin/generate_bills.php','superadmin/parking.php?tab=vehicles','superadmin/parking.php?tab=stickers']) {
      await go(6,route);
      const activeText=route.includes('generate_bills')?"[...document.querySelectorAll('select option, datalist option')].map(n=>n.value+' '+n.textContent).join(' ')":"document.querySelector('.dashboard-main').textContent";
      await check(`!(${activeText}).includes('Former Owner') && !(${activeText}).includes('Former Tenant') && !(${activeText}).includes('Former Staff')`,'Archived accounts absent from active '+route);
    }
    await go(6,'superadmin/units.php');
    await ev("document.querySelector('[data-archive-mode=vacate_unit][data-archive-unit=\"0101\"]').click()");
    await until("document.querySelector('[data-archive-dialog]').open && !document.querySelector('[data-archive-confirm-fields]').hidden");
    await check("document.getElementById('archiveConfirmTitle').textContent==='Mark Unit as Vacant?' && document.querySelector('[data-archive-impact]').textContent.includes('Owner One') && document.querySelector('[data-archive-impact]').textContent.includes('Tenant Two') && document.querySelector('[data-archive-impact]').textContent.includes('Unpaid balance')",'Vacancy confirmation shows both occupants, ownership impact, requests and debt');
    await check("!document.querySelector('[data-archive-confirm-form]').checkValidity()",'Reason and impact confirmation are required');
    for(const width of [390,320]){await send('Emulation.setDeviceMetricsOverride',{width,height:850,deviceScaleFactor:1,mobile:false});await check("(()=>{const r=document.querySelector('[data-archive-dialog]').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.height<=innerHeight;})()",'Confirmation dialog fits mobile '+width+'px');}
    await ev("document.querySelector('[data-archive-close]').click()");await check("!document.querySelector('[data-archive-dialog]').open && document.querySelector('[data-archive-mode=vacate_unit]').closest('tr').textContent.includes('Occupied')",'Cancel leaves the unit occupied and accounts unchanged');
    await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
    await go(6,'superadmin/residents.php');
    await ev("document.querySelector('[data-archive-mode=end_occupancy][data-archive-user=\"2\"]').click()");await until("!document.querySelector('[data-archive-confirm-fields]').hidden");
    await ev("document.getElementById('archiveReasonCode').value='moved_out';document.getElementById('archiveReason').value='Confirmed browser tenant departure';document.querySelector('[data-archive-confirm-form] [name=confirmed]').checked=true;document.querySelector('[data-archive-confirm-form] [name=cancel_unused]').checked=true;window.parkingNavigating=true;document.querySelector('[data-archive-confirm-form]').requestSubmit()");
    await until("!window.parkingNavigating && document.readyState==='complete' && location.pathname.endsWith('/archive.php') && !!document.querySelector('.archive-history')");
    tenantArchive=await ev('location.href');
    await check("document.querySelector('.archive-home').textContent.includes('Tenant Two') && document.querySelector('.archive-home').textContent.includes('Outstanding balance')",'Confirmed End Occupancy opens the real Tenant Archive');
    await go(6,'superadmin/residents.php');await check("!document.querySelector('.unit-table tbody').textContent.includes('Tenant Two') && document.querySelector('.unit-table tbody').textContent.includes('Owner One')",'Tenant leaves active listings while owner remains');
    }
    await go(6,'superadmin/staff.php?tab=information');
    await check("!document.querySelector('[data-archive-user=\"6\"]')",'Super Admin cannot remove themselves through Staff Remove');
    await ev("document.querySelector('[data-archive-mode=archive_staff][data-archive-user=\"8\"]').click()");await until("!document.querySelector('[data-archive-confirm-fields]').hidden");
    await check("document.getElementById('archiveConfirmTitle').textContent==='Remove Staff Account?' && document.querySelector('[data-archive-impact]').textContent.includes('Maintenance Staff') && document.querySelector('[data-archive-impact]').textContent.includes('Filed Job Reports')",'Staff Remove previews correct identity and pending responsibilities');
    await check("document.querySelector('[data-archive-confirm-form] [name=confirmed]').getClientRects().length>0 && document.querySelector('[data-archive-confirm-form] [name=confirmed]').closest('label').textContent.includes('I reviewed')",'Staff removal confirmation checkbox and explanation are visible');
    await ev("document.getElementById('archiveReasonCode').value='staff_removed';document.getElementById('archiveReason').value='Confirmed browser staff removal';document.querySelector('[data-archive-submit]').click()");
    await check("document.querySelector('[data-archive-dialog]').open && !document.querySelector('[data-archive-confirm-form]').checkValidity() && !document.querySelector('[data-archive-confirm-form] [name=confirmed]').checked",'Staff removal waits for the visible confirmation checkbox');
    const realClick=async selector=>{
      const point=await ev(`(()=>{const n=document.querySelector(${JSON.stringify(selector)});n.scrollIntoView({block:'center',behavior:'instant'});const r=n.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
      await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
      await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
    };
    await realClick('[data-archive-confirm-form] [name=confirmed]');
    await check("document.querySelector('[data-archive-confirm-form] [name=confirmed]').checked && document.querySelector('[data-archive-confirm-form]').checkValidity()",'A mouse click confirms the staff removal form');
    await realClick('[data-archive-submit]');
    await until("!!document.querySelector('.system-confirm-dialog[open]')");
    await check("document.querySelector('#systemConfirmTitle').textContent==='Confirm Staff Removal' && document.querySelector('#systemConfirmMessage').textContent.includes('Maintenance Staff')",'Remove button opens a final confirmation naming the selected staff member');
    await check("(async()=>{const r=await fetch('../api/account_archive.php?mode=archive_staff&user_id=8');const d=await r.json();return r.ok&&Number(d.preview.targets[0].account.is_active)===1;})()",'Staff account stays active until final confirmation');
    await check("(()=>{const r=document.querySelector('.system-confirm-dialog[open]').getBoundingClientRect();return Math.abs(r.x+r.width/2-document.documentElement.clientWidth/2)<1&&Math.abs(r.y+r.height/2-innerHeight/2)<1;})()",'Final staff confirmation is centered');
    await realClick('.system-confirm-cancel');await until("!document.querySelector('.system-confirm-dialog[open]')");
    await check("document.querySelector('[data-archive-dialog]').open && document.getElementById('archiveReason').value==='Confirmed browser staff removal' && document.querySelector('[data-archive-confirm-form] [name=confirmed]').checked",'Cancel returns to the removal form and preserves its values');
    await realClick('[data-archive-submit]');await until("!!document.querySelector('.system-confirm-dialog[open]')");
    await ev('window.parkingNavigating=true');await realClick('.system-confirm-accept');
    await until("!window.parkingNavigating && document.readyState==='complete' && location.pathname.endsWith('/archive.php')");
    await check("document.querySelector('.archive-home').textContent.includes('Maintenance Staff') && !!document.querySelector('.archive-restore')",'Staff removal opens Archive with controlled restoration');
    await check("(async()=>{const response=await fetch('../api/notifications.php?fixture_actor=8');return response.status===401;})()",'Removed staff cannot access protected notification endpoints');
    if(!staffOnly) {
    await go(6,'superadmin/units.php');
    await ev("document.querySelector('[data-archive-mode=vacate_unit][data-archive-unit=\"0101\"]').click()");await until("!document.querySelector('[data-archive-confirm-fields]').hidden");
    await ev("document.getElementById('archiveReasonCode').value='turnover';document.getElementById('archiveReason').value='Owner moved out but retained legal ownership';document.querySelector('[data-archive-confirm-form] [name=confirmed]').checked=true;document.querySelector('[data-archive-confirm-form] [name=cancel_unused]').checked=true;document.querySelector('[data-archive-confirm-form] [name=parking_cleared]').checked=true;window.parkingNavigating=true;document.querySelector('[data-archive-confirm-form]').requestSubmit()");
    await until("!window.parkingNavigating && document.readyState==='complete' && location.pathname.endsWith('/archive.php')");
    await check("document.querySelector('.archive-home').textContent.includes('legal ownership and billing responsibility retained')",'Vacancy preserves the legal owner rather than archiving them automatically');
    await go(6,'superadmin/units.php');await check("!document.querySelector('[data-archive-mode=vacate_unit][data-archive-unit=\"0101\"]') && document.querySelector('.unit-table tbody').textContent.includes('Owner One')",'Unit becomes vacant with legal owner still recorded');
    await go(6,'superadmin/generate_bills.php');await check("!!document.querySelector('#resident_options option[data-id=\"1\"]')",'Vacant unit owner remains an eligible dues recipient');
    await go(6,tenantArchive.slice(base.length));
    await check("[...document.querySelectorAll('.archive-restore label')].every(n=>n.getClientRects().length>0)",'Restoration fields and confirmation remain visible');
    await ev("const f=document.querySelector('.archive-restore');f.querySelector('[name=unit_number]').value='0101';f.querySelector('[name=reason]').value='Approved new tenant occupancy period';f.querySelector('[name=new_password]').value='FreshTenant!2026';f.querySelector('[name=confirmed]').checked=true;f.requestSubmit()");
    await until("!!document.querySelector('.system-confirm-accept')");await ev("window.parkingNavigating=true;document.querySelector('.system-confirm-accept').click()");
    await until("!window.parkingNavigating && document.readyState==='complete' && !!document.querySelector('.alert.success')");
    await check("document.querySelector('.alert.success').textContent.includes('restored') && document.querySelector('.archive-home').textContent.includes('Active')",'Explicit restoration creates a new tenant period without overwriting ownership');
    await go(6,'superadmin/residents.php');await check("document.querySelector('.unit-table tbody').textContent.includes('Tenant Two') && document.querySelector('.unit-table tbody').textContent.includes('non-occupying owner')",'Restored tenant appears active while owner occupancy remains ended');
    }
    if(errors.length)throw Error(errors.join('\n'));
    console.log(`Passed ${checks} ${staffOnly ? "staff confirmation and removal" : "Archive browser, permissions, responsive, cancellation and restoration"} checks. Screenshots: ${directory}`);
  } finally {if(socket)socket.close();chrome.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
