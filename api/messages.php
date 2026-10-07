<?php
/**
 * API endpoint for fetching messages (resident view)
 * Returns JSON of messages for live updates
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$connection = connectDb();
ensureMessagesTable($connection);

$userId = (int)$_SESSION['user_id'];
$lastFetch = isset($_GET['since']) ? (int)$_GET['since'] : 0;

try {
    // Get all messages for this user
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
            'author' => $row['sender_role'] === 'admin' ? 'Management Office' : $_SESSION['username'],
            'body' => $row['body'],
            'attachment_name' => $row['attachment_name'],
            'attachment_mime' => $row['attachment_mime'],
            'incoming' => $row['sender_role'] === 'admin',
            'time' => date('M j, Y g:i A', strtotime($row['created_at'])),
            'timestamp' => strtotime($row['created_at'])
        ];
    }
    $query->close();

    // Get announcements
    $announcements = getAnnouncements(50, true);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'announcements' => $announcements,
        'count' => count($messages),
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

$connection->close();
