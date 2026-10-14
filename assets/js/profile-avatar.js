/* One avatar renderer/event for current-user UI and authorized identity views. */
(() => {
  'use strict';
  const data = document.getElementById('portalProfileData');
  if (!data) return;
  let current; try { current=JSON.parse(data.textContent); } catch { return; }
  const editUrl=document.querySelector('meta[name=profile-edit-url]')?.content;
  const mediaUrl = value => {
    if (!value) return null;
    try { const url=new URL(value,location.href); return url.origin===location.origin && url.pathname.endsWith('/profile_photo.php') ? url.href : null; } catch { return null; }
  };
  function paint(node,profile) {
    node.classList.add('shared-profile-avatar'); node.dataset.profileAvatar=String(profile.id);
    let fallback=node.querySelector('[data-avatar-initial]');
    if (!fallback) { fallback=document.createElement('span'); fallback.dataset.avatarInitial=''; node.replaceChildren(fallback); }
    fallback.textContent=profile.initial || Array.from((profile.name||'?').trim())[0]?.toLocaleUpperCase() || '?';
    const url=mediaUrl(profile.photo_url), previous=node.querySelector('[data-avatar-image]');
    node.dataset.avatarSource=url||'';
    if (!url) { node.querySelectorAll('[data-avatar-image]').forEach(image=>image.remove()); return; }
    if (previous?.getAttribute('src')===url) return;
    const image=document.createElement('img'); image.alt=''; image.dataset.avatarImage=''; image.decoding='async'; image.hidden=true;
    image.addEventListener('load',()=>{if(node.dataset.avatarSource!==url){image.remove();return;}image.hidden=false;node.querySelectorAll('[data-avatar-image]').forEach(other=>{if(other!==image)other.remove();});});
    image.addEventListener('error',()=>{image.remove();previous?.remove();});
    node.append(image); image.src=url;
  }
  function update(profile) {
    if (!profile || Number(profile.id)!==Number(current.id)) return;
    current=profile;
    document.querySelectorAll('[data-profile-avatar]').forEach(node=>{if(Number(node.dataset.profileAvatar)===Number(profile.id))paint(node,profile);});
    document.dispatchEvent(new CustomEvent('portal:profile-updated',{detail:{profile}}));
  }
  window.CelandineProfile={paint,update,get:()=>current};
  // Hydrate any legacy shared-header avatar without changing its dimensions.
  document.querySelectorAll('.profile-avatar,.profile-dropdown-avatar,.portal-account-avatar').forEach(node=>paint(node,current));
  document.querySelectorAll('[data-profile-avatar]').forEach(node=>{if(Number(node.dataset.profileAvatar)===Number(current.id))paint(node,current);});
  // Attach fallback handling to images rendered directly by PHP as well.
  document.querySelectorAll('[data-avatar-image]').forEach(image=>{image.addEventListener('error',()=>image.remove());if(image.complete&&!image.naturalWidth)image.remove();});
  document.querySelectorAll('.profile-dropdown').forEach(dropdown=>{
    let edit=[...dropdown.querySelectorAll('a')].find(link=>link.getAttribute('href')?.includes('edit_profile.php'));
    if (!edit && editUrl) {
      edit=document.createElement('a'); edit.className='profile-dropdown-item'; edit.href=editUrl;
      edit.innerHTML='<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m16 3 5 5-12 12H4v-5zM14 5l5 5"/></svg><span>Edit Profile</span>';
    }
    if(edit) { const header=dropdown.querySelector('.profile-dropdown-header'); if(header)header.after(edit);else dropdown.prepend(edit); }
  });
  new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{
    if(!(node instanceof Element))return;
    if(node.matches('[data-profile-avatar]')&&Number(node.dataset.profileAvatar)===Number(current.id))paint(node,current);
    node.querySelectorAll?.('[data-profile-avatar]').forEach(avatar=>{if(Number(avatar.dataset.profileAvatar)===Number(current.id))paint(avatar,current);});
  }))).observe(document.body,{childList:true,subtree:true});
  window.addEventListener('storage',event=>{
    if(event.key!=='celandine-profile-photo')return;
    const url=new URL(editUrl,location.href);url.pathname=url.pathname.replace(/(?:resident\/)?edit_profile\.php$/,'api/profile_photo.php');url.search='';
    fetch(url,{credentials:'same-origin',cache:'no-store'}).then(response=>response.ok?response.json():null).then(result=>{if(result?.success)update(result.profile);}).catch(()=>{});
  });
})();
