<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Forbidden');
}

$management = canAccess('maintenance.work');
$resident = canAccess('resident.maintenance.request');
if (!$management && !$resident) {
    http_response_code(403);
    exit('Forbidden');
}
if ($resident) {
    requireApproval();
}

$requestId = (int)($_GET['id'] ?? 0);
$field = (string)($_GET['field'] ?? '');
$allowedFields = ['evidence_path', 'before_photo_path', 'after_photo_path'];
if ($requestId <= 0 || !in_array($field, $allowedFields, true)) {
    http_response_code(404);
    exit('Not found');
}

$connection = connectDb();
if (!ensureMaintenanceTable($connection)) {
    http_response_code(500);
    exit('Evidence storage unavailable');
}
$userId = (int)($_SESSION['user_id'] ?? 0);
$sql = 'SELECT ' . $field . ' AS image_path FROM maintenance_requests WHERE id = ?';
if ($resident) {
    $sql .= ' AND user_id = ?';
    $query = $connection->prepare($sql);
    $query->bind_param('ii', $requestId, $userId);
} else {
    $query = $connection->prepare($sql);
    $query->bind_param('i', $requestId);
}
$query->execute();
$row = $query->get_result()->fetch_assoc();
$query->close();
$connection->close();

$filePath = maintenanceImagePath($row['image_path'] ?? null);
if (!$filePath) {
    http_response_code(404);
    exit('Not found');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported image format');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
