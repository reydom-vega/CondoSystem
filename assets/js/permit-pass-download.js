/* Download the approved permit, using the existing authenticated pass route. */
(() => {
  'use strict';
  const controls = document.querySelector('[data-permit-downloads]');
  if (!controls) return;
  const feedback = document.querySelector('[data-permit-download-feedback]');
  const buttons = [...controls.querySelectorAll('[data-permit-download]')];
  let busy = false;
  const endpoint = format => { const url = new URL(controls.dataset.exportUrl, location.href); url.searchParams.set('download', format); return url; };
  async function responseOrError(response, type) {
    if (response.ok && response.headers.get('Content-Type')?.includes(type)) return response;
    let error; try { error = (await response.json()).error; } catch { /* Login/error HTML is never saved as a document. */ }
    throw new Error(error || 'Unable to download this permit. Reopen the pass and try again.');
  }
  function qrImage(text) {
    if (typeof window.QRCode !== 'function') throw new Error('The QR code could not load. Please refresh this page.');
    const code = new window.QRCode(document.createElement('div'), {text, width:256, height:256, correctLevel:window.QRCode.CorrectLevel.M});
    const matrix = code._oQRCode, modules = matrix.getModuleCount();
    const scale = Math.max(1, Math.floor(480 / (modules + 8))), border = 4 * scale;
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = (modules + 8) * scale;
    const context = canvas.getContext('2d'); context.fillStyle = '#fff'; context.fillRect(0,0,canvas.width,canvas.height); context.fillStyle = '#000';
    for (let y=0;y<modules;y++) for (let x=0;x<modules;x++) if (matrix.isDark(y,x)) context.fillRect(border+x*scale,border+y*scale,scale,scale);
    return canvas;
  }
  function loadImage(url) {
    return new Promise((resolve,reject) => { const image = new Image(); image.onload=()=>resolve(image); image.onerror=()=>reject(new Error('The document logo could not load. Please try again.')); image.src=url; });
  }
  function lines(context, value, width) {
    const result = [];
    for (const paragraph of String(value).split(/\r?\n/)) {
      let line = '';
      for (let word of paragraph.split(/\s+/)) {
        const candidate = line ? line+' '+word : word;
        if (context.measureText(candidate).width <= width) { line=candidate; continue; }
        if (line) { result.push(line); line=''; }
        // Retain very long model numbers/identifiers without clipping them.
        for (const character of Array.from(word)) {
          if (line && context.measureText(line+character).width > width) { result.push(line); line=''; }
          line+=character;
        }
      }
      result.push(line);
    }
    return result;
  }
  async function jpg(documentData, qr) {
    const brand = await loadImage(documentData.brand_image);
    // Measure first so long descriptions and all authorized items are retained.
    const canvas = document.createElement('canvas'); canvas.width=1200;
    const context = canvas.getContext('2d'), margin=60, width=1080;
    context.font='21px Arial';
    const addressLines=lines(context,documentData.property.address,width);
    const headerOffset=addressLines.length*30;
    const blocks=[]; let y=260+headerOffset;
    context.font='26px Arial';
    for (const [label,value] of documentData.fields) {
      const wrapped=lines(context,value,width-32), height=60+wrapped.length*34;
      blocks.push({kind:'field',label,wrapped,y,height}); y+=height;
    }
    const columns=[0,360,540,660,1080];
    if (documentData.items.length) {
      blocks.push({kind:'heading',y}); y+=76;
      for (const item of documentData.items) {
        const cells=[item.name,item.category,String(item.quantity),item.description || '-'];
        context.font='24px Arial';
        const wrapped=cells.map((cell,index)=>lines(context,cell,columns[index+1]-columns[index]-28));
        const height=Math.max(...wrapped.map(cell=>cell.length))*32+28;
        blocks.push({kind:'item',wrapped,y,height}); y+=height;
      }
    }
    context.font='23px Arial'; const noticeLines=lines(context,documentData.notice,width);
    const qrY=y+44; canvas.height=qrY+qr.height+100+noticeLines.length*32+margin;
    context.fillStyle='#fff'; context.fillRect(0,0,canvas.width,canvas.height);
    context.drawImage(brand,margin,50,600,600*brand.naturalHeight/brand.naturalWidth);
    context.fillStyle='#526078'; context.font='21px Arial';
    addressLines.forEach((line,index)=>context.fillText(line,margin,160+index*30));
    context.fillStyle='#172033'; context.font='bold 38px Arial'; context.fillText(documentData.title,margin,190+headerOffset);
    context.font='24px Arial'; context.fillStyle='#526078'; context.fillText(documentData.reference,margin,232+headerOffset);
    context.fillStyle='#187344'; context.textAlign='right'; context.fillText('APPROVED',canvas.width-margin,232+headerOffset); context.textAlign='left';
    for (const block of blocks) {
      if (block.kind==='field') {
        context.fillStyle='#f6f8fb'; context.fillRect(margin,block.y,width,block.height);
        context.fillStyle='#526078'; context.font='20px Arial'; context.fillText(block.label,margin+16,block.y+29);
        context.fillStyle='#172033'; context.font='26px Arial'; block.wrapped.forEach((line,index)=>context.fillText(line,margin+16,block.y+65+index*34));
      } else if (block.kind==='heading') {
        context.fillStyle='#172033'; context.font='bold 28px Arial'; context.fillText('Authorized items',margin,block.y+37);
        context.font='20px Arial'; ['Item','Category','Quantity','Description'].forEach((label,index)=>context.fillText(label,margin+columns[index]+14,block.y+68));
      } else {
        context.font='24px Arial'; context.fillStyle='#172033';
        block.wrapped.forEach((cell,column)=>cell.forEach((line,index)=>context.fillText(line,margin+columns[column]+14,block.y+36+index*32)));
      }
      context.strokeStyle='#d9e0e9'; context.beginPath(); context.moveTo(margin,block.y+(block.height || 76)); context.lineTo(margin+width,block.y+(block.height || 76)); context.stroke();
    }
    context.imageSmoothingEnabled=false; context.drawImage(qr,Math.round((canvas.width-qr.width)/2),qrY);
    context.textAlign='center'; context.fillStyle='#172033'; context.font='24px Arial'; context.fillText('Scan to verify the current permit status',canvas.width/2,qrY+qr.height+36);
    context.textAlign='left'; context.fillStyle='#526078'; context.font='23px Arial'; noticeLines.forEach((line,index)=>context.fillText(line,margin,qrY+qr.height+86+index*32));
    return new Promise((resolve,reject)=>canvas.toBlob(blob=>blob ? resolve(blob) : reject(new Error('Unable to create the JPG. Please try PDF instead.')), 'image/jpeg', .96));
  }
  function save(blob, filename) {
    const url=URL.createObjectURL(blob), link=document.createElement('a'); link.href=url; link.download=filename;
    document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),60000);
  }
  buttons.forEach(button=>button.addEventListener('click',async()=>{
    if (busy) return;
    busy=true; buttons.forEach(item=>item.disabled=true); feedback.textContent='Preparing your download...';
    const loading=window.CelandineLoading?.begin({button,delay:0});
    try {
      const response=await responseOrError(await fetch(endpoint('details'),{cache:'no-store',credentials:'same-origin'}),'application/json');
      const data=await response.json(), qr=qrImage(data.qr_url), format=button.dataset.permitDownload;
      let blob;
      if (format==='jpg') blob=await jpg(data,qr);
      else {
        const response=await responseOrError(await fetch(endpoint('pdf'),{method:'POST',cache:'no-store',credentials:'same-origin',body:new URLSearchParams({csrf_token:data.csrf,qr_image:qr.toDataURL('image/jpeg',1)})}),'application/pdf');
        blob=await response.blob();
      }
      save(blob,data.reference+'.'+format); feedback.textContent='Your '+format.toUpperCase()+' download is ready.';
    } catch(error) { feedback.textContent=error.message || 'Unable to download this permit. Please try again.'; }
    finally { loading?.end(); busy=false; buttons.forEach(item=>item.disabled=false); }
  }));
})();
