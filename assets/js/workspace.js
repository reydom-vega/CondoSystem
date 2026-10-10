(() => {
  'use strict';
  if (!document.querySelector('meta[name="portal-workspace"]')) return;
  const main = document.querySelector('.dashboard-main');
  if (!main) return;
  let fieldNumber = 0;
  main.querySelectorAll('form label').forEach(label => {
    if (label.matches('.message-attachment-button,.payment-method-item') || label.closest('.message-search,.reply-form')) return;
    if (label.matches('.notify-check')) label.classList.add('checkbox');
    else if (!label.matches('.checkbox') && !label.querySelector('[type=checkbox],[type=radio]')) label.classList.add('field-label');
    if (!label.htmlFor && !label.querySelector('input,select,textarea')) {
      const field = label.parentElement;
      const control = field.matches('.field,.form-group,.violation-field') ? field.querySelector('input:not([type=hidden]),select,textarea') : null;
      if (control) {
        if (!control.id) control.id = `workspace-field-${++fieldNumber}`;
        label.htmlFor = control.id;
      }
    }
  });

  // Keep native controls and their listeners; associate existing filter captions.
  main.querySelectorAll('.unit-filters,.staff-search-row,.bill-history-filters').forEach(form => {
    [...form.children].filter(node => node.tagName === 'LABEL' && node.htmlFor).forEach(label => {
      const control = document.getElementById(label.htmlFor);
      if (!control || control.parentElement !== form) return;
      const field = document.createElement('div'); field.className = 'workspace-filter-field';
      label.before(field); field.append(label, control);
    });
  });

  function prepareRecords() {
    main.querySelectorAll('table:not([data-workspace-ready])').forEach(table => {
      table.dataset.workspaceReady = 'true';
      if (table.matches('.soa-items,.history-items,.violation-rate-table') || !table.tHead || table.tHead.rows.length !== 1) return;
      const headings = [...table.tHead.rows[0].cells];
      if (headings.some(cell => cell.colSpan !== 1)) return;
      table.classList.add('workspace-records');
      table.setAttribute('role', 'table');
      table.querySelectorAll('thead,tbody').forEach(group => group.setAttribute('role', 'rowgroup'));
      table.querySelectorAll('tr').forEach(row => row.setAttribute('role', 'row'));
      headings.forEach(cell => cell.setAttribute('role', 'columnheader'));
      [...table.tBodies].forEach(group => [...group.rows].forEach(row => [...row.cells].forEach((cell, index) => {
        cell.setAttribute('role', 'cell');
        if (cell.colSpan === 1 && headings[index]) cell.dataset.columnLabel = headings[index].textContent.trim();
      })));
      const wrap = table.parentElement;
      if (wrap.getAttribute('role') === 'region') {
        const name = table.closest('section')?.querySelector('h2,h3')?.textContent.trim() || document.querySelector('.dash-title')?.textContent.trim() || 'Records';
        wrap.setAttribute('aria-label', `${name} records`);
      }
    });
  }

  function prepareChargeRows() {
    main.querySelectorAll('.item-row,.bulk-charge-row').forEach(row => {
      row.classList.add('workspace-charge-row');
      row.querySelectorAll('input:not([type=hidden]),select').forEach(control => {
        // Repeated rows can be cloned by existing handlers. Give each a fresh ID.
        if (control.dataset.workspaceLabelled && control.closest('.workspace-charge-field')) return;
        const field = document.createElement('div'); field.className = 'workspace-charge-field';
        const label = document.createElement('label'); label.className = 'field-label';
        const id = `workspace-charge-${++fieldNumber}`;
        control.id = id; control.dataset.workspaceLabelled = 'true'; label.htmlFor = id;
        label.textContent = /amount/.test(control.name) ? 'Amount (PHP)' : 'Charge category';
        control.before(field); field.append(label,control);
      });
    });
    // Cloned controls retain IDs; repair duplicate associations without changing names.
    const seen = new Set();
    main.querySelectorAll('.workspace-charge-field').forEach(field => {
      const control = field.querySelector('input,select'), label = field.querySelector('label');
      if (!control || !label) return;
      if (seen.has(control.id)) control.id = `workspace-charge-${++fieldNumber}`;
      label.htmlFor = control.id; seen.add(control.id);
    });
  }
  prepareRecords(); prepareChargeRows();
  // Existing bill editors insert rows dynamically. Presentation follows those rows.
  const chargeContainers = main.querySelectorAll('#itemRows,#bulkChargeRows');
  const observer = new MutationObserver(() => prepareChargeRows());
  chargeContainers.forEach(container => observer.observe(container,{childList:true}));
  window.addEventListener('pagehide', event => { if (!event.persisted) observer.disconnect(); });
})();
