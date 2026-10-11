(() => {
  'use strict';
  const app = document.querySelector('.parking-workspace');
  if (!app) return;
  const tabs = [...app.querySelectorAll('[data-parking-tab]')];
  const panels = [...app.querySelectorAll('[data-parking-panel]')];
  const initialTab = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.parkingTab;
  const allowed = key => tabs.some(tab => tab.dataset.parkingTab === key);
  const panelForHash = () => {
    let id;
    try { id = decodeURIComponent(location.hash.slice(1)); } catch { return null; }
    return id ? document.getElementById(id)?.closest('[data-parking-panel]')?.dataset.parkingPanel : null;
  };
  const activate = (key, update = false, animate = true) => {
    if (!allowed(key)) return;
    tabs.forEach(tab => { const active = tab.dataset.parkingTab === key; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; });
    panels.forEach(panel => { panel.hidden = panel.dataset.parkingPanel !== key; });
    if (update) {
      const url = new URL(location.href);
      // Canonical tab URLs keep old entry points useful as bookmarks and form targets.
      url.pathname = url.pathname.replace(/[^/]+$/, 'parking.php');
      url.searchParams.set('tab', key);
      if (panelForHash() !== key) url.hash = '';
      if (url.href !== location.href) history.pushState({parkingTab:key}, '', url);
    }
    const panel = panels.find(panel => panel.dataset.parkingPanel === key);
    if (animate && panel && window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      window.gsap.fromTo(panel, {opacity:.5,y:6}, {opacity:1,y:0,duration:.22,ease:'power2.out',overwrite:true,clearProps:'opacity,transform'});
    }
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
      event.preventDefault(); activate(tab.dataset.parkingTab, true);
    });
    tab.addEventListener('keydown', event => {
      const next = {ArrowRight:(index+1)%tabs.length, ArrowLeft:(index+tabs.length-1)%tabs.length, Home:0, End:tabs.length-1}[event.key];
      if (next === undefined) return;
      event.preventDefault(); tabs[next].focus(); activate(tabs[next].dataset.parkingTab, true);
    });
  });
  const restore = () => {
    const requested = panelForHash() || new URL(location.href).searchParams.get('tab') || initialTab;
    activate(allowed(requested) ? requested : initialTab, false, false);
    if (panelForHash()) document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView({block:'start'});
  };
  addEventListener('popstate', restore);
  addEventListener('hashchange', restore);
  restore();
  // Reuse existing links between modules without navigation or loss of form input.
  app.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('data-parking-tab') || link.target || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || url.pathname.slice(0,url.pathname.lastIndexOf('/')) !== location.pathname.slice(0,location.pathname.lastIndexOf('/'))) return;
    const route = url.pathname.split('/').pop();
    const key = ['vehicles.php','registeredvehicles.php'].includes(route) ? 'vehicles' : route === 'parkinginventory.php' || route === 'parking_configuration.php' ? 'slots' : route === 'parking.php' ? url.searchParams.get('tab') || (tabs.some(t => t.dataset.parkingTab === 'parking') ? 'parking' : 'overview') : null;
    if (!allowed(key)) return;
    // Vehicle selection must reach PHP so sticker eligibility and the preselection stay authoritative.
    if (url.searchParams.has('vehicle_id') || url.searchParams.has('visitor_id')) return;
    event.preventDefault(); activate(key, true);
    if (route === 'parking_configuration.php') app.querySelector('.parking-config-details')?.setAttribute('open','');
    if (url.hash) { history.replaceState(history.state, '', location.pathname+location.search+url.hash); restore(); document.getElementById(decodeURIComponent(url.hash.slice(1)))?.scrollIntoView({block:'start'}); }
  });
  const filters = app.querySelector('[data-parking-record-filters]');
  if (filters) {
    const rows = [...app.querySelectorAll('[data-parking-record]')];
    const apply = () => {
      const search = filters.querySelector('[name=record_search]').value.trim().toLocaleLowerCase();
      const status = filters.querySelector('[name=record_status]').value;
      let count = 0;
      rows.forEach(row => { row.hidden = (status && row.dataset.status !== status) || !row.textContent.toLocaleLowerCase().includes(search); if (!row.hidden) count++; });
      app.querySelector('[data-record-count]').textContent = `${count} record${count===1?'':'s'}`;
    };
    filters.addEventListener('submit', event => { event.preventDefault(); apply(); });
    filters.addEventListener('input', apply);
    filters.addEventListener('change', apply);
    apply();
  }
})();
