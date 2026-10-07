<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function sendNotificationFeed(int $statusCode = 200): never {
    http_response_code($statusCode);
    $notifications = getNotifications();
    foreach ($notifications as &$notification) {
        $notification['icon_html'] = systemIcon((string)$notification['icon'], 'notification-icon');
        unset($notification['icon']);
    }
    unset($notification);

    echo json_encode([
        'notifications' => $notifications,
        'count' => getUnreadNotificationCount($notifications),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    sendNotificationFeed(401);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $notifications = getNotifications();

    if ($action === 'dismiss_all') {
        $dismissed = $_SESSION['dismissed_notification_keys'] ?? [];
        $dismissed = array_merge($dismissed, array_column($notifications, 'key'));
        $_SESSION['dismissed_notification_keys'] = array_values(array_unique($dismissed));
    } elseif ($action === 'dismiss' && isset($_POST['key'])) {
        $requestedKey = (string)$_POST['key'];
        foreach ($notifications as $notification) {
            if (hash_equals((string)$notification['key'], $requestedKey)) {
                $dismissed = $_SESSION['dismissed_notification_keys'] ?? [];
                $dismissed[] = $requestedKey;
                $_SESSION['dismissed_notification_keys'] = array_values(array_unique($dismissed));
                break;
            }
        }
    } else {
        sendNotificationFeed(400);
    }
}

sendNotificationFeed();
