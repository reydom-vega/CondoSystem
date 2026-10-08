<?php
// CLI-only request harness. Never accepts the application's actual database.
if (PHP_SAPI !== 'cli' || !preg_match('/^condo_role_test_[a-f0-9]{12}$/D', getenv('CONDO_DB_NAME') ?: '')) {
    http_response_code(404); exit;
}
$page = $argv[1] ?? '';
$allowed = ['superadmin/residents.php', 'superadmin/units.php', 'superadmin/pending_accounts.php', 'superadmin/staff.php', 'superadmin/announcements.php', 'superadmin/admin_messages.php', 'superadmin/maintenancerequests.php', 'superadmin/parking.php', 'superadmin/parkinginventory.php', 'superadmin/violations.php', 'superadmin/registeredvehicles.php', 'superadmin/notification_delivery.php', 'security/scanner.php', 'superadmin/scanner.php', 'api/scan_history.php', 'maintenance/maintenancerequests.php', 'api/admin_messages.php', 'api/admin_dashboard.php', 'api/dashboard.php', 'api/messages.php', 'api/notifications.php'];
if (!in_array($page, $allowed, true)) exit(1);
$request = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/' . $page;
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/' . $page;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = isset($request['post']) ? 'POST' : 'GET';
require_once __DIR__ . '/../config.php';
$userId = (int)($request['actor'] ?? 1);
$db = connectDb();
$actor = $db->query('SELECT role, session_version FROM users WHERE id = ' . $userId)->fetch_assoc();
$_SESSION = ['user_id' => $userId, 'role' => $request['role'] ?? $actor['role'] ?? '', 'username' => 'Fixture', 'last_activity' => time(), 'session_version' => $request['version'] ?? $actor['session_version'] ?? 0];
$_GET = $request['get'] ?? [];
$_POST = $request['post'] ?? [];
if (isset($request['post'])) $_POST['csrf_token'] = empty($request['invalid_csrf']) ? workflowCsrfToken() : 'invalid';
register_shutdown_function(static function (): void { fwrite(STDERR, 'FIXTURE_RESPONSE_STATUS=' . (http_response_code() ?: 200)); });
chdir(dirname(__DIR__) . '/' . dirname($page));
require dirname(__DIR__) . '/' . $page;
