(() => {
    'use strict';
    const configNode = document.getElementById('reportConfig');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent), management = config.portal === 'management';
    const $ = id => document.getElementById(id), form = $('newReportForm');
    const requestKey = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2, '0')).join('');
    const state = { tab: config.portal === 'tracking' ? 'track' : 'mine', page: 1, step: 0, files: [], anonymous: false, detail: null, busy: false, listVersion: 0, request: requestKey(), updateRequest: requestKey() };
    const element = (tag, text, className) => { const node = document.createElement(tag); if (text != null) node.textContent = text; if (className) node.className = className; return node; };
    const label = value => config.statuses[value] || String(value || '').replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase());
    const feedback = (message = '', error = false) => { const node = $('reportFeedback'); node.textContent = message; node.hidden = !message; node.className = `alert ${error ? 'alert-danger' : 'alert-success'}`; };
    const date = value => value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString(undefined, { timeZone: config.timezone, dateStyle: 'medium', timeStyle: 'short' }) : '';
    const badge = value => { const node = element('span', label(value), 'report-badge'); node.dataset.status = value; return node; };
    const animate = node => { if (window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) window.gsap.fromTo(node, { opacity: .4, y: 5 }, { opacity: 1, y: 0, duration: .18, clearProps: 'opacity,transform' }); };
    async function api(action, values = {}, files = []) {
        const data = values instanceof FormData ? values : new FormData();
        if (!(values instanceof FormData)) Object.entries(values).forEach(([key, value]) => data.set(key, value ?? ''));
        data.set('action', action); data.set('csrf_token', config.csrf); data.set('management', management ? '1' : '0');
        files.forEach(file => data.append('evidence[]', file));
        const response = await fetch(config.endpoint, { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        let result; try { result = await response.json(); } catch { throw new Error('The server returned an unexpected response. Please retry.'); }
        if (!response.ok || !result.success) throw new Error(result.error || 'Unable to complete this request.');
        return result.data;
    }
    async function exclusive(button, task) {
        if (state.busy) return; state.busy = true; const original = button?.textContent;
        if (button) { button.disabled = true; button.textContent = 'Please wait…'; button.setAttribute('aria-busy', 'true'); }
        feedback();
        try { await task(); } catch (error) { feedback(navigator.onLine ? error.message : 'You are offline. Reconnect and retry; your form is still here.', true); }
        finally { state.busy = false; if (button) { button.disabled = false; button.textContent = original; button.removeAttribute('aria-busy'); if (button.form?.id === 'reportActionForm') updateAction(); } }
    }
    function setTab(tab) {
        state.tab = tab; state.detail = null; $('reportDetail').hidden = true;
        if ($('reportDashboard')) $('reportDashboard').hidden = false;
        document.querySelectorAll('[data-report-tab]').forEach(button => { const active = button.dataset.reportTab === tab; button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1; });
        if ($('reportListPanel')) $('reportListPanel').hidden = !['mine', 'history'].includes(tab);
        if ($('reportNewPanel')) $('reportNewPanel').hidden = tab !== 'new';
        if ($('reportTrackPanel')) $('reportTrackPanel').hidden = tab !== 'track';
        if ($('reportStats')) $('reportStats').hidden = ['new','track'].includes(tab);
        const guide = document.querySelector('.report-flow-guide'); if (guide) guide.hidden = ['new','track'].includes(tab);
        if ($('reportListTitle')) $('reportListTitle').textContent = management ? 'Community reports' : tab === 'history' ? 'Report history' : 'My Reports';
        const url = new URL(location.href); url.searchParams.delete('reference'); url.searchParams.delete('anonymous'); history.replaceState(null, '', url);
        if (['mine', 'history'].includes(tab)) { state.page = 1; loadList().catch(error => feedback(error.message, true)); }
        if (tab === 'new') { updateConditions(); showStep(state.step); }
    }
    async function loadList() {
        const version = ++state.listVersion, data = new FormData($('reportFilters'));
        data.set('page', state.page); data.set('history', state.tab === 'history' ? '1' : '0');
        $('reportList').setAttribute('aria-busy', 'true');
        try {
            const result = await api('list', data); if (version !== state.listVersion) return;
            $('reportStats').replaceChildren();
            const counts = result.stats, active = ['submitted','under_review','awaiting_information','in_progress'].reduce((sum, key) => sum + Number(counts[key] || 0), 0);
            const summaries = management ? [['Active reports',active],['New reports',counts.submitted],['Awaiting reporter reply',counts.awaiting_information],['Urgent reports',counts.urgent]] : [['Active reports',active],['Needs your reply',counts.awaiting_information],['In progress',counts.in_progress],['History',['resolved','closed','declined','withdrawn'].reduce((sum,key)=>sum+Number(counts[key]||0),0)]];
            summaries.forEach(([title, count]) => { const card = element('div', null, 'report-stat'); card.append(element('strong', count || 0), element('span', title)); $('reportStats').append(card); });
            const list = element('div', null, 'report-records');
            result.records.forEach(record => {
                const card = element('article', null, 'report-record'), content = element('div');
                content.append(element('small', record.reference), element('h3', record.subject), badge(record.status), badge(record.priority));
                content.append(element('p', `${record.category} · ${record.location}`), element('p', date(record.created_at)));
                if (management) content.append(element('p', `${record.reporting_mode === 'anonymous' ? 'Anonymous Reporter' : 'Identified report'} · ${record.assigned_reviewer || 'Unassigned'}`));
                const button = element('button', 'View report', 'service-btn service-btn-secondary'); button.type = 'button'; button.addEventListener('click', () => exclusive(button, () => openCase(record.reference, false)));
                card.append(content, button); list.append(card);
            });
            if (!result.records.length) list.append(element('p', management ? 'No reports match these filters.' : state.tab === 'history' ? 'No completed reports match these filters.' : 'No active reports match these filters. Choose New Report to submit a concern. Use Track Anonymous Report for a case submitted anonymously.'));
            $('reportList').replaceChildren(list); $('reportPrevious').disabled = state.page <= 1; $('reportNext').disabled = !result.has_more; $('reportPageLabel').textContent = `Page ${state.page}`;
        } finally { if (version === state.listVersion) $('reportList').removeAttribute('aria-busy'); }
    }
    function updateConditions() {
        if (!form) return;
        const fields = form.elements, anon = fields.reporting_mode.value === 'anonymous';
        $('reportIdentity').hidden = anon; $('reportAnonymousNotice').hidden = !anon;
        [['reportOtherCategory', fields.category.value === 'Other', 'other_category'], ['reportOtherLocation', fields.location.value === 'Other', 'location_details']].forEach(([id, visible, name]) => { $(id).hidden = !visible; fields[name].required = visible; });
        $('reportPersons').hidden = ['No Specific Person', 'Unknown Person'].includes(fields.person_kind.value);
        const staff = ['Security Personnel', 'Maintenance Staff', 'Administrative Staff', 'Other Staff', 'Multiple Persons'].includes(fields.person_kind.value) || fields.category.value === 'Staff Misconduct';
        $('reportStaffScope').hidden = !staff; fields.staff_scope.required = staff;
        if (staff) $('reportOptionalDetails').open = true;
        form.querySelector('.report-emergency').hidden = fields.urgency.value !== 'urgent' && !['Safety Hazard','Security Concern','Harassment or Intimidation'].includes(fields.category.value);
    }
    function validateStep(step = state.step) {
        updateConditions();
        const category = form.elements.category; category.setCustomValidity(category.value && !config.categories.includes(category.value) ? 'Choose one of the available categories.' : '');
        const section = form.querySelector(`[data-report-step="${step}"]`);
        for (const field of section.querySelectorAll('input,select,textarea')) {
            let hidden = false;
            for (let parent = field.parentElement; parent && parent !== section; parent = parent.parentElement) if (parent.hidden) hidden = true;
            if (hidden || field.checkValidity()) continue;
            if (state.step !== step) showStep(step);
            for (let parent = field.parentElement; parent && parent !== section; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
            field.reportValidity(); return false;
        }
        return true;
    }
    function addDefinition(container, title, value, wide = false) { if (!value) return; const wrapper = element('div', null, wide ? 'report-wide' : ''); wrapper.append(element('dt', title), element('dd', value)); container.append(wrapper); }
    function showStep(step) {
        state.step = Math.max(0, Math.min(1, step));
        form.querySelectorAll('[data-report-step]').forEach(node => node.hidden = Number(node.dataset.reportStep) !== state.step);
        document.querySelectorAll('.report-steps li').forEach((node, index) => { node.classList.toggle('done', index < state.step); if (index === state.step) node.setAttribute('aria-current', 'step'); else node.removeAttribute('aria-current'); });
        $('reportBack').hidden = state.step === 0; $('reportContinue').hidden = state.step === 1; $('reportSubmit').hidden = state.step !== 1;
        if (state.step === 1) {
            const data = new FormData(form), review = $('reportReview'); review.replaceChildren();
            [['reporting_mode','Privacy'],['subject','Subject'],['category','Category'],['other_category','Other category'],['urgency','Urgency'],['incident_date','Incident date'],['incident_time','Incident time'],['location','Location'],['location_details','Location details'],['unit_reference','Incident unit / floor'],['description','Description'],['person_kind','Involved person'],['person_name','Name, if known'],['person_unit','Person’s unit'],['person_position','Position'],['person_details','Additional details'],['staff_scope','Staff role involved']].forEach(([key, title]) => {
                if ((key === 'other_category' && data.get('category') !== 'Other') || (key === 'location_details' && data.get('location') !== 'Other') || (['person_name','person_unit','person_position','person_details'].includes(key) && $('reportPersons').hidden) || (key === 'staff_scope' && $('reportStaffScope').hidden)) return;
                const value = key === 'reporting_mode' ? data.get(key) === 'anonymous' ? 'Anonymous — save your private access code' : 'Your account — visible to authorized reviewers only' : key === 'urgency' ? label(data.get(key)) : data.get(key) || (key === 'incident_time' ? 'Unknown / Not sure' : '');
                addDefinition(review, title, value, key === 'description');
            });
            addDefinition(review, 'Evidence', `${state.files.length} selected file(s)`);
            $('reportReviewPrivacy').textContent = data.get('reporting_mode') === 'anonymous' ? 'Your account will not be linked to this case. Save the private access code shown once after submission. Remove identifying information from your report and PDFs.' : 'Your registered name and unit will be visible to authorized case reviewers only.';
        }
        animate(form.querySelector(`[data-report-step="${state.step}"]`));
    }
    async function checkFiles(files) {
        if (files.length > config.limits.files) throw new Error(`Choose up to ${config.limits.files} files.`);
        if (files.reduce((sum, file) => sum + file.size, 0) > config.limits.total_bytes) throw new Error('The combined evidence size exceeds the upload limit.');
        for (const file of files) {
            if (!file.size || file.size > config.limits.bytes || !/\.(jpe?g|png|webp|pdf)$/i.test(file.name)) throw new Error('Use JPG, PNG, WebP or PDF within the displayed size limits.');
            const bytes = new Uint8Array(await file.slice(0, 16).arrayBuffer()), ascii = String.fromCharCode(...bytes);
            const valid = /\.jpe?g$/i.test(file.name) ? bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255 : /\.png$/i.test(file.name) ? bytes[0] === 137 && ascii.slice(1, 4) === 'PNG' : /\.webp$/i.test(file.name) ? ascii.startsWith('RIFF') && ascii.slice(8, 12) === 'WEBP' : ascii.startsWith('%PDF-');
            if (!valid) throw new Error('An evidence file does not match its extension.');
        }
    }
    function previews() {
        const container = $('reportEvidencePreview'); container.querySelectorAll('img').forEach(img => URL.revokeObjectURL(img.src)); container.replaceChildren();
        state.files.forEach((file, index) => { const item = element('div', null, 'report-evidence-item'); if (!/\.pdf$/i.test(file.name)) { const img = element('img'); img.src = URL.createObjectURL(file); img.alt = 'Selected evidence preview'; item.append(img); } item.append(element('p', file.name)); const remove = element('button', 'Remove', 'service-btn service-btn-secondary'); remove.type = 'button'; remove.onclick = () => { state.files.splice(index, 1); previews(); }; item.append(remove); container.append(item); });
    }
    function success(result, renewal = false) {
        $('reportSuccessTitle').textContent = renewal ? 'Your private access code has been renewed.' : result.duplicate ? 'This report was already submitted.' : 'Your report has been submitted successfully.';
        $('reportSuccessReference').textContent = `Reference: ${result.reference}`;
        $('reportSuccessDate').textContent = result.submitted_at ? `Submitted: ${date(result.submitted_at)}` : '';
        $('reportSuccessDate').hidden = !result.submitted_at;
        $('reportSuccessInitial').hidden = renewal || result.duplicate;
        $('reportAccessCodeBlock').hidden = !result.access_code; $('reportAccessCode').value = result.access_code || '';
        $('reportSuccess').showModal();
    }
    async function openCase(reference, anonymous) {
        const detail = await api('read', { reference, anonymous: anonymous ? '1' : '0' });
        state.detail = detail; state.anonymous = anonymous; state.updateRequest = requestKey();
        if ($('reportDashboard')) $('reportDashboard').hidden = true; if ($('reportTrackPanel')) $('reportTrackPanel').hidden = true;
        $('reportDetail').hidden = false;
        const url = new URL(location.href); url.searchParams.set('reference', reference); if (anonymous) url.searchParams.set('anonymous', '1'); else url.searchParams.delete('anonymous'); history.replaceState(null, '', url);
        renderDetail(detail); animate($('reportDetail'));
    }
    function nextStep(detail) {
        const panel = $('reportNextStep'); panel.replaceChildren();
        const guidance = management ? {
            submitted:['Ready for review','Read the report, then start review with a short update to the reporter. Assigning a reviewer is optional.'],
            under_review:['Review in progress','Reply if you need more information, or update the status when work starts or the concern is resolved.'],
            awaiting_information:['Waiting for the reporter','Check for a reply below. Resume review when you have enough information.'],
            in_progress:['Work in progress','Keep the reporter informed. Mark the report resolved once the concern has been addressed.'],
            resolved:['Resolution recorded','You can close the report when follow-up is complete, or resume work if needed.'],
            closed:['Report closed','Its history remains available. Reopen only if further review is needed.'],
            declined:['Report declined','The explanation is in the messages. Reopen if new information warrants a review.'],
            withdrawn:['Report withdrawn','The reporter withdrew this concern. Its recorded history remains available.']
        } : {
            submitted:['Submitted — no further action needed','Management will review your concern. You can send extra details below if needed.'],
            under_review:['Management is reviewing your report','Wait for an update, or add relevant information below.'],
            awaiting_information:['Your reply is needed','Read management’s latest message and send the requested information below.'],
            in_progress:['Management is working on your concern','Follow updates below. You do not need to submit another report for this concern.'],
            resolved:['Your concern has been marked resolved','Read the resolution below. If the concern remains, send a reply.'],
            closed:['Report closed','You can still read its messages and history.'],
            declined:['Management could not proceed','Read the explanation below. Your report and its history remain available.'],
            withdrawn:['You withdrew this report','You can still read the recorded messages and history.']
        };
        const [title, body] = guidance[detail.status] || ['Report update','Read the latest messages below.'];
        panel.append(element('strong',title),element('p',body));
        if (management) {
            const suggested = {submitted:['under_review','Start review'],awaiting_information:['under_review','Resume review'],in_progress:['resolved','Record resolution'],resolved:['closed','Close report'],closed:['under_review','Reopen report'],declined:['under_review','Reopen report'],withdrawn:['under_review','Reopen report']}[detail.status];
            if (suggested && detail.allowed_statuses.includes(suggested[0])) {
                const button = element('button',suggested[1],'service-btn service-btn-secondary'); button.type='button';
                button.onclick=()=>{ if(state.busy)return; $('reportActionForm').elements.operation.value='status'; updateAction(); $('reportActionForm').elements.status.value=suggested[0]; $('reportActionForm').elements.body.focus(); $('reportActionForm').scrollIntoView({block:'center',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'}); };
                panel.append(button);
            }
        }
    }
    function renderDetail(detail) {
        const heading = $('reportCaseHeading'); heading.replaceChildren(element('small', detail.reference), element('h2', detail.subject), badge(detail.status), badge(detail.priority));
        const information = $('reportCaseInformation'); information.replaceChildren();
        [['Reporter', detail.reporter.name], ['Reporter’s unit', detail.reporter.unit], ['Category', detail.category + (detail.other_category ? `: ${detail.other_category}` : '')], ['Incident', `${detail.incident_date} · ${detail.incident_time || 'Unknown time'}`], ['Location', `${detail.location} ${detail.location_details || ''}`], ['Incident unit / floor', detail.unit_reference], ['Description', detail.description], ['Involved person', detail.person_kind], ['Name, if known', detail.person_name], ['Person’s unit', detail.person_unit], ['Staff position', detail.person_position], ['Additional details', detail.person_details], ['Submitted', date(detail.created_at)], ['Last updated', date(detail.updated_at)]].forEach(([title, value]) => addDefinition(information, title, value, title === 'Description'));
        if (management) { addDefinition(information, 'Review access', ({ordinary:'Standard review',admin:'Super Admin review',oversight:'Independent property manager review'})[detail.review_scope] || 'Restricted review'); addDefinition(information, 'Staff excluded from this review', detail.conflicts.map(value => value.full_name).join(', ') || 'None recorded'); }
        nextStep(detail);
        const progress = $('reportProgress'); progress.replaceChildren();
        const reached = detail.events.filter(event => event.new_status);
        reached.forEach((event,index)=>{const item=element('li',null,index===reached.length-1?'current':'complete');item.append(element('strong',label(event.new_status)),element('time',date(event.created_at)));progress.append(item);});
        const timeline = $('reportTimeline'), conversation = $('reportConversation'); timeline.replaceChildren(); conversation.replaceChildren();
        detail.events.forEach(event => {
            const item = element('li', null, event.visibility === 'internal' ? 'internal' : '');
            item.append(element('strong', event.new_status ? label(event.new_status) : label(event.event_type)), element('time', date(event.created_at)));
            if (event.visibility === 'internal') item.append(element('span', 'Internal — management only', 'report-badge'));
            item.append(element('p', event.body)); timeline.append(item);
            if (event.visibility === 'reporter') { const message = element('article', null, 'report-message'); message.append(element('strong', event.actor_kind === 'management' ? 'Management' : event.actor_kind === 'system' ? 'System' : detail.reporter.name), element('p', event.body), element('time', date(event.created_at))); conversation.append(message); }
        });
        const evidence = $('reportCaseEvidence'); evidence.replaceChildren();
        detail.attachments.forEach(file => { const item = element('div', null, 'report-evidence-item'); if (file.deleted_at) item.append(element('p', `${file.display_name}: removed under the retention policy`)); else { const url = new URL(file.url, location.href); if (url.origin !== location.origin) return; const link = element('a', file.display_name); link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer'; if (file.mime !== 'application/pdf') { const img = element('img'); img.src = url.href; img.alt = 'Case evidence'; img.loading = 'lazy'; item.append(img); } else link.textContent += ' (download PDF)'; item.append(link); } evidence.append(item); });
        if (!detail.attachments.length) evidence.append(element('p', 'No evidence attached.'));
        const actions = $('reportActionForm'), operation = actions.elements.operation;
        Array.from(operation.options).forEach(option => { option.hidden = (option.value === 'rotate_code' && !state.anonymous) || (option.value === 'revoke_code' && detail.reporting_mode !== 'anonymous') || (option.value === 'reply' && !management && ['closed','declined','withdrawn'].includes(detail.status)) || (option.value === 'withdraw' && !['submitted','under_review','awaiting_information'].includes(detail.status)); option.disabled = option.hidden; });
        operation.value = [...operation.options].find(option => !option.disabled)?.value || '';
        actions.hidden = !management && !state.anonymous && ['closed','declined','withdrawn'].includes(detail.status);
        let unavailable = $('reportActionsUnavailable'); if (!unavailable) { unavailable = element('p', null, 'report-guidance'); unavailable.id = 'reportActionsUnavailable'; actions.before(unavailable); }
        unavailable.hidden = !actions.hidden; unavailable.textContent = 'This case is no longer accepting reporter replies. Its private history remains available here.';
        actions.elements.body.value = ''; $('reportReplyEvidence').value = '';
        if (management) {
            actions.elements.status.replaceChildren(); detail.allowed_statuses.forEach(value => { const option = element('option', label(value)); option.value = value; actions.elements.status.append(option); });
            [['reviewer_id', detail.reviewers, 'name'], ['conflict_user_id', detail.staff_accounts, 'full_name']].forEach(([field, records, textKey]) => { const select = actions.elements[field]; select.replaceChildren(); records.forEach(record => { const option = element('option', record[textKey]); option.value = record.id; select.append(option); }); });
            const ranks={ordinary:0,admin:1,oversight:2}; [...actions.elements.review_scope.options].forEach(option=>{option.disabled=ranks[option.value]<=ranks[detail.review_scope];});
        }
        $('reportMoreActions').open = false;
        if ($('reportForget')) $('reportForget').hidden = !state.anonymous;
        updateAction();
    }
    function updateAction() {
        const actions = $('reportActionForm'), operation = actions.elements.operation.value, internal = ['note','assign','priority','escalate','conflict','revoke_code'].includes(operation);
        actions.querySelectorAll('[data-report-operation]').forEach(node => node.hidden = node.dataset.reportOperation !== operation);
        $('reportActionBodyLabel').hidden = operation === 'rotate_code'; actions.elements.body.required = !['reply','note','rotate_code'].includes(operation);
        $('reportActionBodyTitle').textContent = internal ? 'Private note / reason — management only' : operation === 'status' ? 'Explain this status update to the reporter *' : operation === 'withdraw' ? 'Why are you withdrawing this report? *' : management ? 'Message to reporter' : 'Your reply';
        actions.elements.body.placeholder = operation === 'status' ? 'What has been reviewed, what happens next, or how was the concern addressed?' : operation === 'note' ? 'Record findings for authorized management. The reporter will not see this note.' : operation === 'reply' ? management ? 'Write an update or ask the reporter for more information.' : 'Add information or answer management’s question.' : 'Explain why this action is needed.';
        $('reportReplyEvidenceLabel').hidden = !['reply','note'].includes(operation);
        $('reportActionPrivacy').textContent = internal ? 'Internal actions and their evidence are never returned to reporters.' : operation === 'rotate_code' ? 'The previous code stops working immediately. Save the replacement shown once.' : 'This update is shared only with this reporter and eligible case reviewers.';
        document.querySelectorAll('[data-report-quick]').forEach(button=>{button.setAttribute('aria-pressed',String(button.dataset.reportQuick===operation));button.disabled=button.dataset.reportQuick==='status'&&!state.detail?.allowed_statuses?.length;});
        if (!actions.querySelector('button[type=submit]').hasAttribute('aria-busy')) actions.querySelector('button[type=submit]').textContent = ({reply:management?'Send message':'Send reply',status:'Update status',note:'Save private note',assign:'Assign reviewer',priority:'Save priority',escalate:'Escalate report',conflict:'Record conflict',revoke_code:'Revoke access code',withdraw:'Withdraw report',rotate_code:'Renew access code'})[operation] || 'Save update';
    }
    document.querySelectorAll('[data-report-quick]').forEach(button=>button.addEventListener('click',()=>{if(state.busy)return;$('reportActionForm').elements.operation.value=button.dataset.reportQuick;$('reportMoreActions').open=false;updateAction();}));
    document.querySelectorAll('[data-report-tab]').forEach(button => { button.addEventListener('click', () => setTab(button.dataset.reportTab)); button.addEventListener('keydown', event => { if (!['ArrowRight','ArrowLeft','Home','End'].includes(event.key)) return; event.preventDefault(); const buttons = [...document.querySelectorAll('[data-report-tab]')], index = buttons.indexOf(button), next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length; buttons[next].click(); buttons[next].focus(); }); });
    document.querySelectorAll('[data-report-new]').forEach(button => button.onclick = () => setTab('new'));
    $('reportFilters')?.addEventListener('submit', event => { event.preventDefault(); state.page = 1; loadList().catch(error => feedback(error.message, true)); });
    let debounce; $('reportFilters')?.elements.search.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => { state.page = 1; loadList().catch(error => feedback(error.message, true)); }, 300); });
    $('reportPrevious')?.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); loadList().catch(error => feedback(error.message, true)); });
    $('reportNext')?.addEventListener('click', () => { state.page++; loadList().catch(error => feedback(error.message, true)); });
    if (form) {
        form.noValidate = true; form.addEventListener('change', updateConditions); $('reportBack').onclick = () => showStep(state.step - 1); $('reportContinue').onclick = () => { if (validateStep()) showStep(state.step + 1); };
        $('reportEvidence').addEventListener('change', async event => { const files = [...state.files, ...event.target.files]; try { await checkFiles(files); state.files = files; previews(); feedback(); } catch (error) { feedback(error.message, true); } event.target.value = ''; });
        form.addEventListener('submit', event => { event.preventDefault(); if (state.step !== 1) { if (validateStep()) showStep(1); return; } if (!validateStep(0) || !validateStep(1)) return;
            exclusive($('reportSubmit'), async () => { await checkFiles(state.files); const data = new FormData(form); data.set('request_key', state.request); if ($('reportStaffScope').hidden) data.set('staff_scope', ''); if ($('reportPersons').hidden) ['person_name','person_unit','person_position','person_details'].forEach(key=>data.set(key,'')); if(data.get('category')!=='Other')data.set('other_category','');if(data.get('location')!=='Other')data.set('location_details',''); const result = await api('create', data, state.files); state.anonymous = form.elements.reporting_mode.value === 'anonymous'; state.detail = { reference: result.reference }; form.reset(); state.files = []; previews(); state.request = requestKey(); showStep(0); success(result); });
        }); updateConditions(); showStep(0);
    }
    $('reportTrackForm')?.addEventListener('submit', event => { event.preventDefault(); const tracking = event.currentTarget; exclusive(tracking.querySelector('button'), async () => { const result = await api('track', new FormData(tracking)); tracking.elements.access_code.value = ''; await openCase(result.reference, true); }); });
    $('reportActionForm').addEventListener('change', updateAction);
    $('reportActionForm').addEventListener('submit', event => { event.preventDefault(); const actions = event.currentTarget; if (!actions.reportValidity() || !state.detail) return;
        exclusive(actions.querySelector('button[type=submit]'), async () => { const files = ['reply','note'].includes(actions.elements.operation.value) ? [...$('reportReplyEvidence').files] : []; await checkFiles(files); const data = new FormData(actions); data.set('reference', state.detail.reference); data.set('version', state.detail.version); data.set('anonymous', state.anonymous ? '1' : '0'); data.set('request_key', state.updateRequest); const result = await api('update', data, files); if (result.still_authorized === false) { setTab('mine'); feedback('The case has been restricted. It is now available only to eligible independent reviewers.'); return; } await openCase(result.reference, state.anonymous); if (result.access_code) success(result, true); else feedback('Case updated.'); });
    });
    $('reportReturn').onclick = () => setTab(config.portal === 'tracking' || state.anonymous ? 'track' : 'mine');
    $('reportReload').onclick = () => exclusive($('reportReload'), () => openCase(state.detail.reference, state.anonymous));
    $('reportForget')?.addEventListener('click', () => exclusive($('reportForget'), async () => { await api('forget'); state.anonymous = false; setTab('track'); feedback('Anonymous tracking session ended.'); }));
    $('reportCopyCode').onclick = async () => { try { await navigator.clipboard.writeText($('reportAccessCode').value); $('reportCopyCode').textContent = 'Copied'; } catch { $('reportAccessCode').select(); } };
    $('reportSuccessClose').onclick = () => { const reference = state.detail.reference, anonymous = state.anonymous; $('reportSuccess').close(); exclusive(null, () => openCase(reference, anonymous)); };
    $('reportSuccess').addEventListener('close', () => { $('reportAccessCode').value = ''; $('reportAccessCodeBlock').hidden = true; $('reportCopyCode').textContent = 'Copy access code'; });
    const params = new URLSearchParams(location.search);
    if (params.has('reference')) openCase(params.get('reference'), params.get('anonymous') === '1').catch(error => feedback(error.message, true));
    else if (config.portal !== 'tracking') loadList().catch(error => feedback(error.message, true));
})();
