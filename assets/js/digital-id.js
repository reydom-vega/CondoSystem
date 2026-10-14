/* Shared ID card. Credentials exist only in memory while the modal is open. */
(() => {
  'use strict';
  function mount() {
  const endpoint = document.querySelector('meta[name="digital-id-endpoint"]')?.content;
  if (!endpoint || document.querySelector('[data-digital-id-toggle]')) return;
  const csrf = document.querySelector('meta[name="digital-id-csrf"]')?.content;
  const icon = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3m5 0v.01M12 7v3a2 2 0 0 1-2 2H7m-4 0h.01M12 3h.01M12 16v.01M16 12h1m4 0v.01M12 21v-1"/></svg>';
  const shield = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg>';
  const button = document.createElement('button');
  button.type = 'button'; button.className = 'digital-id-toggle btn-icon-nav';
  button.dataset.digitalIdToggle = ''; button.title = 'My Digital Condo ID';
  button.setAttribute('aria-label', button.title); button.setAttribute('aria-haspopup', 'dialog');
  button.setAttribute('aria-controls', 'digitalIdDialog'); button.innerHTML = icon;
  const header = document.querySelector('.dash-header-right');
  const theme = header?.querySelector('[data-theme-toggle]');
  if (theme) theme.before(button);
  else if (header) header.prepend(button);
  else return;

  const dialog = document.createElement('dialog');
  dialog.id = 'digitalIdDialog'; dialog.className = 'digital-id-dialog';
  dialog.setAttribute('aria-labelledby', 'digitalIdTitle');
  dialog.innerHTML = `
    <article class="digital-id-card">
      <header class="digital-id-heading">
        <div><h2 id="digitalIdTitle"></h2><p>DIGITAL CONDOMINIUM ID</p></div>
        <button type="button" class="digital-id-close" aria-label="Close Digital Condo ID" autofocus><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
      </header>
      <div class="digital-id-person" hidden>
        <div class="digital-id-avatar" aria-hidden="true"></div>
        <h3 class="digital-id-name"></h3>
        <span class="digital-id-role">${shield}<span></span></span>
        <dl class="digital-id-info"></dl>
        <p class="digital-id-account-status"></p>
      </div>
      <section class="digital-id-qr-section" aria-labelledby="digitalIdScanLabel">
        <h3 id="digitalIdScanLabel">Scan to Verify Identity</h3>
        <div class="digital-id-qr-frame" hidden><div class="digital-id-qr" role="img" aria-label="Short-lived Digital Condo ID QR code"></div></div>
        <p class="digital-id-countdown" role="timer" aria-live="off" hidden></p>
        <p class="digital-id-feedback" role="status" aria-live="polite"></p>
        <p class="digital-id-instructions">Present this digital ID to authorized security personnel.</p>
        <div class="digital-id-actions"><button type="button" class="digital-id-retry" hidden>Try again</button><button type="button" class="digital-id-revoke" hidden>Revoke QR</button></div>
      </section>
      <footer class="digital-id-footer">${shield}<span>Secure Digital Condominium ID</span></footer>
    </article>`;
  const brandTemplate = document.querySelector('template[data-celandine-brand]');
  if (brandTemplate) dialog.querySelector('#digitalIdTitle').append(brandTemplate.content.cloneNode(true));
  else dialog.querySelector('#digitalIdTitle').textContent = 'THE CELANDINE';
  document.body.append(dialog);
  const find = selector => dialog.querySelector(selector);
  const feedback = find('.digital-id-feedback'), qr = find('.digital-id-qr');
  const frame = find('.digital-id-qr-frame'), countdown = find('.digital-id-countdown');
  const retry = find('.digital-id-retry'), revoke = find('.digital-id-revoke');
  let timer, deadline = 0, request = null, cycle = 0, retries = 0, scrollBefore = '', returnFocus, wasOpened = false;

  function stopTimer() { clearTimeout(timer); timer = undefined; }
  function clearQr() {
    stopTimer(); qr.replaceChildren(); qr.removeAttribute('title'); frame.hidden = true; countdown.hidden = true;
    countdown.textContent = ''; deadline = 0; revoke.hidden = true;
  }
  function displayProfile(profile) {
    find('.digital-id-person').hidden = false;
    find('.digital-id-name').textContent = profile.name;
    if (window.CelandineProfile) window.CelandineProfile.paint(find('.digital-id-avatar'),profile);
    else find('.digital-id-avatar').textContent=profile.initial;
    find('.digital-id-role span').textContent = profile.role;
    find('.digital-id-account-status').textContent = `Account ${profile.status.toLocaleLowerCase()}`;
    const info = find('.digital-id-info'); info.replaceChildren();
    for (const [label, value] of [['Unit Number', profile.unit_number], ['Staff ID', profile.staff_id], ['Member Since', profile.member_since]]) {
      if (!value) continue;
      const box = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.textContent = label; dd.textContent = value; box.append(dt, dd); info.append(box);
    }
  }
  async function post(action, signal) {
    const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', cache:'no-store', signal,
      headers:{'Content-Type':'application/json', Accept:'application/json'}, body:JSON.stringify({action,csrf_token:csrf})});
    let data;
    try { data = await response.json(); } catch { throw new Error('Digital ID is temporarily unavailable. Please try again.'); }
    if (!response.ok || !data.success) {
      const error = new Error(data.error || 'Unable to generate your QR code.'); error.status = response.status; throw error;
    }
    return data;
  }
  function tick() {
    if (!dialog.open) return;
    const remaining = deadline - performance.now();
    if (remaining <= 0) { clearQr(); refresh(true); return; }
    countdown.textContent = `Refreshes in: 00:${String(Math.floor(remaining / 1000)).padStart(2, '0')}`;
    timer = setTimeout(tick, Math.min(250, remaining));
  }
  async function refresh(renewal = false) {
    if (!dialog.open || request) return;
    const currentCycle = cycle, controller = new AbortController(), started = performance.now();
    request = controller; clearQr(); retry.hidden = true;
    feedback.textContent = renewal ? 'Refreshing QR Code...' : 'Generating QR Code...';
    find('.digital-id-qr-section').setAttribute('aria-busy', 'true');
    try {
      if (typeof QRCode !== 'function') throw new Error('The QR generator could not load. Refresh this page and try again.');
      const data = await post('issue', controller.signal);
      if (!dialog.open || cycle !== currentCycle) return;
      // Subtract the full round trip so a slow response never extends server expiry.
      const remaining = (data.expires_at - data.server_time) * 1000 - (performance.now() - started);
      if (remaining <= 0) throw new Error('The QR code expired during loading. Please try again.');
      displayProfile(data.profile);
      const generated = new QRCode(document.createElement('div'), {text:data.qr_url, width:256, height:256, colorDark:'#000000', colorLight:'#ffffff', correctLevel:QRCode.CorrectLevel.M});
      const matrix = generated._oQRCode, modules = matrix.getModuleCount();
      frame.hidden = false; frame.style.removeProperty('width');
      // Render the existing generator's matrix on an integer pixel grid. Its
      // default fractional canvas tiles can make some QR masks hard to decode.
      const scale = Math.max(1, Math.floor((frame.clientWidth - 48) / modules));
      const size = modules * scale, canvas = document.createElement('canvas');
      canvas.width = canvas.height = size;
      const context = canvas.getContext('2d');
      context.fillStyle = '#ffffff'; context.fillRect(0, 0, size, size);
      context.fillStyle = '#000000';
      for (let row = 0; row < modules; row++) for (let col = 0; col < modules; col++) {
        if (matrix.isDark(row, col)) context.fillRect(col * scale, row * scale, scale, scale);
      }
      qr.replaceChildren(canvas); qr.style.width = `${size}px`; frame.style.width = `${size + 48}px`;
      countdown.hidden = false; revoke.hidden = false;
      feedback.textContent = ''; deadline = performance.now() + remaining; retries = 0;
      tick();
    } catch (error) {
      if (!dialog.open || cycle !== currentCycle || error.name === 'AbortError') return;
      clearQr(); feedback.textContent = error.message; retry.hidden = false;
      // Authentication/eligibility failures need user action, not a retry loop.
      if (![401,403,429].includes(error.status) && typeof QRCode === 'function') {
        retries++; timer = setTimeout(() => refresh(true), Math.min(30000, 3000 * 2 ** Math.min(retries - 1, 4)));
      }
    } finally {
      if (request === controller) request = null;
      if (cycle === currentCycle) find('.digital-id-qr-section').removeAttribute('aria-busy');
      else if (dialog.open) refresh(); // A close/reopen waits for the canceled request to settle.
    }
  }
  function closed() {
    if (!wasOpened) return;
    wasOpened = false;
    cycle++; request?.abort(); clearQr(); feedback.textContent = ''; retries = 0;
    retry.hidden = true; find('.digital-id-person').hidden = true;
    find('.digital-id-qr-section').removeAttribute('aria-busy');
    document.body.style.overflow = scrollBefore;
    if (returnFocus?.isConnected) returnFocus.focus();
  }
  function openId(opener) {
    if (dialog.open) return;
    returnFocus = opener; scrollBefore = document.body.style.overflow;
    document.body.style.overflow = 'hidden'; wasOpened = true; dialog.showModal();
    if (window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      gsap.fromTo(find('.digital-id-card'), {opacity:0, y:8, scale:.98}, {opacity:1,y:0,scale:1,duration:.2,clearProps:'opacity,transform'});
    }
    refresh();
  }
  button.addEventListener('click', () => openId(button));
  document.querySelectorAll('.profile-dropdown').forEach(dropdown => {
    if (dropdown.querySelector('[data-profile-digital-id]')) return;
    const item = document.createElement('button');
    item.type = 'button'; item.className = 'profile-dropdown-item';
    item.dataset.profileDigitalId = '';
    item.setAttribute('aria-haspopup', 'dialog'); item.setAttribute('aria-controls', dialog.id);
    item.innerHTML = icon + '<span class="profile-menu-label">My QR ID</span>';
    const next = dropdown.querySelector('[data-profile-theme],a[href*="logout.php"]');
    if (next) next.before(item); else dropdown.append(item);
    item.addEventListener('click', event => {
      event.stopPropagation();
      const menu = dropdown.closest('.profile-menu'), toggle = menu?.querySelector('.btn-profile-nav');
      menu?.classList.remove('open'); toggle?.setAttribute('aria-expanded', 'false');
      openId(toggle || button);
    });
  });
  find('.digital-id-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', closed); // Includes native Escape and focus trapping.
  retry.addEventListener('click', () => { stopTimer(); retries = 0; refresh(); });
  revoke.addEventListener('click', async () => {
    if (!dialog.open || request) return;
    const currentCycle = cycle, controller = new AbortController();
    request = controller; clearQr(); feedback.textContent = 'Revoking QR Code...';
    try {
      await post('revoke', controller.signal);
      if (dialog.open && cycle === currentCycle) { feedback.textContent = 'QR Code Revoked. Reopen your ID to generate a new code.'; }
    } catch (error) {
      if (dialog.open && cycle === currentCycle && error.name !== 'AbortError') { feedback.textContent = error.message; retry.hidden = false; }
    } finally {
      if (request === controller) request = null;
      if (cycle !== currentCycle && dialog.open) refresh();
    }
  });
  function checkExpiry() {
    if (dialog.open && deadline > 0 && performance.now() >= deadline) { clearQr(); refresh(true); }
  }
  document.addEventListener('visibilitychange', checkExpiry);
  window.addEventListener('focus', checkExpiry);
  window.addEventListener('pagehide', () => { if (dialog.open) dialog.close(); closed(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { if (dialog.open) dialog.close(); closed(); } });
  }
  // The existing theme bootstrap mounts first, including on scanner headers.
  if (document.readyState !== 'complete') document.addEventListener('DOMContentLoaded', mount, {once:true});
  else mount();
})();
