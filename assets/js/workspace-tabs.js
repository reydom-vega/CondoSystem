(() => {
  'use strict';
  document.querySelectorAll('[data-workspace-tabs]').forEach(app => {
    const tabs = [...app.querySelectorAll('[data-workspace-tab]')];
    const panels = [...app.querySelectorAll('[data-workspace-panel]')];
    if (!tabs.length) return;
    const initial = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.workspaceTab || tabs[0].dataset.workspaceTab;
    const initialUrl = location.href;
    const activate = (key, update = false) => {
      if (!tabs.some(tab => tab.dataset.workspaceTab === key)) key = initial;
      tabs.forEach(tab => {
        const active = tab.dataset.workspaceTab === key;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
      });
      panels.forEach(panel => { panel.hidden = panel.dataset.workspacePanel !== key; });
      if (update) {
        const url = new URL(location.href);
        url.searchParams.set('tab', key);
        url.hash = '';
        if (url.href !== location.href) history.pushState(null, '', url);
        const panel = panels.find(panel => panel.dataset.workspacePanel === key);
        if (panel && window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
          window.gsap.fromTo(panel, {opacity: .5, y: 6}, {opacity: 1, y: 0, duration: .22, ease: 'power2.out', overwrite: true, clearProps: 'opacity,transform'});
        }
      }
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); activate(tab.dataset.workspaceTab, true);
      });
      tab.addEventListener('keydown', event => {
        const next = {ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1}[event.key];
        if (next === undefined) return;
        event.preventDefault(); tabs[next].focus(); activate(tabs[next].dataset.workspaceTab, true);
      });
    });
    addEventListener('popstate', () => activate(location.href === initialUrl ? initial : new URL(location.href).searchParams.get('tab')));
    activate(initial);
  });
})();
