/* Presentation only: native navigation/submission and application fetches stay untouched. */
(() => {
  'use strict';
  if (window.CelandineLoading) return;
  const root = document.documentElement;
  const dark = document.querySelector('meta[name="loading-logo-dark"]')?.content;
  const light = document.querySelector('meta[name="loading-logo-light"]')?.content;
  if (!dark || !light) return;
  const source = () => root.dataset.theme === 'light' ? light : dark;
  // Warm both small official SVGs so a later theme switch needs no new download.
  for (const href of [source(), source() === dark ? light : dark]) {
    const preload = document.createElement('link');
    preload.rel = 'preload'; preload.as = 'image'; preload.href = href;
    preload.fetchPriority = href === source() ? 'high' : 'low';
    document.head.append(preload);
  }
  const operations = new Map(), buttons = new WeakMap(), regions = new WeakMap();
  const deferredEvents = new Set();
  let nextId = 0, overlay, logo, message, notice, noticeTimer, nativeOperation, initialOperation;
  const autoRegions = new Map();
  function mount() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'portal-loading-overlay'; overlay.hidden = true;
    overlay.innerHTML = '<div class="portal-loading-content"><div class="portal-loading-emblem" aria-hidden="true"><span class="portal-loading-ring"></span><img class="portal-loading-logo" width="64" height="64" alt="" data-theme-logo></div><p class="portal-loading-brand">THE CELANDINE</p><p class="portal-loading-message" role="status" aria-live="polite" aria-atomic="true"></p><div class="portal-loading-dots" aria-hidden="true"><span></span><span></span><span></span></div></div>';
    logo = overlay.querySelector('img'); logo.dataset.logoDark = dark; logo.dataset.logoLight = light;
    const wordmark = document.querySelector('template[data-celandine-brand]')?.content.querySelector('.celandine-wordmark');
    if (wordmark) overlay.querySelector('.portal-loading-brand').replaceChildren(wordmark.cloneNode(true));
    logo.addEventListener('load', logoReady);
    logo.addEventListener('error', () => { logo.dataset.ready = 'false'; });
    message = overlay.querySelector('.portal-loading-message');
    document.body.append(overlay);
    syncLogo();
  }
  function logoReady() { logo.dataset.ready = String(logo.complete && logo.naturalWidth > 0 && logo.getAttribute('src') === source()); }
  function syncLogo() {
    if (!logo) return;
    if (logo.getAttribute('src') !== source()) { logo.dataset.ready = 'false'; logo.src = source(); }
    logoReady();
  }
  document.addEventListener('portal:theme', syncLogo);
  new MutationObserver(syncLogo).observe(root, {attributes:true, attributeFilter:['data-theme']});
  function notify(text, error = false, persistent = false) {
    clearTimeout(noticeTimer);
    if (!notice) {
      notice = document.createElement('div'); notice.className = 'portal-loading-notice';
      const copy = document.createElement('span');
      const close = document.createElement('button'); close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Dismiss loading message');
      close.addEventListener('click', () => { notice.hidden = true; });
      notice.append(copy, close); document.body.append(notice);
    }
    notice.setAttribute('role', error ? 'alert' : 'status');
    notice.querySelector('span').textContent = text; notice.dataset.error = String(error); notice.hidden = false;
    if (!persistent) noticeTimer = setTimeout(() => { notice.hidden = true; }, 7000);
  }
  function render() {
    if (!document.body) return;
    mount();
    const active = [...operations.values()].filter(op => op.visible && op.fullscreen).at(-1);
    overlay.hidden = !active;
    if (active) { syncLogo(); message.textContent = active.message; }
  }
  function acquireButton(button) {
    if (!(button instanceof HTMLElement)) return;
    let state = buttons.get(button);
    if (!state) { state = {count:0, aria:button.getAttribute('aria-busy')}; buttons.set(button, state); }
    state.count++; button.classList.add('portal-button-loading'); button.setAttribute('aria-busy', 'true');
    // Never disable/rename a submitter: its name/value must remain in native POST data.
  }
  function releaseButton(button) {
    const state = buttons.get(button);
    if (!state || --state.count > 0) return;
    button.classList.remove('portal-button-loading');
    if (button.getAttribute('aria-busy') === 'true') {
      if (state.aria === null) button.removeAttribute('aria-busy'); else button.setAttribute('aria-busy', state.aria);
    }
    buttons.delete(button);
  }
  function acquireRegion(op) {
    const target = op.target;
    if (!(target instanceof HTMLElement)) return;
    let state = regions.get(target);
    if (!state) {
      state = {count:0, aria:target.getAttribute('aria-busy'), manageBusy:op.manageBusy};
      regions.set(target, state);
    }
    state.count++; target.classList.add('portal-loading-region');
    if (state.manageBusy) target.setAttribute('aria-busy', 'true');
    if (op.skeleton && !target.textContent.trim() && !target.children.length && !/^(TABLE|TBODY|THEAD|TR|SELECT|INPUT|TEXTAREA|BUTTON|IMG|CANVAS|SVG)$/.test(target.tagName)) {
      state.placeholder = document.createElement('div'); state.placeholder.className = 'portal-loading-skeleton'; state.placeholder.setAttribute('aria-hidden', 'true');
      for (let i = 0; i < 3; i++) state.placeholder.append(document.createElement('span'));
      target.append(state.placeholder);
    }
  }
  function releaseRegion(op) {
    const state = regions.get(op.target);
    if (!state || --state.count > 0) return;
    state.placeholder?.remove();
    op.target.classList.remove('portal-loading-region');
    if (state.manageBusy && op.target.getAttribute('aria-busy') === 'true') {
      if (state.aria === null) op.target.removeAttribute('aria-busy'); else op.target.setAttribute('aria-busy', state.aria);
    }
    regions.delete(op.target);
  }
  function end(id) {
    const op = operations.get(id); if (!op) return;
    op.timers.forEach(clearTimeout); op.observer?.disconnect();
    if (op.visible) { releaseButton(op.button); releaseRegion(op); }
    operations.delete(id);
    if (autoRegions.get(op.target)?.id === id) autoRegions.delete(op.target);
    if (nativeOperation?.id === id) nativeOperation = undefined;
    render();
  }
  function begin(options = {}) {
    const id = ++nextId;
    const op = {id, fullscreen:options.fullscreen === true, button:options.button, target:options.target, skeleton:options.skeleton !== false, manageBusy:options.manageBusy !== false,
      message:options.message || 'Preparing your dashboard...', visible:false, timers:[], observer:null};
    const handle = {id, end:() => end(id), fail:(text = 'Something went wrong. Please try again.') => { end(id); if (document.body) notify(text, true); },
      update:text => { if (operations.has(id)) { op.message = String(text); render(); } }};
    if (navigator.onLine === false) { if (document.body) notify('You are offline. Check your connection before trying again.', true, true); return handle; }
    operations.set(id, op);
    const reveal = () => {
      if (!operations.has(id)) return;
      op.visible = true; acquireButton(op.button); acquireRegion(op); render();
    };
    const delay = Math.max(0, options.delay ?? (op.fullscreen ? 400 : 200));
    if (delay === 0) reveal(); else op.timers.push(setTimeout(reveal, delay));
    op.timers.push(setTimeout(() => { if (operations.has(id)) { op.message = 'This is taking a little longer...'; render(); } }, 5000));
    op.timers.push(setTimeout(() => { if (operations.has(id)) { op.message = 'Still working. Please keep this page open.'; render(); } }, 15000));
    op.timers.push(setTimeout(() => {
      end(id); if (document.body) notify('This request is taking longer than expected. Wait for a response before trying again.', false);
    }, Math.max(1000, options.timeout ?? 30000)));
    return handle;
  }
  function reset() {
    deferredEvents.forEach(clearTimeout); deferredEvents.clear();
    [...operations.keys()].forEach(end); autoRegions.clear(); clearTimeout(noticeTimer);
    if (notice) notice.hidden = true;
    nativeOperation = initialOperation = undefined;
  }
  window.CelandineLoading = {begin, reset};
  function afterEvent(callback) {
    const timer = setTimeout(() => { deferredEvents.delete(timer); callback(); }, 0);
    deferredEvents.add(timer);
  }
  function isPageUrl(url) {
    return url.origin === location.origin && /(?:\.php|\/)$/i.test(url.pathname)
      && !url.pathname.includes('/api/')
      && !/(?:receipt|export|download|document|evidence|attachment)\.php$/i.test(url.pathname)
      && !url.searchParams.has('export') && !url.searchParams.has('download');
  }
  function startNavigation(options = {}) {
    initialOperation?.end(); initialOperation = undefined;
    nativeOperation?.end();
    nativeOperation = begin({fullscreen:true, delay:0, message:'Opening your page...', ...options});
  }
  function init() {
    mount();
    // Mount as soon as the parser creates the body, including on direct visits
    // and browser reloads. Never hold navigation or impose a minimum wait.
    if (document.readyState !== 'complete') {
      initialOperation = begin({fullscreen:true, delay:0, message:'Preparing your page...'});
      window.addEventListener('load', () => { initialOperation?.end(); initialOperation = undefined; }, {once:true});
    }
    // Empty busy regions gain placeholders; existing module messages/rows stay intact.
    const busyObserver = new MutationObserver(records => {
      for (const {target} of records) {
        if (!(target instanceof HTMLElement) || target.closest('.portal-loading-overlay,.portal-loading-notice,#reader,#serviceQr,.pass-qr,.digital-id-dialog') || /^(BUTTON|INPUT|SELECT|TEXTAREA)$/.test(target.tagName)) continue;
        if (target.getAttribute('aria-busy') === 'true' && !autoRegions.has(target) && !regions.has(target)) {
          autoRegions.set(target, begin({target, manageBusy:false}));
        } else if (target.getAttribute('aria-busy') !== 'true' && autoRegions.has(target)) {
          autoRegions.get(target).end(); autoRegions.delete(target);
        }
      }
    });
    busyObserver.observe(document.body, {subtree:true, attributes:true, attributeFilter:['aria-busy']});
    document.addEventListener('click', event => {
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.hasAttribute('download') || link.dataset.loading === 'none' || (link.target && link.target !== '_self')) return;
      const url = new URL(link.href, location.href);
      if (!isPageUrl(url)) return;
      if (url.pathname === location.pathname && url.search === location.search && (url.hash || link.getAttribute('href').includes('#'))) return;
      afterEvent(() => {
        if (event.defaultPrevented) return; // Tabs, image viewers and existing router handlers win.
        startNavigation();
      });
    });
    document.addEventListener('submit', event => {
      const form = event.target, button = event.submitter;
      if (!(form instanceof HTMLFormElement) || form.dataset.loading === 'none') return;
      // Controls named action/method/target can shadow the form's DOM properties.
      const target = button?.getAttribute('formtarget') ?? form.getAttribute('target');
      const method = button?.getAttribute('formmethod') ?? form.getAttribute('method');
      if ((target && target !== '_self') || method?.toLowerCase() === 'dialog') return;
      const labelBefore = button?.textContent.trim();
      afterEvent(() => {
        if (!event.defaultPrevented) {
          const action = new URL(button?.getAttribute('formaction') ?? form.getAttribute('action') ?? location.href, location.href);
          if (isPageUrl(action)) startNavigation({button, message:form.matches('#loginForm') ? 'Preparing your dashboard...' : 'Submitting your request...'});
        } else if (button?.disabled || button?.getAttribute('aria-busy') === 'true') {
          const handle = begin({button});
          const op = operations.get(handle.id); if (!op) return;
          const labelChanged = button.textContent.trim() !== labelBefore;
          op.observer = new MutationObserver(() => {
            if (!button.disabled || (labelChanged && button.textContent.trim() === labelBefore)) handle.end();
          });
          op.observer.observe(button, {attributes:true, attributeFilter:['disabled'], childList:true, subtree:true, characterData:true});
        }
      });
    }, true);
    // Includes browser reload, Back/Forward and location.assign()/reload().
    // Observe only: do not intercept, cancel, delay or repeat navigation.
    if (window.navigation?.addEventListener) {
      window.navigation.addEventListener('navigate', event => {
        if (event.destination.sameDocument || event.hashChange || event.downloadRequest != null || !isPageUrl(new URL(event.destination.url))) return;
        const sourceElement = event.sourceElement;
        if (sourceElement?.closest('[data-loading="none"],a[download]')) return;
        afterEvent(() => {
          if (!event.defaultPrevented && !nativeOperation) startNavigation({message:event.navigationType === 'reload' ? 'Reloading your page...' : 'Opening your page...'});
        });
      });
      window.navigation.addEventListener('navigateerror', reset);
      window.navigation.addEventListener('navigatesuccess', () => {
        // Cross-document changes clean up through pagehide/pageshow instead.
        if (document.readyState === 'complete') nativeOperation?.end();
      });
    }
    window.addEventListener('offline', () => { reset(); notify('You are offline. Check your connection before trying again.', true, true); });
    window.addEventListener('online', () => { if (notice && !notice.hidden) notify('You are back online.'); });
    window.addEventListener('error', event => { if (event.error && operations.size) { reset(); notify('Loading was interrupted. Please try again.', true); } });
    window.addEventListener('unhandledrejection', event => { if (event.reason?.name !== 'AbortError' && operations.size) { reset(); notify('Loading was interrupted. Please try again.', true); } });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && overlay && !overlay.hidden) reset(); });
    window.addEventListener('pagehide', reset);
    window.addEventListener('pageshow', reset);
  }
  if (document.body) init();
  else {
    const bodyObserver = new MutationObserver(() => {
      if (!document.body) return;
      bodyObserver.disconnect(); init();
    });
    bodyObserver.observe(root, {childList:true, subtree:true});
  }
})();
