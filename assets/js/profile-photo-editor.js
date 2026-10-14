/* Local crop preview; only the confirmed square is sent to the private API. */
(() => {
  'use strict';
  const dialog=document.getElementById('profilePhotoEditor'); if(!dialog)return;
  const picker=document.getElementById('profilePhotoInput'), change=document.getElementById('changeProfilePhoto');
  const remove=document.getElementById('removeProfilePhoto'), apply=document.getElementById('applyProfilePhoto');
  const canvas=document.getElementById('profilePhotoCanvas'), context=canvas.getContext('2d');
  const stage=document.getElementById('profilePhotoStage'), slider=document.getElementById('profilePhotoZoom');
  const feedback=document.getElementById('profilePhotoFeedback'), cropFeedback=document.getElementById('profilePhotoEditorFeedback');
  let image=null, objectUrl=null, busy=false, selecting=false, generation=0, zoom=1, x=0, y=0, gesture=null;
  const pointers=new Map();
  document.addEventListener('portal:profile-updated',event=>{remove.hidden=!event.detail.profile.photo_url;});
  function message(node,text,error=false){node.textContent=text;node.dataset.error=String(error);}
  function scale(){return Math.max(512/image.naturalWidth,512/image.naturalHeight)*zoom;}
  function draw(){
    if(!image)return;
    const factor=scale(),w=image.naturalWidth*factor,h=image.naturalHeight*factor;
    x=Math.max(-(w-512)/2,Math.min((w-512)/2,x));y=Math.max(-(h-512)/2,Math.min((h-512)/2,y));
    context.fillStyle='#fff';context.fillRect(0,0,512,512);context.drawImage(image,(512-w)/2+x,(512-h)/2+y,w,h);
  }
  function reset(){zoom=1;x=y=0;slider.value='1';draw();}
  function cleanup(){generation++;pointers.clear();gesture=null;image=null;if(objectUrl)URL.revokeObjectURL(objectUrl);objectUrl=null;picker.value='';}
  function close(){if(!busy)dialog.close();}
  dialog.addEventListener('close',()=>{cleanup();change.focus({preventScroll:true});});
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  dialog.querySelectorAll('[data-photo-cancel]').forEach(button=>button.addEventListener('click',close));
  change.addEventListener('click',()=>{if(!busy&&!selecting)picker.click();});
  picker.addEventListener('change',async()=>{
    const file=picker.files?.[0];if(!file||busy||selecting)return;
    selecting=true;const selection=++generation;
    try {
      if(!/\.(jpe?g|png|webp)$/i.test(file.name)||!['image/jpeg','image/png','image/webp'].includes(file.type))throw Error('Choose a JPG, PNG or WebP image.');
      if(!file.size||file.size>5*1024*1024)throw Error('Profile pictures must be 5 MB or smaller.');
      const bytes=new Uint8Array(await file.slice(0,16).arrayBuffer());
      const jpeg=bytes[0]===255&&bytes[1]===216&&bytes[2]===255;
      const png=[137,80,78,71,13,10,26,10].every((b,i)=>bytes[i]===b);
      const webp=String.fromCharCode(...bytes.slice(0,4))==='RIFF'&&String.fromCharCode(...bytes.slice(8,12))==='WEBP';
      if(!jpeg&&!png&&!webp)throw Error('This file does not contain a supported image.');
      objectUrl=URL.createObjectURL(file);const decoded=new Image();decoded.src=objectUrl;await decoded.decode();
      if(selection!==generation)return;
      if(decoded.naturalWidth*decoded.naturalHeight>20000000||Math.max(decoded.naturalWidth,decoded.naturalHeight)>10000)throw Error('Choose an image smaller than 20 megapixels.');
      image=decoded;reset();message(cropFeedback,'');message(feedback,'');dialog.showModal();
      if(window.gsap&&!matchMedia('(prefers-reduced-motion: reduce)').matches)gsap.fromTo(dialog,{opacity:0,y:8},{opacity:1,y:0,duration:.18,clearProps:'opacity,transform'});
    } catch(error){cleanup();message(feedback,error.message||'The image is corrupted or cannot be read.',true);}
    finally{selecting=false;}
  });
  slider.addEventListener('input',()=>{if(!busy){zoom=Number(slider.value);draw();}});
  document.getElementById('resetProfilePhoto').addEventListener('click',()=>{if(!busy)reset();});
  function snapshot(){
    const values=[...pointers.values()];if(!values.length){gesture=null;return;}
    const center=values.length>1?{x:(values[0].x+values[1].x)/2,y:(values[0].y+values[1].y)/2}:values[0];
    gesture={center,x,y,zoom,distance:values.length>1?Math.hypot(values[0].x-values[1].x,values[0].y-values[1].y):0};
  }
  stage.addEventListener('pointerdown',event=>{if(!image||busy)return;event.preventDefault();stage.setPointerCapture(event.pointerId);pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});snapshot();});
  stage.addEventListener('pointermove',event=>{
    if(!pointers.has(event.pointerId)||!gesture||busy)return;
    pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});const values=[...pointers.values()];
    const center=values.length>1?{x:(values[0].x+values[1].x)/2,y:(values[0].y+values[1].y)/2}:values[0];
    const factor=512/stage.getBoundingClientRect().width;
    if(values.length>1&&gesture.distance){zoom=Math.min(4,Math.max(1,gesture.zoom*Math.hypot(values[0].x-values[1].x,values[0].y-values[1].y)/gesture.distance));slider.value=String(zoom);}
    x=gesture.x+(center.x-gesture.center.x)*factor;y=gesture.y+(center.y-gesture.center.y)*factor;draw();
  });
  for(const type of ['pointerup','pointercancel','lostpointercapture'])stage.addEventListener(type,event=>{pointers.delete(event.pointerId);snapshot();});
  stage.addEventListener('keydown',event=>{const delta={ArrowLeft:[-8,0],ArrowRight:[8,0],ArrowUp:[0,-8],ArrowDown:[0,8]}[event.key];if(!delta||busy||!image)return;event.preventDefault();x+=delta[0];y+=delta[1];draw();});
  async function save(action,blob,button){
    if(busy)return;busy=true;
    const target=dialog.open?cropFeedback:feedback;message(target,action==='upload'?'Uploading profile picture...':'Removing profile picture...');
    dialog.querySelectorAll('button,input').forEach(node=>node.disabled=true);remove.disabled=true;change.disabled=true;
    const loading=window.CelandineLoading?.begin({button,fullscreen:false,delay:0,skeleton:false});
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
    let saved=false;
    try{
      const body=new FormData();body.set('action',action);body.set('csrf_token',dialog.dataset.csrf);if(blob)body.set('photo',blob,'profile.jpg');
      const response=await fetch(dialog.dataset.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',body,signal:controller.signal});
      let result;
      try { result=await response.json(); } catch { throw Error(response.status===403?'Your session changed. Refresh the page and try again.':'The server could not process your picture. Please try again.'); }
      if(!response.ok||!result.success)throw Error(result.error||'Unable to save your picture. Please try again.');
      window.CelandineProfile.update(result.profile);remove.hidden=!result.profile.photo_url;
      try{localStorage.setItem('celandine-profile-photo',String(Date.now()));}catch{}
      saved=true;message(feedback,action==='upload'?'Profile picture updated.':'Profile picture removed.');
    }catch(error){message(target,error.name==='AbortError'?'The request timed out. Please retry or refresh to check your saved picture.':error.message||'Unable to save your picture. Please try again.',true);}
    finally{clearTimeout(timeout);loading?.end();busy=false;dialog.querySelectorAll('button,input').forEach(node=>node.disabled=false);remove.disabled=false;change.disabled=false;if(saved&&dialog.open)dialog.close();}
  }
  apply.addEventListener('click',()=>{if(busy||!image)return;const crop=generation;canvas.toBlob(blob=>{if(crop!==generation||!dialog.open)return;if(blob)save('upload',blob,apply);else message(cropFeedback,'Unable to prepare the photo. Please choose it again.',true);},'image/jpeg',.92);});
  remove.addEventListener('click',()=>{if(!busy&&confirm('Remove your profile picture and restore your initial avatar?'))save('remove',null,remove);});
  window.addEventListener('pagehide',cleanup);
})();
