/* Runs synchronously in the shared head, before body content can paint. */
(() => {
  'use strict';
  if (window.CelandineTheme) return;
  const root = document.documentElement;
  const key = 'condo-theme';
  const saved = () => { try { return localStorage.getItem(key) === 'light' ? 'light' : 'dark'; } catch { return 'dark'; } };
  const updateLogo = logo => {
    const source = root.dataset.theme === 'light' ? logo.dataset.logoLight : logo.dataset.logoDark;
    if (source && logo.getAttribute('src') !== source) logo.setAttribute('src', source);
  };
  const updateLogos = () => document.querySelectorAll('[data-theme-logo]').forEach(updateLogo);
  const updateButtons = () => document.querySelectorAll('[data-theme-toggle],[data-profile-theme]').forEach(button => {
    const label = root.dataset.theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode';
    button.setAttribute('aria-label', button.hasAttribute('data-profile-theme') ? 'Light/Dark Mode' : label);
    if (button.hasAttribute('data-profile-theme')) button.setAttribute('aria-checked', String(root.dataset.theme === 'light'));
    button.title = label;
  });
  function apply(theme, {persist = false} = {}) {
    theme = theme === 'light' ? 'light' : 'dark';
    const switching = document.body && root.dataset.theme !== theme;
    if (switching) root.classList.add('theme-changing');
    try {
      root.dataset.theme = theme;
      updateLogos();
      if (persist) { try { localStorage.setItem(key, theme); } catch {} }
      updateButtons();
      document.dispatchEvent(new CustomEvent('portal:theme', {detail: {theme}}));
      // Resolve the new palette while transitions are disabled, including
      // legacy hover colors. Restore normal interactions before returning.
      if (switching) void document.body.offsetWidth;
    } finally {
      if (switching) root.classList.remove('theme-changing');
    }
  }
  apply(saved());
  // Update parser-created logos before paint, including a restored Light Mode.
  // Also covers shared brand marks added later by a partial page update.
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    if (node.matches('[data-theme-logo]')) updateLogo(node);
    node.querySelectorAll('[data-theme-logo]').forEach(updateLogo);
  }))).observe(root, {childList:true, subtree:true});
  window.CelandineTheme = {set: theme => apply(theme, {persist:true}), get: () => root.dataset.theme};
  function mount() {
    updateLogos();
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'theme-toggle';
    button.setAttribute('data-theme-toggle', '');
    // Lucide sun and moon outlines. No icon-library loading or image filtering.
    button.innerHTML = '<svg class="theme-sun" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg><svg class="theme-moon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.464.402.807a6.25 6.25 0 0 0 8.268 8.268c.344-.215.83-.004.803.397"/></svg>';
    const notification = document.querySelector('.notification-menu');
    const publicNav = document.querySelector('.home-nav .nav-cta');
    if (notification) notification.before(button);
    else if (document.querySelector('.dash-header-right')) document.querySelector('.dash-header-right').prepend(button);
    else if (publicNav) publicNav.prepend(button);
    else { button.classList.add('theme-toggle-floating'); document.body.append(button); }
    const toggle = () => window.CelandineTheme.set(root.dataset.theme === 'dark' ? 'light' : 'dark');
    button.addEventListener('click', toggle);
    document.querySelectorAll('.profile-dropdown').forEach(dropdown => {
      if (dropdown.querySelector('[data-profile-theme]')) return;
      const switchButton = document.createElement('button');
      switchButton.type = 'button'; switchButton.className = 'profile-dropdown-item';
      switchButton.dataset.profileTheme = '';
      switchButton.setAttribute('role', 'switch');
      switchButton.innerHTML = '<span class="profile-theme-icons" aria-hidden="true">' + button.innerHTML + '</span><span class="profile-menu-label">Light/Dark Mode</span><span class="profile-theme-switch" aria-hidden="true"></span>';
      const logout = dropdown.querySelector('a[href*="logout.php"]');
      if (logout) logout.before(switchButton); else dropdown.append(switchButton);
      switchButton.addEventListener('click', event => { event.stopPropagation(); toggle(); });
    });
    updateButtons();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true});
  else mount();
  window.addEventListener('storage', event => {
    if (event.key === key || event.key === null) apply(event.newValue === 'light' ? 'light' : 'dark');
  });
  window.addEventListener('pageshow', event => { if (event.persisted) apply(saved()); });
})();
