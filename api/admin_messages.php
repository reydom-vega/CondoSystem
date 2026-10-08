<?php
/**
 * API endpoint for fetching messages (admin view)
 * Returns JSON of messages for a specific resident
 */

require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

requireCapability('messages.manage', true);

$connection = connectDb();
ensureMessagesTable($connection);

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user_id']);
    exit;
}

try {
    // Get messages for this resident
    $query = $connection->prepare(
        'SELECT id, sender_role, body, attachment_name, attachment_mime, created_at FROM messages WHERE user_id = ? ORDER BY created_at ASC'
    );
    $query->bind_param('i', $userId);
    $query->execute();
    $result = $query->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => (int)$row['id'],
            'sender_role' => $row['sender_role'],
            'body' => $row['body'],
            'attachment_name' => $row['attachment_name'],
            'attachment_mime' => $row['attachment_mime'],
            'created_at' => $row['created_at'],
            'time' => date('M j, Y g:i A', strtotime($row['created_at'])),
            'timestamp' => strtotime($row['created_at'])
        ];
    }
    $query->close();

    // Get resident info
    $residentQuery = $connection->prepare('SELECT full_name, username, unit_number FROM users WHERE id = ?');
    $residentQuery->bind_param('i', $userId);
    $residentQuery->execute();
    $residentRow = $residentQuery->get_result()->fetch_assoc();
    $residentQuery->close();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'resident' => $residentRow ?: null,
        'messages' => $messages,
        'count' => count($messages),
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

$connection->close();
