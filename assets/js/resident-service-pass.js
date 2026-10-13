(() => {
  'use strict';
  // The shared head loads the local QR library before this deferred script.
  // Rendering here avoids a CDN request and an inline/deferred loading race.
  const qr = document.querySelector('#serviceQr[data-qr-value],#qrCode[data-qr-value]');
  const fallback = document.getElementById('passQrFallback');
  const download = document.getElementById('downloadPass');
  if (qr?.dataset.qrValue && typeof window.QRCode === 'function') {
    try {
      const size = Number(qr.dataset.qrSize) || 256;
      const generated = new window.QRCode(document.createElement('div'), {text:qr.dataset.qrValue, width:size, height:size, colorDark:'#000000', colorLight:'#ffffff', correctLevel:window.QRCode.CorrectLevel.M});
      const matrix = generated._oQRCode, modules = matrix.getModuleCount();
      const draw = () => {
        const parent = qr.parentElement, parentStyle = getComputedStyle(parent), style = getComputedStyle(qr);
        const horizontal = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth);
        const available = parent.clientWidth - parseFloat(parentStyle.paddingLeft) - parseFloat(parentStyle.paddingRight) - horizontal;
        const limit = matchMedia('print').matches ? Math.min(size,220) : size;
        const pixels = modules * Math.max(1, Math.floor(Math.min(limit,available) / modules));
        if (qr.querySelector('canvas')?.width === pixels) return;
        // Whole pixels per module keep the pass readable at mobile widths.
        const canvas = document.createElement('canvas');canvas.width = canvas.height = pixels;
        const context = canvas.getContext('2d'), scale = pixels / modules;
        context.fillStyle = '#ffffff';context.fillRect(0,0,pixels,pixels);context.fillStyle = '#000000';
        for (let row=0;row<modules;row++) for (let column=0;column<modules;column++) if (matrix.isDark(row,column)) context.fillRect(column*scale,row*scale,scale,scale);
        qr.replaceChildren(canvas);qr.style.width = (pixels + horizontal) + 'px';
      };
      draw();
      const observer = new ResizeObserver(draw);observer.observe(qr.parentElement);
      window.addEventListener('beforeprint',draw);window.addEventListener('afterprint',draw);
      window.addEventListener('pagehide',event => { if (!event.persisted) observer.disconnect(); });
      // QRCode.js adds a title containing the access token; no tooltip is needed.
      qr.removeAttribute('title');
      if (fallback) fallback.hidden = true;
      if (download) {
        download.disabled = false;
        download.addEventListener('click', () => {
          const canvas = qr.querySelector('canvas');
          if (!canvas) return;
          const border = 24, image = document.createElement('canvas');
          image.width = canvas.width + border * 2; image.height = canvas.height + border * 2;
          const context = image.getContext('2d');
          context.fillStyle = '#ffffff'; context.fillRect(0, 0, image.width, image.height);
          context.drawImage(canvas, border, border);
          const link = document.createElement('a');
          link.download = download.dataset.downloadName; link.href = image.toDataURL('image/png'); link.click();
        });
      }
    } catch {
      qr.replaceChildren();
      if (fallback) fallback.hidden = false;
    }
  }
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
