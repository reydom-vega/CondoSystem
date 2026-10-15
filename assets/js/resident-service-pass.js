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
      const generated = new window.QRCode(document.createElement('div'), {text:qr.dataset.qrValue, width:size, height:size, colorDark:'#000000', colorLight:'#ffffff', correctLevel:qr.dataset.qrCorrection === 'Q' ? window.QRCode.CorrectLevel.Q : window.QRCode.CorrectLevel.M});
      const matrix = generated._oQRCode, modules = matrix.getModuleCount();
      const draw = () => {
        const parent = qr.parentElement, parentStyle = getComputedStyle(parent), style = getComputedStyle(qr);
        const horizontal = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth);
        const available = parent.clientWidth - parseFloat(parentStyle.paddingLeft) - parseFloat(parentStyle.paddingRight) - horizontal;
        const limit = matchMedia('print').matches ? Math.min(size,180) : size;
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
        download.addEventListener('click', async () => {
          const canvas = qr.querySelector('canvas');
          if (!canvas) return;
          download.disabled = true;
          try {
            const property = JSON.parse(download.dataset.property);
            const logo = await new Promise((resolve,reject) => {
              const asset = new Image(); asset.onload = () => resolve(asset); asset.onerror = reject; asset.src = download.dataset.brandImage;
            });
            const border = 24, image = document.createElement('canvas');
            // Add the property identity outside the QR quiet zone; QR content stays unchanged.
            image.width = Math.max(640, canvas.width + border * 2); image.height = canvas.height + 250;
            const context = image.getContext('2d');
            context.fillStyle = '#ffffff'; context.fillRect(0, 0, image.width, image.height);
            context.drawImage(logo,border,24,340,340*logo.naturalHeight/logo.naturalWidth);
            context.fillStyle = '#58657a'; context.font = '16px Arial';
            let line = '', addressY = 112;
            for (const word of property.address.split(/\s+/)) {
              const next = line ? line+' '+word : word;
              if (context.measureText(next).width > image.width-border*2 && line) { context.fillText(line,border,addressY); addressY+=24; line=word; } else line=next;
            }
            context.fillText(line,border,addressY);
            context.drawImage(canvas, Math.round((image.width-canvas.width)/2), 160);
            context.textAlign = 'center'; context.fillStyle = '#172033'; context.font = 'bold 18px Arial';
            context.fillText('Visitor Parking Pass',image.width/2,image.height-36);
            const link = document.createElement('a');
            link.download = download.dataset.downloadName; link.href = image.toDataURL('image/png'); link.click();
          } catch {
            window.alert('Unable to prepare the pass image. Please refresh and try again.');
          } finally { download.disabled = false; }
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
