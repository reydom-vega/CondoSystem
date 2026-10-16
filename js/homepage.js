/* Homepage interactions only; shared theme/auth/loading controllers are reused. */
(() => {
  'use strict';
  if (!document.body.classList.contains('portal-homepage')) return;
  const $ = selector => document.querySelector(selector);
  const nav = $('#homeNav'), menu = $('#mobileMenu'), toggle = $('#navToggle');
  const top = $('#backToTop'), hero = $('.hero-bg');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const behavior = () => reduced.matches ? 'auto' : 'smooth';
  const sections = [...document.querySelectorAll('main section[id]')];
  const links = [...document.querySelectorAll('.nav-links a, #mobileMenu > a[href^="#"]')];
  let frame;
  function closeMenu(focus = false) {
    const wasOpen = !menu.hidden;
    menu.hidden = true;
    menu.classList.remove('open'); toggle.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-label', 'Open menu');
    if (focus && wasOpen) toggle.focus({preventScroll:true});
  }
  toggle.addEventListener('click', () => {
    const open = menu.hidden;
    toggle.focus({preventScroll:true});
    menu.hidden = !open; menu.classList.toggle('open', open); toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  });
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    closeMenu();
    if (link.hash && link.pathname === location.pathname) {
      const target = document.getElementById(link.hash.slice(1));
      if (target) {
        target.setAttribute('tabindex', '-1'); target.focus({preventScroll:true});
        target.addEventListener('blur', () => target.removeAttribute('tabindex'), {once:true});
      }
    }
  }));
  document.addEventListener('pointerdown', event => {
    if (!menu.hidden && !menu.contains(event.target) && !nav.contains(event.target)) closeMenu();
  });
  document.addEventListener('focusin', event => {
    if (!menu.hidden && !menu.contains(event.target) && !nav.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !menu.hidden) { event.preventDefault(); closeMenu(true); }
  });
  function updateScroll() {
    frame = null;
    nav.classList.toggle('scrolled', scrollY > 18);
    top.hidden = scrollY <= 600;
    const current = sections.filter(section => section.getBoundingClientRect().top <= nav.offsetHeight + 110).pop();
    // Sections sharing a grid row are both current; stacked mobile panels remain separate.
    const row = current?.closest('.dual-panels');
    const activeIds = new Set((row
      ? [...row.querySelectorAll('section[id]')].filter(section => section.offsetTop === current.offsetTop)
      : current ? [current] : []).map(section => '#' + section.id));
    links.forEach(link => {
      const active = activeIds.has(link.hash);
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current');
    });
    // Small, compositor-only translation; the image has extra bleed to avoid gaps.
    hero.style.transform = !reduced.matches && innerWidth > 640
      ? `translate3d(0,${Math.min(24, scrollY * .055)}px,0)` : '';
  }
  const scheduleScroll = () => { if (!frame) frame = requestAnimationFrame(updateScroll); };
  window.addEventListener('scroll', scheduleScroll, {passive:true});
  window.addEventListener('resize', () => { if (innerWidth > 1240) closeMenu(); scheduleScroll(); });
  top.addEventListener('click', () => { window.scrollTo({top:0, behavior:behavior()}); $('#main-content').focus({preventScroll:true}); });
  updateScroll();

  const galleries = [];
  function gallery(track, previous, next) {
    const update = () => {
      const max = track.scrollWidth - track.clientWidth;
      previous.disabled = track.scrollLeft <= 2; next.disabled = track.scrollLeft >= max - 2;
    };
    const move = direction => track.scrollBy({left:direction * ((track.firstElementChild?.offsetWidth || 240) + 14), behavior:behavior()});
    previous.hidden = next.hidden = false;
    previous.addEventListener('click', () => move(-1)); next.addEventListener('click', () => move(1));
    track.addEventListener('scroll', update, {passive:true});
    track.addEventListener('keydown', event => {
      if (event.target !== track) return;
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); move(event.key === 'ArrowLeft' ? -1 : 1); }
      if (event.key === 'Home' || event.key === 'End') { event.preventDefault(); track.scrollTo({left:event.key === 'Home' ? 0 : track.scrollWidth, behavior:behavior()}); }
    });
    galleries.push(update); update();
    if ('ResizeObserver' in window) new ResizeObserver(update).observe(track);
  }
  gallery($('#amenityGallery'), $('#amenityPrev'), $('#amenityNext'));
  gallery($('#interiorGallery'), $('#galleryPrev'), $('#galleryNext'));

  const dialog = $('#homeLightbox'), image = $('#homePhoto'), title = $('#homePhotoTitle');
  const status = $('#homePhotoStatus'), count = $('#homePhotoCount');
  let photos = [], photoIndex = 0, opener, previousOverflow;
  function showPhoto(index) {
    photoIndex = (index + photos.length) % photos.length;
    const link = photos[photoIndex];
    title.textContent = link.dataset.photoTitle; image.alt = link.dataset.photoTitle;
    status.textContent = 'Loading photograph\u2026'; image.src = link.href;
    count.textContent = `${photoIndex + 1} / ${photos.length}`;
    if (image.complete && image.naturalWidth) status.textContent = '';
  }
  image.addEventListener('load', () => { status.textContent = ''; });
  image.addEventListener('error', () => { status.textContent = 'This photograph is currently unavailable. Please try another photo.'; });
  document.querySelectorAll('[data-home-preview]').forEach(link => link.addEventListener('click', event => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || typeof dialog.showModal !== 'function') return;
    try { dialog.showModal(); } catch (_) { return; } // Original image link remains the fallback.
    event.preventDefault(); opener = link;
    photos = [...link.closest('.gallery-track').querySelectorAll('[data-home-preview]')];
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    showPhoto(photos.indexOf(link)); dialog.querySelector('.lightbox-close').focus();
  }));
  $('#photoPrev').addEventListener('click', () => showPhoto(photoIndex - 1));
  $('#photoNext').addEventListener('click', () => showPhoto(photoIndex + 1));
  dialog.querySelector('.lightbox-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    const rect = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
  });
  dialog.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); showPhoto(photoIndex + (event.key === 'ArrowLeft' ? -1 : 1)); }
  });
  dialog.addEventListener('close', () => {
    document.body.style.overflow = previousOverflow || '';
    image.removeAttribute('src'); status.textContent = '';
    if (opener?.isConnected) opener.focus({preventScroll:true});
  });

  // Nothing is hidden in CSS pending JS. Failed animation dependencies leave all content readable.
  let observer, introObserver, heroPlayed = false;
  const animations = new Set();
  function animate(targets, stagger = 0) {
    if (reduced.matches || !window.gsap || document.hidden) return;
    const animation = gsap.fromTo(targets, {opacity:.15, y:16}, {
      opacity:1, y:0, duration:.65, stagger, ease:'power2.out', clearProps:'opacity,transform',
      onComplete:() => animations.delete(animation)
    });
    animations.add(animation);
  }
  function revealHero() {
    if (heroPlayed || document.documentElement.dataset.celIntro === 'playing') return;
    heroPlayed = true; introObserver?.disconnect();
    animate($('.hero-text').children, .09); animate($('.hero-facts').children, .06);
  }
  function restoreMotion() {
    observer?.disconnect();
    animations.forEach(animation => { animation.progress(1); animation.kill(); }); animations.clear();
    updateScroll();
  }
  function startReveals() {
    if (reduced.matches || !window.gsap || !('IntersectionObserver' in window)) return;
    observer = new IntersectionObserver(entries => entries.forEach(entry => {
      if (!entry.isIntersecting || document.documentElement.dataset.celIntro === 'playing') return;
      animate(entry.target.hasAttribute('data-home-stagger') ? entry.target.children : entry.target, .05);
      observer.unobserve(entry.target);
    }), {threshold:.08});
    document.querySelectorAll('[data-home-reveal],[data-home-stagger]').forEach(element => observer.observe(element));
  }
  if (document.documentElement.dataset.celIntro === 'playing') {
    introObserver = new MutationObserver(() => {
      if (document.documentElement.dataset.celIntro !== 'playing') { revealHero(); startReveals(); }
    });
    introObserver.observe(document.documentElement, {attributes:true, attributeFilter:['data-cel-intro']});
  } else { revealHero(); startReveals(); }
  reduced.addEventListener('change', () => { restoreMotion(); if (!reduced.matches) startReveals(); });
  window.addEventListener('pagehide', () => {
    closeMenu(); if (dialog.open) dialog.close(); restoreMotion();
    if (frame) cancelAnimationFrame(frame); frame = null;
  });
  window.addEventListener('pageshow', event => { if (event.persisted) { updateScroll(); galleries.forEach(update => update()); } });
})();
