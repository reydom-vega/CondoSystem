(() => {
  const app = document.getElementById('amenityApp');
  if (!app) return;
  const viewNav = app.querySelector('.amenity-view-nav');
  if (viewNav) {
    const tabs = [...viewNav.querySelectorAll('[data-amenity-view]')];
    function activateView(id, updateHash = false) {
      if (!tabs.some(tab => tab.dataset.amenityView === id)) id = 'exploreAmenities';
      tabs.forEach(tab => {
        const active = tab.dataset.amenityView === id;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        document.getElementById(tab.dataset.amenityView).hidden = !active;
      });
      if (updateHash) history.replaceState(null, '', `#${id}`);
    }
    viewNav.hidden = false;
    viewNav.setAttribute('role', 'tablist');
    tabs.forEach(tab => {
      tab.setAttribute('role', 'tab');
      const panel = document.getElementById(tab.dataset.amenityView);
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', tab.id);
      panel.tabIndex = 0;
      tab.addEventListener('click', () => activateView(tab.dataset.amenityView, true));
      tab.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[index].click(); tabs[index].focus();
      });
    });
    app.querySelectorAll('[data-amenity-view-link]').forEach(link => link.addEventListener('click', () => activateView(link.hash.slice(1))));
    window.addEventListener('hashchange', () => activateView(location.hash.slice(1)));
    activateView(location.hash.slice(1));
  }
  const endpoint = '../api/amenity_bookings.php';
  const galleries = new Map();
  const lightbox = document.getElementById('amenityLightbox');
  const lightboxImage = document.getElementById('amenityLightboxImage');
  let activeGallery;
  document.querySelectorAll('[data-amenity-gallery]').forEach(gallery => {
    const images = JSON.parse(gallery.dataset.images || '[]');
    const state = {gallery, images, index: 0, name: gallery.dataset.name};
    galleries.set(gallery,state);
    const img = gallery.querySelector('img');
    const fallback = gallery.querySelector('.amenity-image-fallback');
    function failed() { gallery.querySelector('[data-open-image]').hidden = true; fallback.hidden = false; }
    img?.addEventListener('error',failed);
    if(img?.complete && !img.naturalWidth) failed();
    gallery.addEventListener('click',event => {
      const step = event.target.closest('[data-gallery-step]');
      if(step) {
        state.index = (state.index + Number(step.dataset.galleryStep) + images.length) % images.length;
        gallery.querySelector('[data-open-image]').hidden = false; fallback.hidden = true;
        img.src = images[state.index]; img.alt = `${state.name} at The Celandine Homes, photo ${state.index+1}`;
        gallery.querySelector('[data-gallery-count]').textContent = `${state.index+1} / ${images.length}`;
      }
      if(event.target.closest('[data-open-image]') && images.length) { activeGallery = state; showPhoto(); lightbox.showModal(); }
    });
  });
  function showPhoto() {
    const {name,images,index} = activeGallery;
    document.getElementById('amenityLightboxTitle').textContent = name;
    document.getElementById('amenityLightboxFallback').hidden = true;
    lightboxImage.hidden = false; lightboxImage.src = images[index]; lightboxImage.alt = `${name} at The Celandine Homes, photo ${index+1}`;
    document.getElementById('amenityLightboxCount').textContent = `${index+1} / ${images.length}`;
    lightbox.querySelectorAll('[data-lightbox-step]').forEach(button => button.hidden = images.length < 2);
  }
  lightboxImage?.addEventListener('error',() => { lightboxImage.hidden = true; document.getElementById('amenityLightboxFallback').hidden = false; });
  function changePhoto(step) { if(!activeGallery) return; activeGallery.index = (activeGallery.index + step + activeGallery.images.length) % activeGallery.images.length; showPhoto(); }
  lightbox?.querySelectorAll('[data-lightbox-step]').forEach(button => button.addEventListener('click',() => changePhoto(Number(button.dataset.lightboxStep))));
  lightbox?.addEventListener('keydown',event => { if(event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); changePhoto(event.key === 'ArrowLeft' ? -1 : 1); } });
  document.querySelectorAll('[data-close-amenity]').forEach(button => button.addEventListener('click',() => (window.CelandineMotion ? window.CelandineMotion.closeDialog(button.closest('dialog')) : button.closest('dialog').close())));
  const bookingDialog = document.getElementById('amenityBookingDialog');
  document.querySelectorAll('[data-open-booking]').forEach(button => button.addEventListener('click',() => {
    const pool = button.dataset.openBooking === 'swimming-pool';
    document.querySelectorAll('[data-booking-pane]').forEach(pane => pane.hidden = pane.dataset.bookingPane !== button.dataset.openBooking);
    document.getElementById('amenityBookingTitle').textContent = pool ? 'Swimming Pool reservation' : 'Function Hall reservation';
    bookingDialog.showModal(); bookingDialog.scrollTop = 0; if(pool) refreshSlots();
  }));
  const money = amount => new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(amount);
  const time = value => new Date(`2000-01-01T${value}`).toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'});
  async function json(response) {
    let data;
    try { data = await response.json(); } catch { throw new Error('Your session may have expired. Refresh the page and try again.'); }
    if(!response.ok || !data.success) { const error = new Error(data.error || 'Unable to save reservation.'); error.code = data.code; error.title = data.title; throw error; }
    return data;
  }
  async function post(data) { return json(await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({...data,csrf_token:app.dataset.csrf})})); }
  const poolForm = document.querySelector('[data-pool-reservation]');
  const schedule = document.querySelector('[data-pool-schedule]');
  const availabilityForm = poolForm || schedule;
  let request, requestVersion = 0, slots = [], loadedDate = '', loadedDuration = 0, submitting = false;
  const slotContainer = availabilityForm?.querySelector('[data-time-slots]');
  const slotMessage = availabilityForm?.querySelector('[data-availability-message]');
  const submit = poolForm?.querySelector('[data-reservation-submit]');
  const feedback = poolForm?.querySelector('[data-booking-feedback]');
  function validSelection() {
    return poolForm && loadedDate === poolForm.elements.booking_date.value && loadedDuration === Number(poolForm.elements.duration_hours.value) && slots.some(slot => slot.start === poolForm.elements.booking_time.value && slot.status === 'available');
  }
  function summary() {
    if(!poolForm) return;
    const date = poolForm.elements.booking_date.value, duration = Number(poolForm.elements.duration_hours.value);
    const selected = slots.find(slot => slot.start === poolForm.elements.booking_time.value && slot.status === 'available');
    poolForm.querySelector('[data-summary-date]').textContent = date ? new Date(`${date}T12:00:00`).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}) : 'Choose a date';
    poolForm.querySelector('[data-summary-duration]').textContent = `${duration} hour${duration === 1 ? '' : 's'}`;
    poolForm.querySelector('[data-summary-fee]').textContent = money(duration * 300);
    poolForm.querySelector('[data-summary-time]').textContent = selected ? `${time(selected.start)} – ${time(selected.end)}` : 'Choose a time';
    submit.disabled = submitting || !validSelection();
    slotContainer.querySelectorAll('button').forEach(button => {
      const selected = button.dataset.start === poolForm.elements.booking_time.value;
      button.setAttribute('aria-pressed',String(selected));
      button.querySelector('span').textContent = selected ? 'Selected' : button.dataset.state === 'booked' ? 'Booked' : button.dataset.state === 'past' ? 'Past' : 'Available';
    });
    const hint = poolForm.querySelector('.amenity-submit-hint');
    if (hint) hint.hidden = !!validSelection();
  }
  async function refreshSlots() {
    if(!availabilityForm) return;
    request?.abort(); request = new AbortController(); const version = ++requestVersion;
    const date = availabilityForm.elements.booking_date.value, duration = Number(availabilityForm.elements.duration_hours.value);
    const previous = poolForm?.elements.booking_time.value;
    loadedDate = ''; loadedDuration = 0; if(submit) submit.disabled = true;
    if(!date || !availabilityForm.elements.booking_date.validity.valid) {
      slots = []; slotContainer.replaceChildren(); slotMessage.textContent = 'Select a valid date to see available starting times.';
      if(poolForm) { poolForm.elements.booking_time.value = ''; summary(); } return;
    }
    slotMessage.textContent = 'Loading schedule availability…'; slotContainer.setAttribute('aria-busy','true');
    slotContainer.querySelectorAll('button').forEach(button => button.disabled = true);
    try {
      const data = await json(await fetch(`${endpoint}?${new URLSearchParams({date,duration})}`,{signal:request.signal,headers:{Accept:'application/json'}}));
      if(version !== requestVersion) return;
      slots = data.slots; loadedDate = date; loadedDuration = duration;
      slotContainer.replaceChildren();
      slots.forEach(slot => {
        const button = document.createElement('button'); button.type = 'button'; button.dataset.start = slot.start; button.dataset.state = slot.status;
        button.disabled = slot.status !== 'available' || !poolForm;
        const strong = document.createElement('strong'), state = document.createElement('span'); strong.textContent = `${time(slot.start)} – ${time(slot.end)}`;
        state.textContent = slot.status === 'booked' ? 'Booked' : slot.status === 'past' ? 'Past' : 'Available';
        button.append(strong,state); slotContainer.append(button);
      });
      const available = slots.filter(slot => slot.status === 'available').length;
      slotMessage.textContent = data.fully_booked ? 'Fully Booked — The swimming pool is fully reserved for the selected date and duration. Please choose another date.' : available ? `${available} available starting time${available===1?'':'s'} · ${duration} hour${duration===1?'':'s'} · Asia/Manila` : 'No future starting times remain for this date and duration. Choose another date.';
      if(poolForm) {
        if(previous && !slots.some(slot => slot.start === previous && slot.status === 'available')) { poolForm.elements.booking_time.value = ''; feedback.textContent = 'Time Slot Already Booked — The selected time is no longer available for this date and duration. Choose another available time.'; }
        summary();
      }
    } catch(error) { if(error.name !== 'AbortError') { slots = []; loadedDate = ''; slotContainer.replaceChildren(); slotMessage.textContent = error.message; if(poolForm) summary(); } }
    finally { if(version === requestVersion) slotContainer.removeAttribute('aria-busy'); }
  }
  availabilityForm?.querySelector('[data-refresh-slots]')?.addEventListener('click',refreshSlots);
  availabilityForm?.addEventListener('change',event => {
    if(['booking_date','duration_hours'].includes(event.target.name)) { if(poolForm) { poolForm.elements.booking_time.value = ''; feedback.textContent = ''; } refreshSlots(); }
  });
  slotContainer?.addEventListener('click',event => {
    const button = event.target.closest('[data-start]');
    if(!poolForm || !button || button.disabled) return;
    poolForm.elements.booking_time.value = button.dataset.start; feedback.textContent = ''; summary();
  });
  poolForm?.addEventListener('submit',async event => {
    event.preventDefault(); if(submitting || !validSelection() || !poolForm.reportValidity()) return;
    submitting = true; submit.disabled = true; submit.textContent = 'Submitting…'; feedback.textContent = 'Submitting your reservation for approval…';
    try {
      const data = Object.fromEntries(new FormData(poolForm)); data.action = 'create';
      const response = await post(data); feedback.textContent = response.message;
      feedback.dataset.success = 'true'; poolForm.elements.booking_time.value = '';
      // Reload the current page so the saved request appears in reservation history.
      window.location.assign('book_amenity.php#yourBookings');
    } catch(error) {
      feedback.dataset.success = 'false'; feedback.textContent = `${error.title ? error.title+' — ' : ''}${error.message}`;
      if(error.code === 'schedule_conflict') { poolForm.elements.booking_time.value = ''; await refreshSlots(); }
    } finally { submitting = false; submit.textContent = 'Submit reservation'; summary(); }
  });
  document.querySelectorAll('[data-booking-decision]').forEach(form => form.addEventListener('submit',async event => {
    event.preventDefault(); const button = event.submitter; if(!button || !form.reportValidity()) return;
    const message = form.querySelector('[data-decision-feedback]'); const action = button.value;
    if(action === 'rejected' && !form.elements.reason.value.trim()) { message.textContent = 'Enter a reason before rejecting this request.'; form.elements.reason.focus(); return; }
    form.querySelectorAll('button').forEach(control => control.disabled = true); message.textContent = 'Saving decision…';
    try {
      const response = await post({action,booking_id:form.dataset.bookingDecision,reason:form.elements.reason.value,pmo_review:form.elements.pmo_review?.checked || false});
      message.textContent = response.message; window.location.reload();
    } catch(error) { message.textContent = `${error.title ? error.title+' — ' : ''}${error.message}`; if(error.code === 'schedule_conflict') refreshSlots(); }
    finally { form.querySelectorAll('button').forEach(control => control.disabled = false); }
  }));
  window.addEventListener('focus',() => { if(schedule || bookingDialog?.open) refreshSlots(); });
  document.addEventListener('visibilitychange',() => { if(!document.hidden && (schedule || bookingDialog?.open)) refreshSlots(); });
  setInterval(() => { if(!document.hidden && !submitting && (schedule || bookingDialog?.open)) refreshSlots(); },30000);
  if(poolForm) summary(); if(schedule) refreshSlots();
})();
