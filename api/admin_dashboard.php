<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/management_dashboard.php';
require_once __DIR__.'/../includes/management_dashboard_content.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
requireCapability('management.dashboard', true);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); echo json_encode(['error'=>'Use GET.']); exit; }
try {
    $data = managementDashboardData(connectDb(), (int)$_SESSION['user_id']);
    ob_start(); renderManagementDashboardContent($data); $html = ob_get_clean();
    $pendingBookings = 0;
    foreach ($data['queues'] as $queue) if ($queue['key'] === 'bookings') $pendingBookings = $queue['count'];
    // Keep the original numeric keys for existing consumers, with corrected definitions.
    echo json_encode(['success'=>true,'data'=>$data,'html'=>$html,
        'unread_messages'=>$data['messages']['messages'],'pending_maintenance'=>$data['maintenance']['active'],
        'pending_bookings'=>$pendingBookings,'total_residents'=>$data['residents']['total'],
        'unresolved_violations'=>$data['unresolved_violations'],'timestamp'=>time()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $error) {
    http_response_code(403); echo json_encode(['error'=>'Access denied.']);
} catch (Throwable $error) {
    http_response_code(503); error_log('Management dashboard refresh: '.get_class($error));
    echo json_encode(['error'=>'Dashboard data is temporarily unavailable. Please try again.']);
}
