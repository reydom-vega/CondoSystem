(() => {
  'use strict';
  const card = document.querySelector('.visitor-pass');
  if (!card || !window.gsap) return;
  const media = window.gsap.matchMedia();
  media.add('(prefers-reduced-motion: no-preference)', () => {
    // Keep every section usable if JavaScript or animation is unavailable.
    const context = window.gsap.context(() => {
      window.gsap.fromTo(card, {opacity:.4, y:12}, {opacity:1, y:0, duration:.35, ease:'power2.out', clearProps:'opacity,transform'});
      window.gsap.fromTo(card.querySelectorAll('.visitor-pass-row'), {opacity:.45, y:5}, {opacity:1, y:0, duration:.28, delay:.08, stagger:.035, ease:'power2.out', clearProps:'opacity,transform'});
      window.gsap.fromTo(card.querySelector('.visitor-pass-qr-section'), {opacity:.4}, {opacity:1, duration:.3, delay:.2, clearProps:'opacity'});
    }, card);
    const finish = () => context.revert();
    window.addEventListener('beforeprint', finish);
    return () => { window.removeEventListener('beforeprint', finish); context.revert(); };
  });
})();
