/* Save the existing authenticated visitor pass as a full, readable PNG. */
(() => {
  'use strict';
  const button = document.querySelector('[data-visitor-pass-download]');
  const card = document.querySelector('.visitor-pass');
  const qr = document.querySelector('#serviceQr[data-qr-value]');
  const feedback = document.querySelector('[data-visitor-pass-download-feedback]');
  if (!button || !card || !qr?.querySelector('canvas') || typeof window.QRCode !== 'function') return;
  let busy = false;
  button.disabled = false;

  function wrap(context, value, width) {
    const result = [];
    for (const paragraph of String(value).split(/\r?\n/)) {
      let line = '';
      for (const word of paragraph.split(/\s+/)) {
        const next = line ? line+' '+word : word;
        if (context.measureText(next).width <= width) { line = next; continue; }
        if (line) { result.push(line); line = ''; }
        for (const character of word) {
          if (line && context.measureText(line+character).width > width) { result.push(line); line = ''; }
          line += character;
        }
      }
      result.push(line);
    }
    return result;
  }

  function image(url) {
    return new Promise((resolve, reject) => {
      const asset = new Image();
      const timeout = setTimeout(() => { asset.src = ''; reject(new Error('The pass logo took too long to load. Please try again.')); },15000);
      asset.onload = () => { clearTimeout(timeout); resolve(asset); };
      asset.onerror = () => { clearTimeout(timeout); reject(new Error('The pass logo could not load. Please try again.')); };
      asset.src = url;
    });
  }

  function exportQr() {
    const generated = new window.QRCode(document.createElement('div'), {text:qr.dataset.qrValue, width:256, height:256, correctLevel:window.QRCode.CorrectLevel.Q});
    const matrix = generated._oQRCode, modules = matrix.getModuleCount();
    const scale = Math.max(1, Math.floor(480 / (modules+8))), quiet = scale*4;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = (modules+8)*scale;
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff'; context.fillRect(0,0,canvas.width,canvas.height);
    context.fillStyle = '#000';
    for (let row=0;row<modules;row++) for (let column=0;column<modules;column++) {
      if (matrix.isDark(row,column)) context.fillRect(quiet+column*scale,quiet+row*scale,scale,scale);
    }
    return canvas;
  }

  button.addEventListener('click', async () => {
    if (busy) return;
    busy = true; button.disabled = true; feedback.textContent = 'Preparing pass image...';
    const loading = window.CelandineLoading?.begin({button, delay:0});
    try {
      const property = JSON.parse(button.dataset.property);
      const logo = await image(button.dataset.brandImage), code = exportQr();
      // Draw only pass information, excluding navigation, entry/extension forms
      // and live parking controls. Measure all text before sizing the image.
      const canvas = document.createElement('canvas'); canvas.width = 1000;
      const context = canvas.getContext('2d'), margin = 52, width = canvas.width-margin*2;
      context.font = '21px Arial';
      const address = wrap(context, property.address, width);
      const headerEnd = 185+address.length*29;
      let y = headerEnd+108;
      const blocks = [...card.querySelectorAll('.visitor-pass-section .visitor-pass-row')].map(row => {
        const label = row.querySelector('dt').textContent.trim();
        const value = row.querySelector('dd').cloneNode(true);
        value.querySelectorAll('small').forEach(node => node.remove());
        context.font = '25px Arial';
        const lines = wrap(context, value.textContent.trim() || 'Not provided', width-36);
        const block = {label,lines,y,height:56+lines.length*34}; y += block.height+10;
        return block;
      });
      const status = card.querySelector('.visitor-pass-status strong').textContent.trim();
      const notice = 'Saved pass image. Security must scan the QR code to verify the current approval, visit dates and access permissions. This image does not authorize entry by itself.';
      context.font = '21px Arial'; const noticeLines = wrap(context,notice,width);
      const qrY = y+24; canvas.height = qrY+code.height+108+noticeLines.length*29+70;
      context.fillStyle = '#fff'; context.fillRect(0,0,canvas.width,canvas.height);
      context.drawImage(logo,margin,38,520,520*logo.naturalHeight/logo.naturalWidth);
      context.font = '21px Arial'; context.fillStyle = '#526078';
      address.forEach((line,index) => context.fillText(line,margin,156+index*29));
      context.font = 'bold 36px Arial'; context.fillStyle = '#172033';
      context.fillText('Visitor Access Pass',margin,headerEnd+28);
      context.font = '23px Arial'; context.fillStyle = '#b45309';
      context.fillText(card.querySelector('.visitor-pass-reference').textContent.trim(),margin,headerEnd+65);
      context.textAlign = 'right'; context.fillStyle = '#526078';
      context.fillText(status,canvas.width-margin,headerEnd+65); context.textAlign = 'left';
      blocks.forEach(block => {
        context.fillStyle = '#f5f7fa'; context.fillRect(margin,block.y,width,block.height);
        context.font = '20px Arial'; context.fillStyle = '#526078'; context.fillText(block.label,margin+18,block.y+29);
        context.font = '25px Arial'; context.fillStyle = '#172033';
        block.lines.forEach((line,index) => context.fillText(line,margin+18,block.y+65+index*34));
      });
      context.imageSmoothingEnabled = false;
      context.drawImage(code,Math.round((canvas.width-code.width)/2),qrY);
      context.textAlign = 'center'; context.font = 'bold 23px Arial';
      context.fillText('Scan to verify the current pass status',canvas.width/2,qrY+code.height+38);
      context.textAlign = 'left'; context.font = '21px Arial'; context.fillStyle = '#526078';
      noticeLines.forEach((line,index) => context.fillText(line,margin,qrY+code.height+86+index*29));
      context.font = '16px Arial';
      context.fillText(card.querySelector('.celandine-document-footer')?.textContent.trim() || property.name,margin,canvas.height-30);
      const blob = await new Promise((resolve,reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('Unable to create the pass image. Please try again.')), 'image/png'));
      const url = URL.createObjectURL(blob), link = document.createElement('a');
      link.href = url; link.download = button.dataset.downloadName;
      document.body.append(link); link.click(); link.remove();
      setTimeout(() => URL.revokeObjectURL(url),30000);
      feedback.textContent = 'Pass image downloaded.';
    } catch (error) {
      feedback.textContent = error.message || 'Unable to download the pass image. Please refresh and try again.';
    } finally {
      loading?.end(); busy = false; button.disabled = false;
    }
  });
})();
