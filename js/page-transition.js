// Legacy auth-page entry point: navigation stays native and immediate.
// Page entrance and reduced-motion support are centralized in assets/js/animations.js.
(() => {
  const url = new URL(location.href);
  if (url.searchParams.get('transition') === 'page') {
    url.searchParams.delete('transition');
    history.replaceState(null, '', url);
  }
})();
