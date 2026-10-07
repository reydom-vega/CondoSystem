(() => {
  const transitionName = 'page';
  const shouldAnimate = window.matchMedia('(prefers-reduced-motion: reduce)').matches === false;
  const currentUrl = new URL(window.location.href);

  if (currentUrl.searchParams.get('transition') === transitionName) {
    document.documentElement.classList.add('page-entering');
    document.body.classList.add('page-entering');
    currentUrl.searchParams.delete('transition');
    window.history.replaceState({}, '', currentUrl);
  }

  const transitionLayer = document.querySelector('.page-transition');

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || !link.href || link.target || link.download || link.href === window.location.href) return;

    const destination = new URL(link.href, window.location.origin);
    if (destination.pathname === currentUrl.pathname && destination.search === currentUrl.search) return;
    if (!/\.(php|html)(?:[?#]|$)/i.test(destination.pathname)) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();

    if (transitionLayer) transitionLayer.classList.add('is-active');
    document.body.classList.add('is-transitioning');

    const destinationUrl = new URL(destination.href);
    destinationUrl.searchParams.set('transition', transitionName);

    window.setTimeout(() => {
      window.location.href = destinationUrl.href;
    }, shouldAnimate ? 240 : 0);
  });
})();
