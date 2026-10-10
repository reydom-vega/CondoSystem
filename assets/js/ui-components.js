(() => {
  'use strict';
  if (window.CelandineUi) return;
  window.CelandineUi = {version:1};
  const body = document.body;
  const sidebar = document.getElementById('sidebar');
  const menu = document.getElementById('menuToggle');
  const overlay = document.getElementById('sidebarOverlay');
  const mobile = window.matchMedia('(max-width: 1023px)');
  const main = document.querySelector('.dashboard-main,main,.form-panel,.pending-card,.rejected-card');
  const controller = new AbortController();
  const on = (node,event,fn,options={}) => node?.addEventListener(event,fn,{...options,signal:controller.signal});
  const role = document.querySelector('meta[name=portal-role]')?.content;
  const storageKey = 'celandine-sidebar-collapsed';
  let restoreFocus;

  if (main && !document.querySelector('a.skip-link,a.portal-skip-link')) {
    if (!main.id) main.id = 'portalMainContent';
    const skip = document.createElement('a'); skip.className = 'portal-skip-link';
    skip.href = `#${main.id}`; skip.textContent = 'Skip to main content';
    body.prepend(skip);
    on(skip,'click',() => { if (!main.hasAttribute('tabindex')) main.tabIndex = -1; main.focus(); });
  }
  function storeCollapsed(collapsed) { try { localStorage.setItem(storageKey,collapsed ? '1' : '0'); } catch {} }
  function setCollapsed(collapsed) {
    body.classList.toggle('sidebar-collapsed',collapsed);
    if (menu && !mobile.matches) {
      menu.setAttribute('aria-expanded',String(!collapsed));
      menu.setAttribute('aria-label',collapsed ? 'Expand navigation' : 'Collapse navigation');
    }
  }
  function setDrawer(open,focus = true) {
    if (!sidebar || !mobile.matches) return;
    sidebar.classList.toggle('open',open); overlay?.classList.toggle('open',open);
    sidebar.inert = !open;
    body.classList.toggle('portal-drawer-open',open);
    if (menu) { menu.setAttribute('aria-expanded',String(open)); menu.setAttribute('aria-label',open ? 'Close navigation' : 'Open navigation'); }
    if (open) {
      restoreFocus = menu || document.activeElement;
      sidebar.querySelector('.sidebar-close')?.focus();
    } else if(focus) (restoreFocus || menu)?.focus();
    document.dispatchEvent(new CustomEvent('portal:drawer',{detail:{open,sidebar}}));
  }
  if (sidebar) {
    sidebar.setAttribute('aria-label','Main navigation');
    sidebar.querySelectorAll('.sidebar-link').forEach(link => {
      const label = link.textContent.trim();
      if (!link.querySelector('.sidebar-link-label')) {
        const span = document.createElement('span'); span.className = 'sidebar-link-label';
        [...link.childNodes].filter(node => node.nodeType === Node.TEXT_NODE).forEach(node => { span.append(node); });
        link.append(span);
      }
      link.title = label;
      link.setAttribute('aria-label',label);
    });
    const close = document.createElement('button'); close.type = 'button'; close.className = 'sidebar-close';
    close.setAttribute('aria-label','Close navigation'); close.textContent = '\u00d7'; sidebar.prepend(close);
    on(close,'click',() => setDrawer(false));
    menu?.setAttribute('aria-controls',sidebar.id);
    try { setCollapsed(localStorage.getItem(storageKey)==='1'); } catch { setCollapsed(false); }
    const syncScreen = () => {
      if (mobile.matches) setDrawer(false,false);
      else { sidebar.inert = false; sidebar.classList.remove('open'); overlay?.classList.remove('open'); body.classList.remove('portal-drawer-open'); setCollapsed(body.classList.contains('sidebar-collapsed')); }
    };
    syncScreen(); on(mobile,'change',syncScreen);
    on(menu,'click',() => {
      if (mobile.matches) setDrawer(!sidebar.classList.contains('open'));
      else { const collapsed = !body.classList.contains('sidebar-collapsed'); setCollapsed(collapsed); storeCollapsed(collapsed); document.dispatchEvent(new CustomEvent('portal:sidebar',{detail:{collapsed,sidebar}})); }
    });
    on(overlay,'click',() => setDrawer(false));
    on(sidebar,'click',event => { if(event.target.closest('a[href]') && mobile.matches) setDrawer(false,false); });
    on(document,'keydown',event => {
      if (!mobile.matches || !sidebar.classList.contains('open')) return;
      if (event.key==='Escape') { event.preventDefault(); setDrawer(false); }
      if (event.key==='Tab') {
        const focusable = [...sidebar.querySelectorAll('a[href],button:not(:disabled)')].filter(el => el.getClientRects().length);
        const first = focusable[0], last = focusable.at(-1);
        if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement===last) { event.preventDefault(); first?.focus(); }
      }
    });
  }
  const header = document.querySelector('.dash-header');
  const heading = header?.querySelector('h1');
  const dashboard = sidebar?.querySelector('.sidebar-brand');
  if (heading && dashboard && !heading.textContent.toLowerCase().includes('dashboard')) {
    const breadcrumb = document.createElement('nav'); breadcrumb.className = 'portal-breadcrumb'; breadcrumb.setAttribute('aria-label','Breadcrumb');
    const home = document.createElement('a'); home.href = dashboard.href; home.textContent = 'Dashboard';
    const separator = document.createElement('span'); separator.textContent = '/'; separator.setAttribute('aria-hidden','true');
    const current = document.createElement('span'); current.textContent = heading.textContent; current.setAttribute('aria-current','page');
    breadcrumb.append(home,separator,current); header.after(breadcrumb);
  }
  if (role && header?.querySelector('.dash-header-right')) {
    const badge = document.createElement('span'); badge.className = 'portal-role-badge'; badge.textContent = role;
    header.querySelector('.dash-header-right').prepend(badge);
  }
  document.querySelectorAll('.alert').forEach(alert => {
    if (!alert.hasAttribute('role')) alert.setAttribute('role',alert.classList.contains('error') ? 'alert' : 'status');
  });
  document.querySelectorAll('form').forEach(form => {
    form.querySelectorAll('input[required]:not([type=hidden]):not([type=radio]):not([type=checkbox]),select[required],textarea[required]').forEach(field => {
      const label = field.labels?.[0];
      if(label && !label.querySelector('.portal-required')) {
        const marker = document.createElement('span'); marker.className = 'portal-required'; marker.textContent = '*'; marker.setAttribute('aria-hidden','true');
        const text = [...label.childNodes].find(node => node.nodeType===Node.TEXT_NODE && node.textContent.trim());
        if(label.contains(field) && text) {
          const caption = document.createElement('span'); caption.className = 'portal-field-caption';
          text.replaceWith(caption); caption.append(text,marker);
        } else if(!label.contains(field)) label.append(marker);
      }
    });
    const action = form.querySelector('input[name=action],input[name=form_action]')?.value || '';
    if (!form.dataset.confirm && !form.hasAttribute('onsubmit') && /^(delete|delete_|cancel|cancel_|unassign|revoke)/.test(action)) {
      form.dataset.confirm = /cancel/.test(action) ? 'Cancel this request? Review the details before continuing.' : 'Confirm this action? This will update the selected record.';
      form.dataset.confirmAction = /cancel/.test(action) ? 'Cancel request' : 'Confirm action';
    }
  });
  function prepareTables(root = document) {
    root.querySelectorAll('.dashboard-main table').forEach(table => {
      if (table.dataset.portalTable) return;
      table.dataset.portalTable = 'true';
      let wrap = table.parentElement;
      if (!/wrap|scroll/.test(wrap.className)) {
        wrap = document.createElement('div'); wrap.className = 'portal-table-scroll';
        table.before(wrap); wrap.append(table);
      }
      wrap.tabIndex = 0; wrap.setAttribute('role','region');
      const name = table.closest('section')?.querySelector('h2,h3')?.textContent.trim() || heading?.textContent.trim() || 'Records';
      wrap.setAttribute('aria-label',`${name} table. Scroll horizontally for more columns.`);
      table.querySelectorAll('thead th:not([scope])').forEach(th => th.scope = 'col');
    });
  }
  prepareTables();
  // Older custom overlays keep their existing actions and gain keyboard focus handling.
  const overlayFocus = new Map();
  const customOverlays = [...document.querySelectorAll('.modal-overlay,.staff-confirm-overlay,.vacancy-confirm-overlay,.message-image-viewer')];
  const isOverlayOpen = element => element.classList.contains('open') && !element.hidden;
  const dismissControl = element => element.querySelector('button[id*=Cancel],.modal-close,[data-action=close],button[data-close-modal]');
  const modalObserver = new MutationObserver(records => {
    for(const record of records) {
      const element = record.target;
      if(isOverlayOpen(element) && !overlayFocus.has(element)) {
        overlayFocus.set(element,document.activeElement);
        const dialog = element.querySelector('[role=dialog]') || element;
        dialog.setAttribute('role','dialog'); dialog.setAttribute('aria-modal','true');
        if(!dialog.hasAttribute('aria-label') && !dialog.hasAttribute('aria-labelledby')) dialog.setAttribute('aria-label',dialog.querySelector('h2,h3')?.textContent || 'Details');
        (dismissControl(element) || element.querySelector('button,input,a[href]'))?.focus();
      } else if(!isOverlayOpen(element) && overlayFocus.has(element)) {
        const previous = overlayFocus.get(element); overlayFocus.delete(element); previous?.focus();
      }
    }
  });
  customOverlays.forEach(element => modalObserver.observe(element,{attributes:true,attributeFilter:['class','hidden']}));
  on(document,'keydown',event => {
    const element = customOverlays.find(isOverlayOpen);
    if(!element) return;
    if(event.key==='Escape') dismissControl(element)?.click();
    if(event.key==='Tab') {
      const fields = [...element.querySelectorAll('button:not(:disabled),input:not(:disabled),select,textarea,a[href],[tabindex="0"]')].filter(item => item.getClientRects().length);
      if(event.shiftKey && document.activeElement===fields[0]) { event.preventDefault(); fields.at(-1)?.focus(); }
      if(!event.shiftKey && document.activeElement===fields.at(-1)) { event.preventDefault(); fields[0]?.focus(); }
    }
  });
  // Profile menus keep their existing click handlers; add consistent keyboard dismissal.
  on(document,'keydown',event => {
    if(event.key!=='Escape') return;
    document.querySelectorAll('.profile-menu.open,.notification-menu.open').forEach(panel => {
      panel.classList.remove('open'); const toggle = panel.querySelector('[aria-expanded]'); toggle?.setAttribute('aria-expanded','false'); toggle?.focus();
    });
  });
  window.CelandineUi.prepareTables = prepareTables;
  // Listeners remain attached in the back/forward cache. They are not duplicated on pageshow.
  on(window,'pagehide',event => { if(!event.persisted) { controller.abort(); modalObserver.disconnect(); } });
})();
