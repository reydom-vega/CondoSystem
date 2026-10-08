<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Forbidden');
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(404);
    exit('Proof not found.');
}

$connection = connectDb();
if (($_SESSION['role'] ?? '')==='resident') {
    $actor=residentContext($connection,(int)$_SESSION['user_id']);
    if (!$actor || !$actor['approved'] || !in_array($actor['account_kind'],['owner','tenant'],true)) { http_response_code(403); exit('Forbidden'); }
}
ensureBillingTables($connection);
if (!ensureParkingStickerOrdersTable($connection)) {
    http_response_code(500);
    exit('Proof storage is unavailable.');
}

$stmt = $connection->prepare('SELECT user_id, bill_payment_id, proof_file FROM parking_sticker_orders WHERE id = ? AND claim_status IN (\'pending\', \'issued\') AND proof_file IS NOT NULL LIMIT 1');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order || (!canReviewPermits() && !canManageBilling() && !residentCanPayBill($connection,(int)$_SESSION['user_id'],(int)$order['user_id'],(int)$order['bill_payment_id']))
    || !preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D', $order['proof_file'])) {
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
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($proofPath);
if ($mime !== ($mimeTypes[$extension] ?? null)) { http_response_code(404); exit('Proof not found.'); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($proofPath));
header('Content-Disposition: inline; filename="parking-sticker-proof-' . $orderId . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($proofPath);
