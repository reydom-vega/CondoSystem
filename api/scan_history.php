<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isSecurity()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$connection = connectDb();
if (schemaMutationAllowed()) $connection->query("CREATE TABLE IF NOT EXISTS qr_scan_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    scanned_content TEXT NOT NULL,
    content_type VARCHAR(20) NOT NULL DEFAULT 'text',
    scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    requireWorkflowCsrf(is_array($payload) && is_string($payload['csrf_token'] ?? null) ? $payload['csrf_token'] : '');
    $content = is_string($payload['content'] ?? null) ? trim($payload['content']) : '';

    if ($content === '' || strlen($content) > 4096) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'QR content is empty or too long.']);
        exit;
    }

    $contentType = filter_var($content, FILTER_VALIDATE_URL) ? 'link' : 'text';
    $userId = (int)$_SESSION['user_id'];
    $username = (string)($_SESSION['username'] ?? 'Security');
    $stmt = $connection->prepare('INSERT INTO qr_scan_logs (user_id, username, scanned_content, content_type) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $userId, $username, $content, $contentType);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not save scan history.']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'verification' => verifyAccessScan($connection, $content)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$result = $connection->query('SELECT id, username, scanned_content, content_type, scanned_at FROM qr_scan_logs ORDER BY scanned_at DESC, id DESC LIMIT 100');
echo json_encode(['success' => true, 'records' => $result ? $result->fetch_all(MYSQLI_ASSOC) : []]);
