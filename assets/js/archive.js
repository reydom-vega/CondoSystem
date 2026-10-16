(() => {
  'use strict';
  const dialog=document.querySelector('[data-archive-dialog]');if(!dialog)return;
  const form=dialog.querySelector('form'),impact=dialog.querySelector('[data-archive-impact]'),feedback=dialog.querySelector('[data-archive-feedback]'),fields=dialog.querySelector('[data-archive-confirm-fields]'),submit=dialog.querySelector('[data-archive-submit]'),reload=dialog.querySelector('[data-archive-reload]');
  let current=null,preview=null,controller=null,busy=false,saving=false;
  const element=(tag,text)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;return node;};
  const message=(text,error=false)=>{feedback.textContent=text;feedback.dataset.error=String(error);};
  const sync=()=>{submit.disabled=busy||!preview;dialog.querySelectorAll('[data-archive-close]').forEach(button=>button.disabled=saving);};
  const titles={vacate_unit:'Mark Unit as Vacant?',end_occupancy:'End Occupancy?',archive_account:'End Account Relationship?',archive_staff:'Remove Staff Account?'};
  async function request(url,options={}) {
    controller=new AbortController();const timer=setTimeout(()=>controller.abort(),20000);
    try {const response=await fetch(url,{cache:'no-store',credentials:'same-origin',signal:controller.signal,...options});let result;try{result=await response.json();}catch{throw Error('The session or service is unavailable. Reopen the page and try again.');}if(!response.ok||!result.success)throw Error(result.error||'Unable to complete the archive request.');return result;}
    finally{clearTimeout(timer);controller=null;}
  }
  async function load() {
    if(busy)return;busy=true;preview=null;form.querySelectorAll('input[type=checkbox]').forEach(input=>input.checked=false);fields.hidden=true;reload.hidden=true;impact.replaceChildren();sync();message('Loading linked records...');
    try {
      const url=new URL(dialog.dataset.endpoint,location.href);Object.entries(current).forEach(([key,value])=>{if(key!=='request_key')url.searchParams.set(key,value);});
      const result=await request(url);preview=result.preview;
      if(current.mode==='archive_staff') {
        const user=preview.targets[0].account;
        form.dataset.confirm=`Remove and archive ${user.full_name} (${user.staff_id||'Account #'+user.id})? This staff member will immediately lose system access. Historical reports and records will be preserved.`;
        form.dataset.confirmTitle='Confirm Staff Removal';
        form.dataset.confirmAction='Remove & Archive Staff';
      }
      const grid=element('dl');grid.className='archive-impact-grid';
      const t=preview.totals;
      const rows=[['Unit',preview.unit||'Not applicable'],['Accounts / relationships',t.accounts],['Registered vehicles',t.vehicles],['Assigned parking slots',t.parking_slots],['Active visitor passes / visits',t.active_visits],['Vehicles currently inside',t.active_parking],['Unused requests / future bookings',t.unused_requests],['Open maintenance requests',t.maintenance],['Outstanding bills',t.unpaid_bills],['Unpaid balance','PHP '+(t.unpaid_cents/100).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})],['Filed Job Reports',t.filed_reports],['Assigned case responsibilities',t.assigned_cases]];
      rows.forEach(([label,value])=>{const cell=element('div');cell.append(element('dt',label),element('dd',String(value)));grid.append(cell);});impact.append(grid);
      const list=element('ul');list.className='archive-targets';
      preview.targets.forEach(target=>{const user=target.account,item=element('li');item.append(element('strong',`${user.full_name} — ${user.staff_id||'Account #'+user.id}`),element('p',`${user.role} · ${user.account_type||'Staff'} · ${user.email} · ${user.is_active==='0'||user.is_active===0?'Disabled':'Active'} · Joined ${user.created_at}`));
        const relationship=target.relationships.find(row=>row.unit_number===preview.unit&&row.ended_at===null);
        if(relationship)item.append(element('p',`Occupancy start: ${relationship.started_at||'Not recorded (legacy assignment)'}`));
        if(target.retain_owner)item.append(element('p','Ownership, owner billing access and valid contractual parking assignments remain active. Physical occupancy ends.'));
        if(target.active_parking.length||target.services.some(row=>row.status==='checked_in'))item.append(element('p','Active visits remain open and require Security/Admin review and real checkout.'));
        list.append(item);
      });impact.append(list);
      const unused=dialog.querySelector('[data-archive-cancel-unused]');unused.hidden=t.unused_requests===0;unused.querySelector('input').required=t.unused_requests>0;
      const needsInspection=preview.targets.some(target=>!target.retain_owner&&target.slots.some(slot=>slot.status==='occupied'&&String(slot.assigned_unit).replace(/^0+/,'')===String(preview.unit).replace(/^0+/,'')));
      dialog.querySelector('[data-archive-parking-clear]').hidden=!needsInspection;
      fields.hidden=false;message('Review the impact summary and provide the reason before confirming.');
    }catch(error){if(dialog.open){message(error.name==='AbortError'?'The summary request timed out. Refresh it and try again.':error.message,true);reload.hidden=false;}}
    finally{busy=false;sync();}
  }
  document.addEventListener('click',event=>{
    const trigger=event.target.closest('[data-archive-mode]');if(!trigger)return;event.preventDefault();if(busy)return;
    form.reset();delete form.dataset.confirm;delete form.dataset.confirmBypass;delete form.dataset.confirmTitle;delete form.dataset.confirmAction;
    current={mode:trigger.dataset.archiveMode,user_id:trigger.dataset.archiveUser,unit_number:trigger.dataset.archiveUnit||'',request_key:Array.from(crypto.getRandomValues(new Uint8Array(32)),byte=>byte.toString(16).padStart(2,'0')).join('')};
    dialog.querySelector('#archiveConfirmTitle').textContent=titles[current.mode]||'Review archive action';
    dialog.querySelector('[data-archive-introduction]').textContent=current.mode==='archive_staff'?'This staff member will immediately lose system access and leave active Staff Management. Filed Job Reports, Audit Logs and processed transactions remain preserved in Archive.':'Ending the selected relationship revokes its occupancy privileges. Historical records and balances stay with the original account. Unit vacancy preserves legal ownership unless the owner’s account relationship is explicitly ended through Resident Management.';
    submit.textContent=current.mode==='archive_staff'?'Remove & Archive Staff':current.mode==='vacate_unit'?'Confirm Vacancy':'End & Archive Relationship';dialog.showModal();load();
  });
  dialog.querySelectorAll('[data-archive-close]').forEach(button=>button.addEventListener('click',()=>{if(!saving){controller?.abort();dialog.close();}}));
  dialog.addEventListener('cancel',event=>{if(saving)event.preventDefault();else controller?.abort();});
  dialog.addEventListener('close',()=>{controller?.abort();preview=null;});
  reload.addEventListener('click',load);
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||!preview||!form.reportValidity())return;
    busy=true;saving=true;sync();message('Saving the confirmed archive action...');
    const loading=window.CelandineLoading?.begin({button:submit,message:'Saving archive...',delay:0});
    try {
      const data=new URLSearchParams(new FormData(form));Object.entries(current).forEach(([key,value])=>data.set(key,value));data.set('preview_hash',preview.preview_hash);
      const result=await request(dialog.dataset.endpoint,{method:'POST',body:data});const url=new URL(result.redirect,location.href);if(url.origin!==location.origin)throw Error('Archive saved. Open Archive Management to review the result.');location.assign(url.href);
    }catch(error){message(error.name==='AbortError'?'The response timed out. Check Archive before retrying; this confirmation can be safely retried.':error.message,true);reload.hidden=false;}
    finally{loading?.end();busy=false;saving=false;sync();}
  });
})();
