<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn() || (!isSuperAdmin() && !isSecurity())) {
    http_response_code(403);
    exit('Forbidden');
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(404);
    exit('Proof not found.');
}

$connection = connectDb();
if (!ensureParkingStickerOrdersTable($connection)) {
    http_response_code(500);
    exit('Proof storage is unavailable.');
}

$stmt = $connection->prepare('SELECT proof_file FROM parking_sticker_orders WHERE id = ? AND claim_status IN (\'pending\', \'issued\') AND proof_file IS NOT NULL LIMIT 1');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order || !preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/', $order['proof_file'])) {
    http_response_code(404);
    exit('Proof not found.');
}

$proofDirectory = realpath(__DIR__ . '/private_uploads/sticker_proofs');
$proofPath = $proofDirectory ? realpath($proofDirectory . DIRECTORY_SEPARATOR . $order['proof_file']) : false;
if (!$proofDirectory || !$proofPath || !str_starts_with($proofPath, $proofDirectory . DIRECTORY_SEPARATOR) || !is_file($proofPath)) {
    http_response_code(404);
    exit('Proof not found.');
}

$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
$extension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . filesize($proofPath));
header('Content-Disposition: inline; filename="parking-sticker-proof-' . $orderId . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
readfile($proofPath);
