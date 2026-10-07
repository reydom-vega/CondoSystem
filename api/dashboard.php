<?php
/**
 * API endpoint for fetching dashboard updates (resident view)
 * Returns JSON of key metrics, notifications, and status updates
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$connection = connectDb();
$userId = (int)$_SESSION['user_id'];

try {
    // Get unread message count
    $messageCount = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM messages WHERE user_id = $userId AND sender_role = 'admin' AND is_read = 0");
    if ($result) {
        $row = $result->fetch_assoc();
        $messageCount = $row['count'] ?? 0;
    }

    // Get active maintenance requests
    ensureMaintenanceTable($connection);
    $maintenanceCount = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE user_id = $userId AND status IN ('pending', 'approved', 'in_progress', 'reopened')");
    if ($result) {
        $row = $result->fetch_assoc();
        $maintenanceCount = $row['count'] ?? 0;
    }

    // Get pending announcements
    $announcements = getAnnouncements(5, true);
    $announcementCount = count($announcements);

    // Get due payments
    ensurePaymentsTable($connection);
    $duePayments = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM payments WHERE user_id = $userId AND status IN ('pending', 'overdue')");
    if ($result) {
        $row = $result->fetch_assoc();
        $duePayments = $row['count'] ?? 0;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'unread_messages' => (int)$messageCount,
        'active_maintenance' => (int)$maintenanceCount,
        'announcements' => (int)$announcementCount,
        'due_payments' => (int)$duePayments,
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

$connection->close();
