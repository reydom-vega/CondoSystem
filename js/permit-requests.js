(() => {
  const form = document.getElementById('permitRequestForm');
  if (form) {
    const category = document.getElementById('permitCategory');
    const property = document.getElementById('propertyFields');
    const legacy = document.getElementById('legacyPermitFields');
    const purpose = document.getElementById('gatePurpose');
    const description = document.getElementById('gateDescription');
    const items = document.getElementById('permitItems');
    const add = document.getElementById('addPermitItem');
    let nextIndex = Math.max(0, ...Array.from(items.querySelectorAll('[name]'), input => Number(input.name.match(/items\[(\d+)\]/)?.[1] || 0))) + 1;
    const syncPurpose = () => {
      description.required = purpose.value === 'Other';
      const hint = document.getElementById('gateDescriptionHint');
      if (hint) hint.textContent = description.required ? 'Required for the selected purpose.' : 'Optional; add details to help management review your request.';
    };
    const syncItems = () => {
      const rows = items.querySelectorAll('.permit-item');
      rows.forEach((row, index) => {
        const number = row.querySelector('[data-permit-item-number]');
        if (number) number.textContent = index + 1;
        row.querySelector('.permit-remove').disabled = rows.length === 1;
        row.querySelector('.permit-remove').setAttribute('aria-label', `Remove item ${index + 1}`);
      });
      add.disabled = rows.length >= 50;
      const count = document.getElementById('permitItemCount');
      if (count) count.textContent = `${rows.length} / 50 items`;
    };
    const syncCategory = () => {
      const gate = category.value === 'Property Gate Pass';
      property.hidden = property.disabled = !gate;
      legacy.hidden = legacy.disabled = gate;
      form.querySelector('[name="action"]').value = gate ? 'gate_save' : 'create';
      syncPurpose();
    };
    add.addEventListener('click', () => {
      if (items.children.length >= 50) return;
      const row = items.querySelector('.permit-item').cloneNode(true);
      row.querySelectorAll('input, select').forEach(input => {
        input.name = input.name.replace(/items\[\d+\]/, `items[${nextIndex}]`);
        if (input.tagName === 'SELECT') input.selectedIndex = 0;
        else input.value = input.type === 'number' ? '1' : '';
      });
      nextIndex++;
      items.append(row); syncItems(); row.querySelector('input').focus();
    });
    items.addEventListener('click', event => {
      const remove = event.target.closest('.permit-remove');
      if (remove && items.children.length > 1) {
        const row = remove.closest('.permit-item');
        const next = row.nextElementSibling || row.previousElementSibling;
        row.remove(); syncItems(); next?.querySelector('input')?.focus();
      }
    });
    purpose.addEventListener('change', syncPurpose);
    category.addEventListener('change', syncCategory);
    form.addEventListener('submit', event => {
      if (!property.disabled) {
        const start = form.elements.start_time.value, end = form.elements.end_time.value;
        form.elements.end_time.setCustomValidity(start >= end ? 'End time must be after start time.' : '');
        if (!form.reportValidity()) event.preventDefault();
      }
    });
    form.elements.end_time.addEventListener('input', () => form.elements.end_time.setCustomValidity(''));
    form.elements.start_time.addEventListener('input', () => form.elements.end_time.setCustomValidity(''));
    syncCategory(); syncItems();
  }
  const search = document.getElementById('permitSearch');
  const status = document.getElementById('permitStatusFilter');
  const filter = () => {
    let visible = 0;
    document.querySelectorAll('[data-permit-status]').forEach(row => {
      row.hidden = (status.value && row.dataset.permitStatus !== status.value) || !row.textContent.toLowerCase().includes(search.value.toLowerCase().trim());
      if (!row.hidden) visible++;
    });
    const empty = document.getElementById('permitNoMatches');
    if (empty) empty.hidden = visible > 0;
  };
  search?.addEventListener('input', filter); status?.addEventListener('change', filter);
  document.querySelectorAll('form').forEach(current => current.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (current.dataset.confirm && !window.CelandineConfirmReady && !window.confirm(current.dataset.confirm)) { event.preventDefault(); return; }
    const submitter = event.submitter;
    // Keep the clicked button enabled until its name/value has been submitted.
    if (submitter) { submitter.textContent = 'Saving…'; current.setAttribute('aria-busy', 'true'); }
  }));
})();
