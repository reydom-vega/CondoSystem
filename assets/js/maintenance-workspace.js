(() => {
  'use strict';
  const app = document.querySelector('.maintenance-workspace');
  if (!app) return;
  const tabs = [...app.querySelectorAll('[data-maintenance-tab]')];
  const panels = [...app.querySelectorAll('[data-maintenance-panel]')];
  const initial = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.maintenanceTab || tabs[0]?.dataset.maintenanceTab;
  const initialUrl = location.href;
  const activate = (key, update = false, animate = false) => {
    if (!tabs.some(tab => tab.dataset.maintenanceTab === key)) key = initial;
    tabs.forEach(tab => {
      const active = tab.dataset.maintenanceTab === key;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    panels.forEach(panel => { panel.hidden = panel.dataset.maintenancePanel !== key; });
    if (update) {
      const url = new URL(location.href);
      url.searchParams.set('tab', key);
      // Each panel has its own status filter and request links.
      url.searchParams.delete('status');
      url.searchParams.delete('view');
      url.hash = '';
      if (url.href !== location.href) history.pushState({maintenanceTab: key}, '', url);
    }
    const panel = panels.find(panel => panel.dataset.maintenancePanel === key);
    if (animate && panel && window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      window.gsap.fromTo(panel, {opacity: .5, y: 6}, {opacity: 1, y: 0, duration: .22, ease: 'power2.out', overwrite: true, clearProps: 'opacity,transform'});
    }
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); activate(tab.dataset.maintenanceTab, true, true);
    });
    tab.addEventListener('keydown', event => {
      const next = {ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1}[event.key];
      if (next === undefined) return;
      event.preventDefault(); tabs[next].focus(); activate(tabs[next].dataset.maintenanceTab, true, true);
    });
  });
  const restore = () => {
    const url = new URL(location.href);
    activate(location.href === initialUrl ? initial : (url.searchParams.get('tab') || initial));
  };
  addEventListener('popstate', restore);
  restore();
})();
