/* Runs before the body: a seen/reduced-motion/no-storage visit never shows the overlay. */
(() => {
  'use strict';
  const html = document.documentElement;
  const key = 'celandineIntroSeenV1';
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let eligible = false;
  try {
    eligible = sessionStorage.getItem(key) !== '1' && !motion.matches;
    sessionStorage.setItem(key, '1');
  } catch (_) { eligible = false; } // Fail open when browser storage is disabled.
  if (!eligible) return;

  html.dataset.celIntro = 'playing';
  let complete = false, timeline, root, skip, previousFocus;
  const inertElements = new Map();
  const controller = new AbortController();
  const watchdog = setTimeout(() => finish(false), 8000);
  function finish(restoreFocus = true) {
    if (complete) return;
    complete = true;
    clearTimeout(watchdog);
    timeline?.kill();
    controller.abort();
    motion.removeEventListener('change', changedMotion);
    root ||= document.getElementById('cel-intro');
    const focusWasInIntro = root?.contains(document.activeElement);
    if (root) { root.hidden = true; root.setAttribute('aria-hidden', 'true'); }
    delete html.dataset.celIntro;
    inertElements.forEach((wasInert, element) => { element.inert = wasInert; });
    inertElements.clear();
    if (restoreFocus && focusWasInIntro) {
      if (previousFocus?.isConnected && previousFocus !== document.body && !root.contains(previousFocus)) {
        previousFocus.focus({preventScroll:true});
      } else {
        // Resume at the start of the page without revealing its keyboard skip link.
        // The next Tab still reaches that link normally.
        const bodyTabIndex = document.body.getAttribute('tabindex');
        document.body.setAttribute('tabindex', '-1');
        document.body.focus({preventScroll:true});
        if (bodyTabIndex === null) document.body.removeAttribute('tabindex');
        else document.body.setAttribute('tabindex', bodyTabIndex);
      }
    }
  }
  function changedMotion() { if (motion.matches) finish(); }
  motion.addEventListener('change', changedMotion);
  document.addEventListener('click', event => {
    if (event.target instanceof Element && event.target.closest('#cel-skip')) finish();
  }, {signal:controller.signal});
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); finish(); }
    else if (event.key === 'Tab') { event.preventDefault(); document.getElementById('cel-skip')?.focus({preventScroll:true}); }
  }, {signal:controller.signal});
  window.addEventListener('pagehide', () => finish(false), {signal:controller.signal});
  window.addEventListener('pageshow', event => { if (event.persisted) finish(false); }, {signal:controller.signal});

  function start() {
    if (complete) return;
    root = document.getElementById('cel-intro');
    skip = document.getElementById('cel-skip');
    if (!root || !skip || getComputedStyle(root).position !== 'fixed' || typeof window.gsap?.timeline !== 'function' || motion.matches) { finish(false); return; }
    try {
      previousFocus = document.activeElement;
      root.hidden = false;
      [...document.body.children].forEach(element => {
        if (element === root || element.matches('script,style,link,template,.portal-loading-overlay,.portal-loading-notice')) return;
        inertElements.set(element, element.inert);
        element.inert = true;
      });
      skip.focus({preventScroll:true});
      const petals = [...root.querySelectorAll('.cel-petal')].sort((a,b) => Number(a.dataset.index) - Number(b.dataset.index));
      if (!petals.length) { finish(); return; }
      const gsap = window.gsap;
      petals.forEach(petal => gsap.set(petal, {svgOrigin:petal.style.transformOrigin.replaceAll('px',''), opacity:0, scale:.18, rotation:-14}));
      const wordmark = root.querySelector('.cel-intro-wordmark');
      const halo = root.querySelector('.cel-intro-halo');
      const glow = root.querySelector('.cel-intro-glow');
      gsap.set(wordmark, {opacity:0,y:15});
      timeline = gsap.timeline({onComplete:() => finish()});
      timeline.to(petals, {opacity:1,scale:1,rotation:0,duration:.72,stagger:{each:.027},ease:'back.out(1.5)'}, .12)
        .to(halo, {opacity:.7,scale:1.15,duration:.8,ease:'power2.out'}, .7)
        .to(halo, {opacity:0,scale:1.3,duration:.5}, 1.35)
        .to(wordmark, {opacity:1,y:0,duration:.52,ease:'power2.out'}, 1.2)
        .to(glow, {opacity:1,duration:.55}, 1.6)
        .to(root, {opacity:0,duration:.55,ease:'power2.inOut'}, 2.35);
    } catch (_) { finish(); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true,signal:controller.signal});
  else start();
})();
