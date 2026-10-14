(() => {
  'use strict';
  const personal = document.getElementById('personalInformationForm');
  const security = document.getElementById('passwordChangeForm');
  if (!personal || !security) return;
  const email = personal.elements.email, emailPassword = document.getElementById('email_current_password');
  const syncEmail = () => {
    const changed = email.value.trim().toLowerCase() !== personal.dataset.originalEmail.toLowerCase();
    document.getElementById('emailPasswordConfirmation').hidden = !changed;
    emailPassword.disabled = !changed; emailPassword.required = changed;
    if (!changed) emailPassword.value='';
  };
  email.addEventListener('input',syncEmail); email.addEventListener('change',syncEmail); syncEmail();
  const next = security.elements.new_password, confirmation = security.elements.confirm_password;
  function syncRules() {
    const value=next.value;
    const valid={len:value.length>=8,case:/[a-z]/.test(value)&&/[A-Z]/.test(value),num:/[0-9]/.test(value),sym:/[^A-Za-z0-9]/.test(value),match:value!==''&&value===confirmation.value};
    document.querySelectorAll('.profile-password-rules [data-rule]').forEach(item=>{
      const ok=valid[item.dataset.rule]; item.classList.toggle('valid',ok);
      item.querySelector('svg').innerHTML=ok?'<path d="m5 12 4 4L19 6"/>':'<path d="m6 6 12 12M18 6 6 18"/>';
    });
    next.setCustomValidity(value && !['len','case','num','sym'].every(rule=>valid[rule]) ? 'Use at least 8 characters, uppercase and lowercase letters, a number and a special character.' : '');
    confirmation.setCustomValidity(confirmation.value && !valid.match ? 'Password confirmation does not match.' : '');
  }
  next.addEventListener('input',syncRules); confirmation.addEventListener('input',syncRules); syncRules();
  document.querySelectorAll('[data-password-target]').forEach(button=>{
    const field=document.getElementById(button.dataset.passwordTarget), label=button.getAttribute('aria-label');
    button.addEventListener('click',()=>{
      const show=field.type==='password'; field.type=show?'text':'password';
      button.setAttribute('aria-pressed',String(show)); button.setAttribute('aria-label',show?label.replace(/^Show/,'Hide'):label);
      field.focus({preventScroll:true});
    });
  });
  const restore=()=>document.querySelectorAll('[data-profile-form]').forEach(form=>{
    delete form.dataset.submitting;
    form.querySelector('[type=submit]').removeAttribute('aria-disabled');
  });
  document.querySelectorAll('[data-profile-form]').forEach(form=>form.addEventListener('submit',event=>{
    if (form.dataset.submitting==='true') { event.preventDefault(); return; }
    if (form===personal) syncEmail(); else syncRules();
    if (!form.reportValidity()) { event.preventDefault(); return; }
    form.dataset.submitting='true';
    // Leave the native submitter enabled so its POST semantics stay intact.
    // The shared loader supplies the spinner and navigation feedback.
    form.querySelector('[type=submit]').setAttribute('aria-disabled','true');
  }));
  window.addEventListener('pageshow',restore);
  const menu=document.getElementById('profileMenu'), toggle=document.getElementById('profileToggle');
  toggle?.addEventListener('click',event=>{ event.stopPropagation(); const open=menu.classList.toggle('open'); toggle.setAttribute('aria-expanded',String(open)); });
  document.addEventListener('click',event=>{ if(menu&&!menu.contains(event.target)) { menu.classList.remove('open'); toggle.setAttribute('aria-expanded','false'); } });
  if (window.gsap) {
    const media=window.gsap.matchMedia();
    media.add('(prefers-reduced-motion: no-preference)',()=>{
      const context=window.gsap.context(()=>window.gsap.fromTo('.profile-settings>.profile-section',{opacity:.5,y:8},{opacity:1,y:0,duration:.3,stagger:.045,ease:'power2.out',clearProps:'opacity,transform'}));
      return ()=>context.revert();
    });
    window.addEventListener('pagehide',event=>{if(!event.persisted)media.revert();});
  }
})();
