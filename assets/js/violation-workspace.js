(() => {
  'use strict';
  const residentName = document.getElementById('violationResident');
  const residentId = document.getElementById('violationResidentId');
  if (residentName && residentId) {
    const options = [...document.querySelectorAll('#violationResidentNames option')];
    const normalize = value => value.trim().replace(/\s+/gu, ' ').toLowerCase();
    const resolve = () => {
      if (residentName.disabled) { residentName.setCustomValidity(''); return; }
      const name = normalize(residentName.value);
      const matches = name ? options.filter(option => normalize(option.value) === name || normalize(option.dataset.residentName) === name) : [];
      residentId.value = matches.length === 1 ? matches[0].dataset.residentId : '';
      residentName.setCustomValidity(!name ? '' : matches.length === 1 ? '' : matches.length > 1
        ? 'Choose the matching name and unit from the suggestions.' : 'Enter an existing resident\'s full name or choose a suggestion.');
    };
    residentName.addEventListener('input', resolve); residentName.addEventListener('change', resolve);
    residentName.form.addEventListener('submit', event => {
      resolve();
      if (!residentName.checkValidity()) { event.preventDefault(); residentName.reportValidity(); }
    });
    resolve();
  }
  const app = document.querySelector('.violation-workspace');
  if (!app) return;
  const tabs = [...app.querySelectorAll('[data-violation-tab]')];
  const panels = [...app.querySelectorAll('[data-violation-panel]')];
  const initial = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.violationTab;
  const allowed = key => tabs.some(tab => tab.dataset.violationTab === key);
  const hashPanel = () => {
    try { return document.getElementById(decodeURIComponent(location.hash.slice(1)))?.closest('[data-violation-panel]')?.dataset.violationPanel; }
    catch { return null; }
  };
  const activate = (key, update = false, animate = false) => {
    if (!allowed(key)) key = initial;
    tabs.forEach(tab => {
      const active = tab.dataset.violationTab === key;
      tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
    });
    panels.forEach(panel => { panel.hidden = panel.dataset.violationPanel !== key; });
    const panel = panels.find(panel => panel.dataset.violationPanel === key);
    if (update) {
      const url = new URL(location.href);
      url.searchParams.set('tab', key);
      url.searchParams.delete('violation');
      if (panel?.dataset.filter) url.searchParams.set('filter', panel.dataset.filter);
      url.hash = '';
      if (url.href !== location.href) history.pushState({violationTab: key}, '', url);
    }
    if (animate && panel && window.gsap && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      window.gsap.fromTo(panel, {opacity:.5,y:6}, {opacity:1,y:0,duration:.22,ease:'power2.out',overwrite:true,clearProps:'opacity,transform'});
    }
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); activate(tab.dataset.violationTab, true, true);
    });
    tab.addEventListener('keydown', event => {
      const next = {ArrowRight:(index+1)%tabs.length, ArrowLeft:(index+tabs.length-1)%tabs.length, Home:0, End:tabs.length-1}[event.key];
      if (next === undefined) return;
      event.preventDefault(); tabs[next].focus(); activate(tabs[next].dataset.violationTab, true, true);
    });
  });
  const restore = () => activate(hashPanel() || new URL(location.href).searchParams.get('tab') || initial);
  addEventListener('popstate', restore); addEventListener('hashchange', restore);
  // A selected lookup result opens the existing form without discarding its fields.
  app.addEventListener('violation:show-issue', () => activate('issue', true));
  restore();
})();
