(() => {
  'use strict';
  const node = (tag, text, cls) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (cls) el.className = cls; return el; };
  const states = {submitted:'Submitted',reviewed:'Reviewed',returned:'Returned for Clarification'};
  const local = value => new Intl.DateTimeFormat('en-PH', {dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Manila'}).format(new Date(value.replace(' ', 'T') + 'Z'));
  document.querySelectorAll('[data-job-reports]').forEach(root => {
    const config = JSON.parse(root.querySelector('[data-jr-config]').textContent);
    const management = config.scope === 'management', $ = s => root.querySelector(s);
    const periodForm = $('[data-jr-period]'), notesForm = $('[data-jr-notes]'), fileFilters = $('[data-jr-file-filters]');
    const dialog = $('[data-jr-dialog]'), documentArea = $('[data-jr-document]'), actions = $('[data-jr-dialog-actions]');
    let period = {type:'daily',period:config.today}, revision = 0, previousId = 0, preview = null, current = null, latest = null, dirty = false, busy = false, overviewSeq = 0, listSeq = 0, opener;
    const values = form => Object.fromEntries(new FormData(form));
    const notes = () => values(notesForm);
    function message(text, error = false, inside = dialog.open) { const el = $(inside ? '[data-jr-dialog-message]' : '[data-jr-message]'); el.textContent = text; el.hidden = !text; el.classList.toggle('jr-error', error); }
    async function request(action, params = {}) {
      const body = new URLSearchParams({action,scope:config.scope,csrf_token:config.csrf});
      Object.entries(params).forEach(([k,v]) => body.set(k, typeof v === 'object' ? JSON.stringify(v) : String(v)));
      const response = await fetch(config.endpoint, {method:'POST',body,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      let result; try { result = await response.json(); } catch (_) { throw Error('The server did not return a report. Refresh your session and try again.'); }
      if (!response.ok || !result.ok) throw Error(result.error || 'Unable to process this report.');
      return result.data;
    }
    async function run(button, task) {
      if (busy) { message('A report request is still in progress. Please wait.'); return; } busy = true; root.setAttribute('aria-busy','true'); if (button) button.disabled = true;
      const controls = [...root.querySelectorAll('button:not([data-jr-close])')].map(el => [el,el.disabled]); controls.forEach(([el]) => {el.disabled=true;});
      const loading = window.CelandineLoading?.begin({button,message:'Preparing your job report...',timeout:120000});
      try { await task(); } catch (error) { message(error.message, true); } finally { loading?.end(); controls.forEach(([el,disabled])=>{el.disabled=disabled;}); if (button) button.disabled = false; busy = false; root.setAttribute('aria-busy','false'); }
    }
    function stats(target, data, labels) {
      target.replaceChildren(...Object.entries(labels).map(([key,label]) => { const card = node('div', undefined, 'jr-stat');card.append(node('strong', data[key] ?? 0),node('span', label));return card; }));
    }
    function pager(parent, data, callback) {
      const bar = node('div', undefined, 'jr-pager');bar.append(node('span', `${data.total} records · Page ${data.page} of ${data.pages}`));
      [['Previous',data.page-1,data.page<=1],['Next',data.page+1,data.page>=data.pages]].forEach(([label,page,disabled]) => {const b = button(label,() => run(b,() => callback(page)),true);b.disabled=disabled;bar.append(b);});parent.append(bar);
    }
    function button(label, callback, secondary = false) { const b = node('button', label, secondary ? 'jr-secondary' : '');b.type='button';b.addEventListener('click',callback);return b; }
    function activityTable(parent, rows, monthly) {
      parent.replaceChildren();
      if (!rows.length) {parent.append(node('p','No system-recorded work activities for this period or selected filters.','jr-empty'));return;}
      const wrap=node('div',undefined,'jr-table-wrap'),table=node('table'),head=node('thead'),tr=node('tr'),body=node('tbody');
      ['Time (PH)','Module','Work activity','Reference','Result'].forEach(t => tr.append(node('th',t)));head.append(tr);table.append(head,body);let day='';
      rows.forEach(row => {const date=row.local_time.slice(0,10);if(monthly&&date!==day){const group=node('tr',undefined,'jr-day'),td=node('td',date);td.colSpan=5;group.append(td);body.append(group);day=date;}
        const line=node('tr');['local_time','module','description','reference','result'].forEach(key => line.append(node('td',row[key])));body.append(line);});wrap.append(table);parent.append(wrap);
    }
    async function overview(page = 1, loadDraft = false) {
      const seq=++overviewSeq,data=await request('overview',{...period,...values($('[data-jr-activity-filters]')),page});if(seq!==overviewSeq)return;
      const all=data.activity.modules.reduce((s,m)=>({total:s.total+Number(m.total),completed:s.completed+Number(m.completed),updated:s.updated+Number(m.updated)}),{total:0,completed:0,updated:0});
      stats($('[data-jr-summary]'),{...all,filed:data.filed_count},{total:'Total Activities',completed:'Completed Actions',updated:'Updated Records',filed:'Filed Reports'});
      const roleData=Object.fromEntries(data.metrics.map(m=>[m.label,m.value]));stats($('[data-jr-role-summary]'),roleData,Object.fromEntries(data.metrics.map(m=>[m.label,m.label])));
      const module=$('[name="module"]'),selected=module.value;module.replaceChildren(new Option('All modules',''),...data.activity.modules.map(m=>new Option(m.module,m.module)));module.value=selected;
      activityTable($('[data-jr-activities]'),data.activity.rows,period.type==='monthly');pager($('[data-jr-activities]'),data.activity,p=>overview(p));
      latest=data.latest;
      if(loadDraft){revision=data.draft.revision;const draftNotes=latest?.status==='returned'&&(!data.draft.updated_at||data.draft.updated_at<=latest.filed_at)?latest.notes:data.draft.notes;Object.entries(draftNotes).forEach(([k,v])=>{notesForm.elements[k].value=v;});dirty=false;previousId=latest?.status==='returned'?Number(latest.id):0;preview=null;$('[data-jr-correction]').hidden=!previousId;if(previousId)$('[data-jr-correction]').textContent=`Correcting ${latest.reference}, version ${latest.version}. The original submission remains unchanged.`;$('[data-jr-draft-state]').textContent=revision?`Draft saved · Revision ${revision}`:'Draft';}
    }
    async function list(page = 1) {
      const seq=++listSeq,data=await request('list',{...values(fileFilters),page});if(seq!==listSeq)return;const parent=$('[data-jr-files]');parent.replaceChildren();
      if(management)stats($('[data-jr-review-summary]'),data.stats,{total:'Total Filed Versions',submitted:'Submitted',reviewed:'Reviewed',returned:'Returned',daily:'Daily Reports',monthly:'Monthly Reports'});
      if(!data.rows.length)parent.append(node('p','No filed reports match these filters.','jr-empty'));
      else{const wrap=node('div',undefined,'jr-table-wrap'),table=node('table'),head=node('thead'),tr=node('tr'),body=node('tbody');['Staff','Report / period','Submitted (PH)','Status','Actions'].forEach(t=>tr.append(node('th',t)));head.append(tr);table.append(head,body);
        data.rows.forEach(r=>{const row=node('tr'),staff=node('td');staff.append(node('strong',r.staff_name),node('small',r.staff_id+' · '+(config.roles[r.staff_role]||r.staff_role)));if(r.self_filed)staff.append(node('span','Self-filed','jr-badge'));
          const details=node('td');details.append(node('strong',r.reference),node('small',`${r.report_type==='daily'?'Daily':'Monthly'} · ${r.period_start.slice(0,r.report_type==='daily'?10:7)} · Version ${r.version}`));const action=node('td');const view=button('View report',()=>run(view,()=>detail(r.id)),true);action.append(view);row.append(staff,details,node('td',local(r.filed_at)),node('td',states[r.status]||r.status),action);body.append(row);});wrap.append(table);parent.append(wrap);}
      pager(parent,data,list);
    }
    function open(title) {opener=document.activeElement;$('[data-jr-dialog-title]').textContent=title;$('[data-jr-dialog-message]').hidden=true;if(!dialog.open)dialog.showModal();}
    function renderDocument(data, filed, paging) {
      documentArea.replaceChildren();const info=node('div',undefined,'jr-document-info');info.append(node('strong',data.staff.name),node('div',`${data.staff.staff_id} · ${config.roles[data.staff.role]||data.staff.role}`),node('div',`${data.period.type==='daily'?'Daily':'Monthly'} · ${data.period.label} · Asia/Manila`));
      if(filed)info.append(node('div',`${filed.reference} · Version ${filed.version} · ${states[filed.status]}`));
      const generated=filed?.filed_at||preview?.generated_at;if(generated)info.append(node('div',`Generated / filed: ${local(generated)}`));documentArea.append(info);
      const totals=node('div',undefined,'jr-stats');stats(totals,data.summary,{total:'Total Activities',completed:'Completed Actions',updated:'Updated Records'});documentArea.append(totals,node('p',data.definitions,'jr-muted')); (data.metrics||[]).forEach(m=>documentArea.append(node('p',`${m.label}: ${m.value}`)));documentArea.append(node('h3','System-Recorded Activity'));
      const events=node('div');activityTable(events,data.activities,data.period.type==='monthly');documentArea.append(events);if(paging) pager(events,paging,paging.next);
      documentArea.append(node('h3','Staff-Reported Work Notes'),node('p','Staff-written notes are separate from verified system records.','jr-muted'));
      Object.entries(config.notes).forEach(([key,label])=>documentArea.append(node('h4',label),node('p',data.notes[key]||'No notes provided.','jr-note-content')));
      if(filed){documentArea.append(node('h3','Submission & Review History'));filed.reviews.forEach(r=>{const entry=node('div',undefined,'jr-review-entry');entry.append(node('strong',`${states[r.action]||r.action} · ${r.actor_name}`),node('p',local(r.created_at),'jr-muted'));if(r.feedback)entry.append(node('p',r.feedback,'jr-note-content'));documentArea.append(entry);});}
      documentArea.append(node('p','The Celandine · Confidential staff activity report','jr-muted'));
    }
    function exportButton(format, filed) {
      const b=button(format==='pdf'?'Download PDF':'Download PNG',()=>run(b,async()=>{
        const payload=filed?{id:filed.id}:{...period,notes:notes(),preview_hash:preview.preview_hash};
        const body=new URLSearchParams({scope:config.scope,csrf_token:config.csrf,format});Object.entries(payload).forEach(([k,v])=>body.set(k,typeof v==='object'?JSON.stringify(v):v));
        const response=await fetch(config.export,{method:'POST',body,credentials:'same-origin',cache:'no-store'});
        if(!response.ok){let data;try{data=await response.json();}catch(_){data={};}throw Error(data.error||'Unable to download this report.');}
        const blob=await response.blob(),url=URL.createObjectURL(blob),a=node('a');a.href=url;a.download=(response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)||[])[1]||`JobReport.${format}`;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);message('Download prepared. Long PNG reports include numbered pages in a ZIP.');
      }),true);return b;
    }
    async function showPreview(page = 1) {
      const next=await request('preview',{...period,notes:notes(),page});preview=next;current=null;open('Preview Job Report');
      renderDocument(next.snapshot,null,{total:next.total,page:next.page,pages:next.pages,next:showPreview});actions.replaceChildren(exportButton('pdf'),exportButton('png'));
      if(latest&&latest.status!=='returned'){actions.append(node('p','This period already has a filed report. Only a returned report may be corrected.','jr-notice'));const existing=button('View filed report',()=>run(existing,()=>detail(latest.id)),true);actions.append(existing);return;}
      const label=node('label',undefined,'jr-confirm'),check=node('input');check.type='checkbox';check.required=true;label.append(check,node('span',`I reviewed this report and confirm its accuracy. Filing submits all ${next.total} system-recorded activities and these staff-reported notes as an immutable official version.`));actions.append(label);
      const submit=button(previousId?'File Corrected Version':'File Report',()=>run(submit,async()=>{
        if(!check.checked)throw Error('Confirm the report’s accuracy before filing.');
        const key=submit.dataset.requestKey||(submit.dataset.requestKey=cryptoKey());const saved=await request('file',{...period,notes:notes(),preview_hash:preview.preview_hash,previous_id:previousId,confirmed:'1',request_key:key});dirty=false;await detail(saved.id);await list();await overview();message(`Report filed successfully. Reference: ${saved.reference}`,false,true);
      }));actions.append(submit);
    }
    async function detail(id,page = 1) {
      current=await request('detail',{id,page});preview=null;open('Filed Job Report');renderDocument(current.data,current,{total:current.total,page:current.page,pages:current.pages,next:p=>detail(id,p)});actions.replaceChildren(exportButton('pdf',current),exportButton('png',current));
      if(current.self_filed)actions.append(node('p',current.status==='submitted'?'Self-filed report. Another authorized Super Admin must review it; it remains Submitted until independently reviewed.':'You filed this report. Its review history records the independent reviewer and decision.','jr-notice'));
      if(current.can_correct){const original=current;const b=button('Prepare corrected version',()=>run(b,async()=>{
        period={type:original.report_type,period:original.period_start.slice(0,original.report_type==='daily'?10:7)};setPeriodInputs();await overview(1,true);previousId=Number(original.id);dirty=true;$('[data-jr-correction]').textContent=`Correcting ${original.reference}, version ${original.version}. The original submission will remain unchanged.`;$('[data-jr-correction]').hidden=false;dialog.close();$('[data-workspace-tab="report"]')?.click();notesForm.querySelector('textarea')?.focus({preventScroll:true});notesForm.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
      }));actions.append(b);}
      if(current.can_review){const report=current,field=node('label','Private review feedback','jr-review-feedback'),area=node('textarea');area.rows=4;area.maxLength=4000;field.append(area);actions.append(field);
        [['Mark Reviewed','reviewed'],['Return for Clarification','returned']].forEach(([label,decision])=>{const b=button(label,()=>run(b,async()=>{const key=b.dataset.requestKey||(b.dataset.requestKey=cryptoKey());await request('review',{id:report.id,decision,feedback:area.value,request_key:key});await detail(report.id);await list();message('Review recorded successfully.',false,true);}),decision==='returned');actions.append(b);});}
    }
    function setPeriodInputs(){periodForm.elements.type.value=period.type;const input=periodForm.elements.period;input.type=period.type==='daily'?'date':'month';input.max=period.type==='daily'?config.today:config.month;input.value=period.period;}
    function cryptoKey(){const bytes=new Uint8Array(24);crypto.getRandomValues(bytes);return [...bytes].map(b=>b.toString(16).padStart(2,'0')).join('');}
    $('[data-jr-close]').addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>opener?.focus());
    fileFilters.addEventListener('submit',e=>{e.preventDefault();run(e.submitter,()=>list());});$('[data-jr-clear-files]').addEventListener('click',e=>{fileFilters.reset();run(e.currentTarget,()=>list());});
    if(!management){
      periodForm.elements.type.addEventListener('change',()=>{const input=periodForm.elements.period,type=periodForm.elements.type.value;input.type=type==='daily'?'date':'month';input.max=type==='daily'?config.today:config.month;input.value=type==='daily'?config.today:config.month;periodForm.requestSubmit();});
      periodForm.elements.period.addEventListener('change',()=>periodForm.requestSubmit());
      $('[data-jr-refresh]').addEventListener('click',e=>run(e.currentTarget,()=>overview()));
      periodForm.addEventListener('submit',e=>{e.preventDefault();if(dirty&&!window.confirm('Load another period? Unsaved work notes will be replaced. Save your draft first if needed.')){setPeriodInputs();return;}run(e.submitter,async()=>{period=values(periodForm);$('[data-jr-activity-filters]').reset();await overview(1,true);});});
      const filters=$('[data-jr-activity-filters]');filters.addEventListener('submit',e=>{e.preventDefault();run(e.submitter,()=>overview());});$('[data-jr-clear-activities]').addEventListener('click',e=>{filters.reset();run(e.currentTarget,()=>overview());});
      notesForm.addEventListener('submit',e=>e.preventDefault());notesForm.addEventListener('input',()=>{dirty=true;$('[data-jr-draft-state]').textContent='Draft · Unsaved changes';});
      $('[data-jr-save]').addEventListener('click',e=>run(e.currentTarget,async()=>{const draft=await request('save_draft',{...period,notes:notes(),revision});revision=draft.revision;dirty=false;$('[data-jr-draft-state]').textContent=`Draft saved · Revision ${revision}`;message('Draft saved. It has not been filed.',false,false);}));
      $('[data-jr-preview]').addEventListener('click',e=>run(e.currentTarget,()=>showPreview()));
    }
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    run(null,async()=>{if(!management)await overview(1,true);await list();if(config.open_id)await detail(config.open_id);});
  });
})();
