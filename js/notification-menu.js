(function () {
    'use strict';

    const endpoint = '../api/notifications.php';
    let refreshInProgress = false;

    function setBadge(count) {
        const toggle = document.getElementById('notificationToggle');
        if (!toggle) return;

        let badge = toggle.querySelector('.notification-count-badge');
        toggle.setAttribute('aria-label', count > 0 ? `Notifications (${count})` : 'Notifications');
        if (count <= 0) {
            badge?.remove();
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'notification-count-badge';
            badge.style.cssText = 'position:absolute;top:-7px;right:-7px;z-index:2;display:flex;min-width:20px;height:20px;align-items:center;justify-content:center;box-sizing:border-box;padding:0 5px;border:2px solid #1a1e2b;border-radius:999px;background:#e53945;color:#fff;font-size:10px;font-weight:800;line-height:1;white-space:nowrap;pointer-events:none';
            toggle.append(badge);
        }

        badge.textContent = count > 99 ? '99+' : String(count);
        badge.setAttribute('aria-label', `${count} notifications`);
    }

    function renderNotifications(feed) {
        const menu = document.getElementById('notificationMenu');
        const dropdown = document.getElementById('notificationDropdown');
        if (!menu || !dropdown || !feed || !Array.isArray(feed.notifications)) return;

        setBadge(Number(feed.count) || 0);
        const header = dropdown.querySelector('.notification-header');
        const dismissAll = header?.querySelector('[data-notification-dismiss-all]');
        if (dismissAll) dismissAll.remove();
        if (Number(feed.count) > 0 && header) {
            const markAll = document.createElement('button');
            markAll.type = 'button';
            markAll.className = 'notification-mark-read';
            markAll.dataset.notificationDismissAll = '';
            markAll.textContent = 'Mark all as read';
            header.append(markAll);
        }

        dropdown.querySelectorAll('.notification-item, .notification-empty').forEach((item) => item.remove());
        if (feed.notifications.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'notification-empty';
            empty.textContent = "You're all caught up.";
            dropdown.append(empty);
            return;
        }

        for (const notification of feed.notifications) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = `notification-item${Number(notification.unread_count) > 0 ? ' unread' : ''}`;
            item.dataset.notificationKey = notification.key;
            item.dataset.notificationLink = notification.link;

            const icon = document.createElement('span');
            icon.className = 'notification-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = notification.icon_html;

            const copy = document.createElement('span');
            copy.className = 'notification-copy';
            const title = document.createElement('strong');
            title.textContent = notification.title;
            const message = document.createElement('span');
            message.textContent = notification.message;
            const time = document.createElement('small');
            time.textContent = notification.time;
            copy.append(title, message, time);
            item.append(icon, copy);

            item.addEventListener('click', () => {
                const destinationValue = item.dataset.notificationLink;
                const destination = destinationValue
                    ? new URL(destinationValue, window.location.href).href
                    : null;

                if (destination) window.location.assign(destination);
                postAction({ action: 'dismiss', key: item.dataset.notificationKey })
                    .then((feed) => renderNotifications(feed))
                    .catch(() => {});
            });

            dropdown.append(item);
        }
    }

    async function postAction(payload) {
        const form = new URLSearchParams(payload);
        form.set('csrf_token', document.getElementById('notificationMenu')?.dataset.csrfToken || '');
        const response = await fetch(endpoint, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
            body: form.toString(),
        });
        if (!response.ok) throw new Error('Notification update failed.');
        return response.json();
    }

    async function refreshNotifications() {
        if (refreshInProgress || document.visibilityState === 'hidden') return;
        refreshInProgress = true;
        try {
            const response = await fetch(endpoint, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (response.ok) renderNotifications(await response.json());
        } catch (error) {
            // Keep the last rendered notification state when the network is unavailable.
        } finally {
            refreshInProgress = false;
        }
    }

    document.addEventListener('click', function (event) {
        const notificationMenu = document.getElementById('notificationMenu');
        const notificationToggle = document.getElementById('notificationToggle');
        if (!notificationMenu || !notificationToggle) return;

        const markAll = event.target.closest('[data-notification-dismiss-all]');
        if (markAll && notificationMenu.contains(markAll)) {
            event.preventDefault();
            event.stopPropagation();
            postAction({ action: 'dismiss_all' }).then((feed) => renderNotifications(feed)).catch(() => {});
            return;
        }

        if (notificationToggle.contains(event.target)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const isOpen = notificationMenu.classList.toggle('open');
            notificationToggle.setAttribute('aria-expanded', String(isOpen));
        } else if (!notificationMenu.contains(event.target)) {
            notificationMenu.classList.remove('open');
            notificationToggle.setAttribute('aria-expanded', 'false');
        }
    }, true);

    refreshNotifications();
    window.setInterval(refreshNotifications, 15000);
    document.addEventListener('visibilitychange', refreshNotifications);
})();
