(() => {
  'use strict';
  if (window.CelandineMotion) return;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const gsap = window.gsap;
  const canAnimate = () => !!gsap && !reduced.matches && !document.hidden;
  const visible = element => element instanceof HTMLElement && element.getClientRects().length && !element.closest('[hidden],#reader,#serviceQr,.pass-qr');
  const active = new Set();
  function tween(elements,from,to={}) {
    const targets = [...(elements instanceof Element ? [elements] : elements || [])].filter(visible);
    if(!canAnimate() || !targets.length) return;
    const animation = gsap.fromTo(targets,from,{duration:.34,ease:'power2.out',overwrite:'auto',clearProps:'transform,opacity',...to,onComplete:() => active.delete(animation)});
    active.add(animation); return animation;
  }
  function enter(element) { return tween(element,{opacity:.35,y:10},{opacity:1,y:0}); }
  function update(element) { return tween(element,{opacity:.55},{opacity:1,duration:.22}); }
  function closeDialog(dialog,returnValue) {
    if(!dialog?.open) return;
    if(!canAnimate()) { dialog.close(returnValue); return; }
    if(dialog.dataset.portalClosing) return;
    dialog.dataset.portalClosing = 'true';
    gsap.to(dialog,{opacity:0,scale:.985,duration:.14,ease:'power2.in',onComplete:() => {
      delete dialog.dataset.portalClosing; dialog.close(returnValue); gsap.set(dialog,{clearProps:'opacity,transform'});
    }});
  }
  window.CelandineMotion = {enter,update,closeDialog};
  if(!gsap) return; // CSS and native interactions remain fully functional.
  const media = gsap.matchMedia();
  media.add('(prefers-reduced-motion: no-preference)',() => {
    const controller = new AbortController();
    const restoreValues = [];
    const on = (node,event,fn) => node?.addEventListener(event,fn,{signal:controller.signal});
    const header = document.querySelector('.dash-header');
    if(header) tween(header,{opacity:.4,y:-8},{opacity:1,y:0,duration:.3});
    tween(document.querySelectorAll('.portal-homepage .home-nav,.portal-homepage .hero-copy,.portal-homepage .hero-panel'),{opacity:.4,y:10},{opacity:1,y:0,stagger:.04});
    const revealObserver = new IntersectionObserver(entries => {
      entries.forEach(entry => { if(entry.isIntersecting) { enter(entry.target); revealObserver.unobserve(entry.target); } });
    },{threshold:.1});
    document.querySelectorAll('.portal-homepage main .reveal').forEach(element => revealObserver.observe(element));
    const panels = [...document.querySelectorAll('.dashboard-main > section,.dashboard-main > .admin-panel,.dashboard-main > .unit-management-panel,.dashboard-main > .service-panel,.portal-account-page .card,.portal-account-page .pending-card,.portal-account-page .rejected-card,.portal-error-card')].filter(el => !el.querySelector('#reader'));
    tween(panels.slice(0,8),{opacity:.4,y:12},{opacity:1,y:0,stagger:.045});
    const cards = [...document.querySelectorAll('.admin-stat-card,.stat-card')].slice(0,16);
    tween(cards,{opacity:.4,y:10},{opacity:1,y:0,stagger:.025});
    cards.forEach(card => {
      on(card,'pointerenter',() => { if(canAnimate() && matchMedia('(hover:hover)').matches) gsap.to(card,{y:-3,duration:.18,overwrite:'auto'}); });
      on(card,'pointerleave',() => gsap.to(card,{y:0,duration:.18,overwrite:'auto',clearProps:'transform'}));
      const value = card.querySelector('strong,.stat-value');
      if(!value || value.children.length) return;
      const original = value.textContent;
      let counting = true;
      restoreValues.push(() => { if(counting) value.textContent = original; });
      const match = original.trim().match(/^([^\d-]*)(-?[\d,]+(?:\.\d+)?)(\s*%?)$/);
      if(!match || !visible(value)) return;
      const amount = Number(match[2].replaceAll(',',''));
      if(!Number.isFinite(amount)) return;
      const decimals = match[2].includes('.') ? match[2].split('.')[1].length : 0;
      const progress = {value:amount*.85};
      gsap.to(progress,{value:amount,duration:.45,ease:'power2.out',onUpdate:() => { value.textContent=match[1]+progress.value.toLocaleString('en-PH',{minimumFractionDigits:decimals,maximumFractionDigits:decimals})+match[3]; },onComplete:() => {value.textContent=original;counting=false;}});
      // Restore exact backend text even if the preference changes during the tween.
    });
    const pending = new Set(); let frame;
    const flush = () => {
      frame = null;
      pending.forEach(element => {
        if(!visible(element) || element.contains(document.activeElement) && element.querySelector('input:focus,textarea:focus')) return;
        if(element.matches('dialog[open]')) tween(element,{opacity:.4,scale:.985},{opacity:1,scale:1,duration:.22});
        else update(element);
      }); pending.clear();
    };
    const observer = new MutationObserver(records => {
      records.forEach(record => {
        const target = record.target;
        if(!(target instanceof HTMLElement)) return;
        if(record.type==='attributes') {
          if(target.matches('dialog[open],.profile-menu.open,.notification-menu.open,[data-amenity-gallery] img,[data-booking-pane]:not([hidden]),[role=tabpanel]:not([hidden]),.scanner-result,.amenity-reservation-summary')) pending.add(target.matches('img') ? target.closest('[data-amenity-gallery]') : target);
          if(target.matches('.modal-overlay.open,.staff-confirm-overlay.open,.vacancy-confirm-overlay.open,.message-image-viewer.open,.signup-step.active,.step-panel.active,.step-message.show')) pending.add(target.querySelector('.modal-content,.staff-confirm-modal,.vacancy-confirm-modal,.message-image-viewer-panel') || target);
        } else {
          const feedback = target.closest('.alert,[data-booking-feedback],[data-availability-message],[data-time-slots],.amenity-reservation-summary,.admin-chat-messages,.thread-messages,.qr-table-wrap');
          if(feedback) pending.add(feedback);
          record.addedNodes.forEach(node => { if(node instanceof HTMLElement && node.matches('.alert,.thread-message,.message-announcement,dialog[open]')) pending.add(node); });
        }
      });
      if(pending.size && !frame) frame=requestAnimationFrame(flush);
    });
    observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open','hidden','class','src','data-state']});
    on(document,'portal:drawer',event => { if(event.detail.open) tween(event.detail.sidebar.querySelectorAll('.sidebar-link'),{opacity:.5,x:-8},{opacity:1,x:0,stagger:.012,duration:.24}); });
    on(document,'portal:sidebar',event => { update(event.detail.sidebar.querySelector('.sidebar-brand')); });
    // Short feedback on existing calendar fields, without transforming the inputs.
    on(document,'change',event => { if(event.target.matches('input[type=date],select[name=duration_hours]')) update(event.target.closest('form')?.querySelector('.amenity-reservation-summary')); });
    return () => { restoreValues.forEach(restore => restore()); controller.abort(); observer.disconnect(); revealObserver.disconnect(); if(frame) cancelAnimationFrame(frame); pending.clear(); };
  });
  window.addEventListener('pagehide',event => { if(!event.persisted) { media.revert(); active.forEach(animation => animation.kill()); active.clear(); } });
})();
