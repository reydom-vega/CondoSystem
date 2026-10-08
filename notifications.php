<?php
$scriptPath = '../js/profile-menu.js';
echo '<script src="' . $scriptPath . '" defer></script>';
echo '<script src="../js/notification-menu.js" defer></script>';
$notifications = getNotifications();
$notificationCount = getUnreadNotificationCount($notifications);
$notificationsUnread = $notificationCount > 0;
?>
<div class="notification-menu" id="notificationMenu" data-csrf-token="<?php echo htmlspecialchars(workflowCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <button class="btn-icon-nav" id="notificationToggle" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
        <span class="notification-bell-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span><?php if ($notificationCount > 0): ?><span class="notification-count-badge" aria-label="<?php echo $notificationCount; ?> notifications" aria-live="polite" style="position:absolute;top:-7px;right:-7px;z-index:2;display:flex;min-width:20px;height:20px;align-items:center;justify-content:center;box-sizing:border-box;padding:0 5px;border:2px solid #1a1e2b;border-radius:999px;background:#e53945;color:#fff;font-size:10px;font-weight:800;line-height:1;white-space:nowrap;pointer-events:none"><?php echo $notificationCount > 99 ? '99+' : $notificationCount; ?></span><?php endif; ?>
    </button>
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <strong>Notifications</strong>
            <?php if ($notificationsUnread): ?><button type="button" class="notification-mark-read" data-notification-dismiss-all>Mark all as read</button><?php endif; ?>
        </div>
        <?php if (empty($notifications)): ?>
            <p class="notification-empty">You're all caught up.</p>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <button type="button" class="notification-item <?php echo (int)($notification['unread_count'] ?? 0) > 0 ? 'unread' : ''; ?>" data-notification-key="<?php echo htmlspecialchars($notification['key'], ENT_QUOTES, 'UTF-8'); ?>" data-notification-link="<?php echo htmlspecialchars($notification['link'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo systemIcon($notification['icon'], 'notification-icon'); ?>
                    <span class="notification-copy"><strong><?php echo htmlspecialchars($notification['title']); ?></strong><span><?php echo htmlspecialchars($notification['message']); ?></span><small><?php echo htmlspecialchars($notification['time']); ?></small></span>
                </button>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
