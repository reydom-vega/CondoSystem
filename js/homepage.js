(() => {
  const nav = document.getElementById('homeNav');
  const backToTop = document.getElementById('backToTop');
  const toggle = document.getElementById('navToggle');
  const menu = document.getElementById('mobileMenu');
  const sections = [...document.querySelectorAll('main section[id]')];
  const links = [...document.querySelectorAll('.nav-links a')];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const behavior = () => reducedMotion.matches ? 'auto' : 'smooth';

  function closeMenu() {
    menu.classList.remove('open');
    toggle.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
  }

  function updateScroll() {
    nav.classList.toggle('scrolled', window.scrollY > 18);
    backToTop.classList.toggle('visible', window.scrollY > 700);
    const current = sections.filter(section =>
      section.getBoundingClientRect().top <= nav.offsetHeight + 100
    ).pop();
    links.forEach(link => {
      const active = !!current && link.hash === '#' + current.id;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  }

  window.addEventListener('scroll', updateScroll, { passive: true });
  updateScroll();
  backToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: behavior() }));
  toggle.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
  });
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.classList.contains('open')) {
      closeMenu();
      toggle.focus();
    }
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 1180) closeMenu();
    updateScroll();
  });

  const gallery = document.getElementById('interiorGallery');
  function moveGallery(direction) {
    const slide = gallery.querySelector('.interior-slide');
    const gap = parseFloat(getComputedStyle(gallery).columnGap) || 0;
    gallery.scrollBy({ left: direction * (slide.offsetWidth + gap), behavior: behavior() });
  }
  document.getElementById('galleryPrev').addEventListener('click', () => moveGallery(-1));
  document.getElementById('galleryNext').addEventListener('click', () => moveGallery(1));
  document.querySelectorAll('.amenity-home-preview img').forEach(image => {
    const fallback = () => {
      const preview = image.closest('a');
      preview.classList.add('photo-unavailable');
      preview.removeAttribute('href');
      preview.removeAttribute('target');
      preview.textContent = image.alt + ' · Photo unavailable';
    };
    image.addEventListener('error', fallback, {once:true});
    if(image.complete && !image.naturalWidth) fallback();
  });
})();
