<?php
require_once 'config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$messageId = (int)($_GET['id'] ?? 0);
if ($messageId <= 0) {
    http_response_code(404);
    exit('Attachment not found');
}

$connection = connectDb();
if (!ensureMessagesTable($connection)) {
    http_response_code(500);
    exit('Attachment storage unavailable');
}

if (isAdmin()) {
    $query = $connection->prepare('SELECT user_id, attachment_path, attachment_name, attachment_mime FROM messages WHERE id = ?');
    $query->bind_param('i', $messageId);
} else {
    $userId = (int)$_SESSION['user_id'];
    $query = $connection->prepare('SELECT user_id, attachment_path, attachment_name, attachment_mime FROM messages WHERE id = ? AND user_id = ?');
    $query->bind_param('ii', $messageId, $userId);
}
$query->execute();
$attachment = $query->get_result()->fetch_assoc();
$query->close();
$connection->close();

if (!$attachment || !$attachment['attachment_path']) {
    http_response_code(404);
    exit('Attachment not found');
}

$storedName = (string)$attachment['attachment_path'];
if (!preg_match('/\A[a-f0-9]{48}\.(jpg|png|gif|webp|pdf|txt|csv|doc|docx|xls|xlsx)\z/', $storedName)) {
    http_response_code(404);
    exit('Attachment not found');
}

$filePath = __DIR__ . '/private_uploads/message_attachments/' . $storedName;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Attachment not found');
}

$mimeType = (string)$attachment['attachment_mime'];
$inline = isset($_GET['inline']) && $_GET['inline'] === '1' && strpos($mimeType, 'image/') === 0;
$safeName = str_replace(["\r", "\n", '"'], ['', '', '_'], (string)$attachment['attachment_name']);
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($safeName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);