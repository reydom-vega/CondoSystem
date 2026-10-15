(() => {
  'use strict';
  const root = document.querySelector('[data-management-dashboard]');
  if (!root) return;
  const content = root.querySelector('[data-management-content]');
  const refresh = root.querySelector('[data-management-refresh]');
  const feedback = root.querySelector('[data-management-feedback]');
  let pending = null, timer = null, stopped = false;
  const tell = (text, error = false) => { feedback.textContent = text; feedback.dataset.error = String(error); };
  const schedule = () => { clearTimeout(timer); if (!stopped && !document.hidden) timer = setTimeout(() => update(false), 60000); };
  async function update(manual) {
    if (pending || stopped) return;
    if (!manual && (document.hidden || content.contains(document.activeElement) || content.querySelector('details[open]'))) { schedule(); return; }
    if (!navigator.onLine) { tell('You are offline. Showing the last loaded dashboard.', true); schedule(); return; }
    pending = new AbortController(); refresh.disabled = true; content.setAttribute('aria-busy','true');
    if (manual) tell('Refreshing dashboard...');
    const timeout = setTimeout(() => pending?.abort(), 20000);
    try {
      const response = await fetch(root.dataset.endpoint, {credentials:'same-origin',cache:'no-store',signal:pending.signal,headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.success || typeof data.html !== 'string') throw Error(data.error || 'Dashboard refresh failed.');
      // Recheck focus after the request so background updates never remove an active control.
      if (!manual && (content.contains(document.activeElement) || content.querySelector('details[open]'))) return;
      content.innerHTML = data.html;
      tell(manual ? 'Dashboard updated.' : '');
    } catch (error) {
      if (!stopped) tell(error.name === 'AbortError' ? 'Refresh timed out. Showing the last loaded dashboard; try again.' : 'Unable to refresh. Showing the last loaded dashboard; try again.', true);
    } finally {
      clearTimeout(timeout); pending = null; refresh.disabled = false; content.setAttribute('aria-busy','false'); schedule();
    }
  }
  refresh.addEventListener('click', () => update(true));
  document.addEventListener('visibilitychange', () => { clearTimeout(timer); if (!document.hidden) schedule(); });
  window.addEventListener('offline', () => tell('You are offline. Showing the last loaded dashboard.',true));
  window.addEventListener('online', () => { tell('Connection restored. Refresh to load the latest dashboard.'); schedule(); });
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); pending?.abort(); });
  window.addEventListener('pageshow', () => { stopped = false; schedule(); });
  schedule();
})();
