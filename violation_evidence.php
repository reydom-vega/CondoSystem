<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Forbidden');
}
$isAuthorizedStaff = isAdmin() && (isSecurity() || isSuperAdmin());
$isResident = !isAdmin() && (($_SESSION['role'] ?? '') === 'resident');
if (!$isAuthorizedStaff && !$isResident) {
    http_response_code(403);
    exit('Forbidden');
}
if ($isResident) {
    requireApproval();
}

$violationId = (int)($_GET['id'] ?? 0);
if ($violationId <= 0) {
    http_response_code(404);
    exit('Not found');
}

$connection = connectDb();
if (!ensureViolationsTable($connection)) {
    http_response_code(500);
    exit('Unable to load evidence');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($isAuthorizedStaff) {
    $stmt = $connection->prepare('SELECT evidence_path FROM violations WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $violationId);
} else {
    $stmt = $connection->prepare('SELECT evidence_path FROM violations WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $violationId, $userId);
}
$stmt->execute();
$violation = $stmt->get_result()->fetch_assoc();
$filename = $violation['evidence_path'] ?? '';
if (!preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $filename)) {
    http_response_code(404);
    exit('Not found');
}

$evidenceDirectory = realpath(__DIR__ . '/private_uploads/violation_evidence');
$filePath = $evidenceDirectory ? realpath($evidenceDirectory . DIRECTORY_SEPARATOR . $filename) : false;
if (!$evidenceDirectory || !$filePath || !str_starts_with($filePath, $evidenceDirectory . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit('Not found');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported evidence format');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
$download = isset($_GET['download']) && $_GET['download'] === '1';
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="violation-' . $violationId . '.' . pathinfo($filePath, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
