<?php
/**
 * API endpoint for fetching dashboard updates (resident view)
 * Returns JSON of key metrics, notifications, and status updates
 */

require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

requireCapability('resident.portal', true);
if (!isApproved()) {
    http_response_code(403);
    echo json_encode(['error' => 'Resident approval required']);
    exit;
}

$connection = connectDb();
$userId = (int)$_SESSION['user_id'];

try {
    // Get unread message count
    $messageCount = 0;
    $result = residentHasPermission('resident.messages.use') ? $connection->query("SELECT COUNT(*) as count FROM messages WHERE user_id = $userId AND sender_role = 'admin' AND is_read = 0") : false;
    if ($result) {
        $row = $result->fetch_assoc();
        $messageCount = $row['count'] ?? 0;
    }

    // Get active maintenance requests
    ensureMaintenanceTable($connection);
    $maintenanceCount = 0;
    $result = residentHasPermission('resident.maintenance.request') ? $connection->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE user_id = $userId AND status IN ('pending', 'approved', 'in_progress', 'reopened')") : false;
    if ($result) {
        $row = $result->fetch_assoc();
        $maintenanceCount = $row['count'] ?? 0;
    }

    // Get pending announcements
    $announcements = residentHasPermission('resident.announcements.view') ? getAnnouncements(5, true) : [];
    $announcementCount = count($announcements);

    // Get due payments
    ensurePaymentsTable($connection);
    $duePayments = getResidentBillingSummary($connection,$userId)['count'];

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
