<?php
/**
 * API endpoint for fetching dashboard updates (admin view)
 * Returns JSON of key metrics and status updates
 */

require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

requireCapability('management.dashboard', true);

$connection = connectDb();

try {
    // Get unread resident messages count
    $unreadMessages = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM messages WHERE sender_role = 'resident' AND is_read = 0");
    if ($result) {
        $row = $result->fetch_assoc();
        $unreadMessages = $row['count'] ?? 0;
    }

    // Get pending maintenance requests
    ensureMaintenanceTable($connection);
    $pendingMaintenance = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE status IN ('pending', 'approved', 'in_progress', 'reopened')");
    if ($result) {
        $row = $result->fetch_assoc();
        $pendingMaintenance = $row['count'] ?? 0;
    }

    // Get pending booking requests
    ensureBookingsTable($connection);
    $pendingBookings = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'");
    if ($result) {
        $row = $result->fetch_assoc();
        $pendingBookings = $row['count'] ?? 0;
    }

    // Get total residents
    $totalResidents = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM users WHERE role = 'resident'");
    if ($result) {
        $row = $result->fetch_assoc();
        $totalResidents = $row['count'] ?? 0;
    }

    // Get violations count
    $violationsCount = 0;
    $result = $connection->query("SELECT COUNT(*) as count FROM violations WHERE status = 'unresolved'");
    if ($result) {
        $row = $result->fetch_assoc();
        $violationsCount = $row['count'] ?? 0;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'unread_messages' => (int)$unreadMessages,
        'pending_maintenance' => (int)$pendingMaintenance,
        'pending_bookings' => (int)$pendingBookings,
        'total_residents' => (int)$totalResidents,
        'unresolved_violations' => (int)$violationsCount,
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

$connection->close();
