(() => {
  'use strict';
  const root = document.querySelector('[data-digital-id-verification]');
  if (!root) return;
  const status = document.getElementById('digitalIdVerificationStatus');
  const details = document.getElementById('digitalIdVerificationDetails');
  const token = new URLSearchParams(location.hash.slice(1)).get('credential') || '';
  // Remove the bearer credential from browser history/address bar immediately.
  history.replaceState(null, '', location.pathname);
  if (!/^[a-f0-9]{64}$/.test(token)) { status.textContent = 'Invalid QR Code'; status.dataset.state = 'invalid'; return; }
  const requestId = crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
  let expiryTimer, expiry = 0;
  const started = performance.now();
  function expire() {
    if (expiry && performance.now() >= expiry) { status.textContent = 'QR Code Expired — scan the refreshed code.'; status.dataset.state = 'expired'; details.replaceChildren(); }
  }
  fetch(root.dataset.endpoint, {method:'POST', credentials:'same-origin', cache:'no-store',
    headers:{'Content-Type':'application/json', Accept:'application/json'},
    body:JSON.stringify({credential:token,request_id:requestId,csrf_token:root.dataset.csrf})})
    .then(async response => {
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || 'Verification is unavailable. Scan again.');
      return data.verification;
    }).then(result => {
      status.textContent = result.message; status.dataset.state = result.verification_result;
      if (result.valid && result.profile && root.dataset.reviewer === 'true') {
        const title = document.createElement('h2'); title.textContent = result.profile.name; details.append(title);
        for (const [label,value] of [['Role',result.profile.role], ['Unit',result.profile.unit_number], ['Staff ID',result.profile.staff_id], ['Member since',result.profile.member_since], ['Account status',result.account_status]]) {
          if (!value) continue;
          const p = document.createElement('p'); p.textContent = `${label}: ${value}`; details.append(p);
        }
        const heading = document.createElement('h3'); heading.textContent = 'Current eligibility'; details.append(heading);
        const list = document.createElement('ul');
        for (const right of result.privileges || []) { const li = document.createElement('li'); li.textContent = right; list.append(li); }
        details.append(list);
      }
      if (result.valid && result.expires_at) { const remaining = (result.expires_at - result.server_time) * 1000 - (performance.now() - started); expiry = performance.now() + Math.max(0, remaining); expiryTimer = setTimeout(expire, Math.max(0, remaining)); }
    }).catch(() => { status.textContent = 'Unable to verify. Scan the current code again or contact management.'; status.dataset.state = 'invalid'; });
  document.addEventListener('visibilitychange', expire);
  window.addEventListener('pagehide', () => { clearTimeout(expiryTimer); details.replaceChildren(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { status.textContent = 'Scan the current QR code again to verify.'; status.dataset.state = 'expired'; details.replaceChildren(); } });
})();
