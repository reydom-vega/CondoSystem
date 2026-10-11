(() => {
  'use strict';
  const app = document.querySelector('.billing-workspace');
  if (!app) return;
  const tabs = [...app.querySelectorAll('[data-billing-tab]')];
  const panels = [...app.querySelectorAll('[data-billing-panel]')];
  const initial = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.billingTab || 'billing';
  const activate = (key, update = false, animate = false) => {
    if (!tabs.some(tab => tab.dataset.billingTab === key)) key = 'billing';
    tabs.forEach(tab => {
      const active = tab.dataset.billingTab === key;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    panels.forEach(panel => { panel.hidden = panel.dataset.billingPanel !== key; });
    if (update) {
      const url = new URL(location.href);
      url.searchParams.set('tab', key);
      // Each panel has its own status filter; don't carry a paid filter into open bills.
      url.searchParams.delete('status');
      url.hash = '';
      if (url.href !== location.href) history.pushState({billingTab: key}, '', url);
    }
    const panel = panels.find(panel => panel.dataset.billingPanel === key);
    if (animate && panel && window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      window.gsap.fromTo(panel, {opacity: .5, y: 6}, {opacity: 1, y: 0, duration: .22, ease: 'power2.out', overwrite: true, clearProps: 'opacity,transform'});
    }
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); activate(tab.dataset.billingTab, true, true);
    });
    tab.addEventListener('keydown', event => {
      const next = {ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1}[event.key];
      if (next === undefined) return;
      event.preventDefault(); tabs[next].focus(); activate(tabs[next].dataset.billingTab, true, true);
    });
  });
  const restore = () => {
    const url = new URL(location.href);
    activate(url.searchParams.get('tab') || (url.searchParams.get('status') === 'paid' ? 'history' : initial));
  };
  addEventListener('popstate', restore);
  restore();
})();
