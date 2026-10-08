<?php
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/deployment_schema.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() === PHP_SESSION_NONE) {
    session_name('CONDOSESSID');
    session_set_cookie_params(['path' => '/', 'secure' => appUsesSecureCookies(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

date_default_timezone_set('Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}
set_exception_handler(static function (Throwable $exception): void {
    $reference = bin2hex(random_bytes(6));
    error_log('Application error [' . $reference . ']: ' . $exception);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Application error. Reference: ' . $reference . PHP_EOL);
        exit(1);
    }
    http_response_code(503);
    echo 'The service is temporarily unavailable. Please try again later. Reference: ' . $reference;
});

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    throw new RuntimeException('Install application dependencies with Composer before starting the application.');
}
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getUnitInventoryFilePath(): string {
    $baseDir = __DIR__;
    $candidates = [
        $baseDir . '/inventory/condo_units.csv',
        $baseDir . '/condo_units.csv',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $baseDir . '/inventory/condo_units.csv';
}

function normalizeUnitNumber(?string $value): string {
    if ($value === null) {
        return '';
    }

    $normalized = strtoupper(trim((string) $value));
    $normalized = preg_replace('/\s+/', '', $normalized);
    $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized);

    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^\d+$/', $normalized)) {
        return str_pad(ltrim($normalized, '0') !== '' ? ltrim($normalized, '0') : '0', 4, '0', STR_PAD_LEFT);
    }

    return $normalized;
}

function loadUnitInventory(): array {
    $filePath = getUnitInventoryFilePath();
    if (!is_file($filePath)) {
        return [];
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle);
    $inventory = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (!is_array($row) || count($row) < 3) {
            continue;
        }

        $unitNumber = normalizeUnitNumber((string) $row[0]);
        if ($unitNumber === '') {
            continue;
        }

        $inventory[] = [
            'unit_number' => $unitNumber,
            'floor' => (int) $row[1],
            'unit_on_floor' => (int) $row[2],
        ];
    }

    fclose($handle);
    return $inventory;
}

function getUnitInventoryCount(): int {
    return count(loadUnitInventory());
}

const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'Condo_System';

const SMTP_HOST      = 'smtp.gmail.com';
const SMTP_USER      = '';
const SMTP_PASS      = '';
const SMTP_PORT      = 587;                          
const SMTP_SECURE    = 'tls'; 
const SMTP_DEBUG     = 0;
const MAIL_FROM      = '';
const MAIL_FROM_NAME = 'The Celandine Homes';

$configuredDbHost = appSetting('CONDO_DB_HOST', DB_HOST);
$configuredDbUser = appSetting('CONDO_DB_USER', DB_USER);
$configuredDbPass = appSetting('CONDO_DB_PASS', DB_PASS);
$configuredDbName = appSetting('CONDO_DB_NAME', DB_NAME);
$configuredSmtpHost = appSetting('CONDO_SMTP_HOST', SMTP_HOST);
$configuredSmtpUser = appSetting('CONDO_SMTP_USER', SMTP_USER);
$configuredSmtpPass = appSetting('CONDO_SMTP_PASS', SMTP_PASS);
$configuredMailFrom = appSetting('CONDO_MAIL_FROM', MAIL_FROM ?: $configuredSmtpUser);

function connectDb(): mysqli {
    global $configuredDbHost, $configuredDbUser, $configuredDbPass, $configuredDbName;
    $connection = new mysqli($configuredDbHost, $configuredDbUser, $configuredDbPass, $configuredDbName);

    $connection->set_charset('utf8mb4');
    $connection->query("SET time_zone = '+08:00'");
    if (schemaMutationAllowed()) {
        static $rolesReady = false;
        if (!$rolesReady) {
            ensureUserRoles($connection);
            $rolesReady = true;
        }
    } else {
        $stmt = $connection->prepare('SELECT version FROM app_schema_versions WHERE version = ? LIMIT 1');
        $version = APP_SCHEMA_VERSION;
        $stmt->bind_param('s', $version);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            throw new RuntimeException('Database migration is required for this release.');
        }
    }

    return $connection;
}

function getUserRoles(): array {
    return [
        'resident' => 'Resident',
        'admin' => 'Admin',
        'superadmin' => 'SuperAdmin',
        'treasurer' => 'Treasurer',
        'maintenance' => 'Maintenance',
        'security' => 'Security',
    ];
}

function ensureUserRoles(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    $usersTable = $connection->query("SHOW TABLES LIKE 'users'");
    if (!$usersTable || $usersTable->num_rows === 0) {
        return false;
    }

    $role = $connection->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
    if (!$role || !str_contains((string)$role['Type'], "'security'") || !str_contains((string)$role['Type'], "'treasurer'")) {
        if (!$connection->query("ALTER TABLE users MODIFY role ENUM('resident', 'admin', 'superadmin', 'treasurer', 'maintenance', 'security') NOT NULL DEFAULT 'resident'")) return false;
    }

    $columns = [
        'staff_id' => "ALTER TABLE users ADD staff_id VARCHAR(20) DEFAULT NULL AFTER username",
        'resident_id' => "ALTER TABLE users ADD resident_id VARCHAR(20) DEFAULT NULL AFTER staff_id",
        'is_active' => "ALTER TABLE users ADD is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_verified",
        'last_login_at' => "ALTER TABLE users ADD last_login_at DATETIME DEFAULT NULL AFTER is_active",
        'last_seen_at' => "ALTER TABLE users ADD last_seen_at DATETIME DEFAULT NULL AFTER last_login_at",
        'session_version' => "ALTER TABLE users ADD session_version INT NOT NULL DEFAULT 0 AFTER last_seen_at",
        'verification_token' => "ALTER TABLE users ADD verification_token VARCHAR(100) DEFAULT NULL AFTER is_verified",
        'verification_expires' => "ALTER TABLE users ADD verification_expires DATETIME DEFAULT NULL",
        // Existing accounts default to 'approved' so nobody who was already
        // set up before this feature gets locked out; new resident signups
        // should explicitly insert 'pending' instead.
        'status' => "ALTER TABLE users ADD status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER is_verified",
        'rejection_reason' => "ALTER TABLE users ADD rejection_reason TEXT DEFAULT NULL AFTER status",
        'account_type' => "ALTER TABLE users ADD account_type VARCHAR(80) DEFAULT NULL AFTER rejection_reason",
        'unit_owner_id' => "ALTER TABLE users ADD unit_owner_id INT DEFAULT NULL AFTER account_type",
        'reset_token' => "ALTER TABLE users ADD reset_token VARCHAR(100) DEFAULT NULL",
        'reset_expires' => "ALTER TABLE users ADD reset_expires DATETIME DEFAULT NULL",
        'failed_login_attempts' => "ALTER TABLE users ADD failed_login_attempts INT NOT NULL DEFAULT 0",
        'locked_until' => "ALTER TABLE users ADD locked_until DATETIME DEFAULT NULL",
    ];
    foreach ($columns as $column => $alter) {
        $exists = $connection->query("SHOW COLUMNS FROM users LIKE '{$column}'");
        if ($exists && $exists->num_rows === 0 && !$connection->query($alter)) {
            return false;
        }
    }

    if (!$connection->query("UPDATE users SET staff_id = CONCAT('STAFF-', LPAD(id, 5, '0')) WHERE role <> 'resident' AND (staff_id IS NULL OR staff_id = '')")) {
        return false;
    }

    $residentIndex = $connection->query("SHOW INDEX FROM users WHERE Key_name = 'ux_users_resident_id'");
    if ($residentIndex && $residentIndex->num_rows > 0 && !$connection->query('ALTER TABLE users DROP INDEX ux_users_resident_id')) {
        return false;
    }

    if (!$connection->query("UPDATE users SET resident_id = unit_number WHERE role = 'resident'")) {
        return false;
    }

    $staffIndex = $connection->query("SHOW INDEX FROM users WHERE Key_name = 'ux_users_staff_id'");
    if ($staffIndex && $staffIndex->num_rows === 0 && !$connection->query('ALTER TABLE users ADD UNIQUE INDEX ux_users_staff_id (staff_id)')) {
        return false;
    }

    return true;
}

function ensurePaymentsTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    return $connection->query("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        payment_method VARCHAR(30) NOT NULL,
        status ENUM('pending', 'paid', 'overdue', 'rejected') NOT NULL DEFAULT 'pending',
        due_date DATE NOT NULL,
        paid_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )") === true;
}

function ensureMaintenanceTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    if (!$connection->query("CREATE TABLE IF NOT EXISTS maintenance_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        issue_type VARCHAR(80) NOT NULL,
        description TEXT NOT NULL,
        preferred_date DATE DEFAULT NULL,
        location VARCHAR(80) DEFAULT NULL,
        urgency ENUM('low', 'normal', 'urgent') NOT NULL DEFAULT 'normal',
        evidence_path VARCHAR(64) DEFAULT NULL,
        completion_note TEXT DEFAULT NULL,
        before_photo_path VARCHAR(64) DEFAULT NULL,
        after_photo_path VARCHAR(64) DEFAULT NULL,
        status ENUM('pending', 'approved', 'in_progress', 'completed', 'closed', 'rejected', 'cancelled', 'reopened') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id),
        CONSTRAINT fk_maintenance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )")) {
        return false;
    }

    $columns = [
        'location' => 'VARCHAR(80) DEFAULT NULL',
        'urgency' => "ENUM('low', 'normal', 'urgent') NOT NULL DEFAULT 'normal'",
        'evidence_path' => 'VARCHAR(64) DEFAULT NULL',
        'completion_note' => 'TEXT DEFAULT NULL',
        'before_photo_path' => 'VARCHAR(64) DEFAULT NULL',
        'after_photo_path' => 'VARCHAR(64) DEFAULT NULL',
    ];
    foreach ($columns as $column => $definition) {
        $result = $connection->query("SHOW COLUMNS FROM maintenance_requests WHERE Field = '" . $connection->real_escape_string($column) . "'");
        if (!$result || ($result->num_rows === 0 && !$connection->query("ALTER TABLE maintenance_requests ADD COLUMN {$column} {$definition}"))) {
            return false;
        }
    }

    $statusColumn = $connection->query("SHOW COLUMNS FROM maintenance_requests WHERE Field = 'status'");
    if (!$statusColumn || !$statusColumn->num_rows) {
        return false;
    }
    $statusType = (string)$statusColumn->fetch_assoc()['Type'];
    $requiredStatuses = ['pending', 'approved', 'in_progress', 'completed', 'closed', 'rejected', 'cancelled', 'reopened'];
    foreach ($requiredStatuses as $status) {
        if (strpos($statusType, "'{$status}'") === false) {
            return $connection->query("ALTER TABLE maintenance_requests MODIFY status ENUM('pending', 'approved', 'in_progress', 'completed', 'closed', 'rejected', 'cancelled', 'reopened') NOT NULL DEFAULT 'pending'") === true;
        }
    }
    return true;
}

function ensureMessagesTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    if (!$connection->query("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        sender_role ENUM('resident', 'admin') NOT NULL,
        body TEXT NOT NULL,
        attachment_path VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        attachment_mime VARCHAR(100) DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )")) {
        return false;
    }

    $columns = ['attachment_path', 'attachment_name', 'attachment_mime'];
    foreach ($columns as $column) {
        $result = $connection->query("SHOW COLUMNS FROM messages WHERE Field = '" . $connection->real_escape_string($column) . "'");
        if (!$result) {
            return false;
        }
        if ($result->num_rows === 0 && !$connection->query("ALTER TABLE messages ADD COLUMN {$column} VARCHAR(" . ($column === 'attachment_mime' ? '100' : '255') . ") DEFAULT NULL")) {
            return false;
        }
    }

    return true;
}

function storeMessageAttachment(array $upload): array {
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['error' => $uploadError === UPLOAD_ERR_NO_FILE ? '' : 'The attachment upload failed. Please try again.'];
    }

    if ((int)($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        return ['error' => 'Attachments must be 10 MB or smaller.'];
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
    ];
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($upload['tmp_name'] ?? '');
    if (!isset($allowedTypes[$mimeType])) {
        return ['error' => 'Use a JPG, PNG, GIF, WEBP, PDF, TXT, CSV, DOC, DOCX, XLS, or XLSX attachment.'];
    }

    $directory = __DIR__ . '/private_uploads/message_attachments';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        return ['error' => 'Secure attachment storage is unavailable. Please contact management.'];
    }

    $storedName = bin2hex(random_bytes(24)) . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $storedName)) {
        return ['error' => 'Could not save the attachment. Please try again.'];
    }

    return [
        'path' => $storedName,
        'name' => basename((string)($upload['name'] ?? 'attachment')),
        'mime' => $mimeType,
        'error' => ''
    ];
}

function ensureBookingsTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    return $connection->query("CREATE TABLE IF NOT EXISTS bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amenity VARCHAR(80) NOT NULL,
        booking_date DATE NOT NULL,
        booking_time TIME NOT NULL,
        status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )") === true;
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }

    return null;
}

function getUnreadNotificationCount(array $notifications): int {
    $total = 0;
    foreach ($notifications as $notification) {
        $total += max(0, (int)($notification['unread_count'] ?? 0));
    }
    return $total;
}

function getNotifications(): array {
    if (!isLoggedIn()) return [];
    $role = (string)($_SESSION['role'] ?? '');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $notifications = [];
    $connection = connectDb();
    $can = static fn(string $capability): bool => roleHasCapability($role, $capability);
    $add = static function (int $count, string $icon, string $title, string $message, string $route) use (&$notifications): void {
        if ($count > 0) $notifications[] = ['icon' => $icon, 'title' => $title, 'message' => $count . ' ' . $message, 'time' => 'Now', 'link' => buildUrl($route), 'unread_count' => $count];
    };
    $count = static function (string $sql) use ($connection): int {
        $result = $connection->query($sql);
        return $result ? (int)($result->fetch_assoc()['total'] ?? 0) : 0;
    };
    if ($can('billing.manage') && ensureBillingTables($connection)) {
        $add($count("SELECT COUNT(*) AS total FROM payments WHERE status = 'pending'"), 'credit-card', 'Pending payments', 'payment records await confirmation.', 'superadmin/unitpayments.php?status=pending');
        $add($count("SELECT COUNT(*) AS total FROM payments WHERE status = 'overdue'"), 'violations', 'Overdue payments', 'payment records require follow-up.', 'superadmin/unitpayments.php?status=overdue');
    }
    if ($can('messages.manage') && ensureMessagesTable($connection)) {
        $add($count("SELECT COUNT(*) AS total FROM messages WHERE sender_role = 'resident' AND is_read = 0"), 'messages', 'New resident messages', 'unread messages need a reply.', 'superadmin/admin_messages.php');
    }
    if ($can('maintenance.work') && ensureMaintenanceTable($connection)) {
        if ($can('maintenance.review')) $add($count("SELECT COUNT(*) AS total FROM maintenance_requests WHERE status = 'pending'"), 'maintenance', 'Pending maintenance requests', 'requests await review.', 'superadmin/maintenancerequests.php?status=pending');
        $add($count("SELECT COUNT(*) AS total FROM maintenance_requests WHERE status IN ('approved','reopened','in_progress')"), 'maintenance', 'Maintenance work queue', 'requests are ready for work or in progress.', 'superadmin/maintenancerequests.php');
    }
    if ($can('bookings.review') && ensureBookingsTable($connection)) {
        $add($count("SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'"), 'calendar', 'Pending booking requests', 'bookings await approval.', 'superadmin/bookingrequest.php');
    }
    if ($can('parking.review') && ensureParkingTables($connection)) {
        $add($count("SELECT COUNT(*) AS total FROM parking_requests WHERE status = 'pending'"), 'parking', 'Pending parking requests', 'parking requests await approval.', 'superadmin/parking.php');
    }
    if ($can('violations.review') && ensureViolationsTable($connection)) {
        $add($count("SELECT COUNT(*) AS total FROM violations WHERE status = 'disputed'"), 'violations', 'Disputed violations', 'violations await review.', 'superadmin/violations.php');
    }
    if (($can('visitors.review') || $can('permits.review')) && ensureResidentServicesTables($connection)) {
        if ($can('visitors.review')) $add($count("SELECT COUNT(*) AS total FROM resident_service_requests WHERE request_kind = 'visitor' AND status = 'pending' AND end_date >= CURRENT_DATE()"), 'visitors', 'Pending visitor registrations', 'visitor registrations await review.', 'superadmin/service_requests.php?kind=visitor');
        if ($can('permits.review')) $add($count("SELECT COUNT(*) AS total FROM resident_service_requests WHERE request_kind = 'permit' AND status = 'pending' AND end_date >= CURRENT_DATE()"), 'calendar', 'Pending permit requests', 'permits await review.', 'superadmin/service_requests.php?kind=permit');
    }
    if ($can('vehicles.review') && ensureVehiclesTable($connection)) {
        $add($count("SELECT COUNT(*) AS total FROM vehicles WHERE status = 'pending'"), 'parking', 'Pending vehicle registrations', 'vehicles await review.', 'superadmin/registeredvehicles.php');
    }
    if ($role === 'resident' && isApproved()) {
        $billUsers=residentBillingUserIds($connection,$userId);
        $payer=residentUserHasPermission($connection,$userId,'resident.billing.pay');
        if ($billUsers && ensurePaymentsTable($connection)) $add($count("SELECT COUNT(*) AS total FROM payments WHERE user_id IN (".implode(',',array_map('intval',$billUsers)).") AND status IN ('pending','overdue')"), 'credit-card', $payer ? 'Payments need attention' : 'Unit bills available', $payer ? 'bills await payment.' : 'bills can be viewed; the unit owner handles payment.', 'resident/payments.php');
        if (residentUserHasPermission($connection,$userId,'resident.messages.use') && ensureMessagesTable($connection)) $add($count("SELECT COUNT(*) AS total FROM messages WHERE user_id = $userId AND sender_role = 'admin' AND is_read = 0"), 'messages', 'New message from Management', 'unread messages are in your inbox.', 'resident/messages.php');
        if (residentUserHasPermission($connection,$userId,'resident.maintenance.request') && ensureMaintenanceTable($connection)) $add($count("SELECT COUNT(*) AS total FROM maintenance_requests WHERE user_id = $userId AND status IN ('approved','in_progress','completed','reopened')"), 'maintenance', 'Maintenance request update', 'requests have updates or need confirmation.', 'resident/maintenance.php');
        if (ensureAnnouncementsTable($connection)) $add($count("SELECT COUNT(*) AS total FROM announcements WHERE priority = 'high' AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 'announcements', 'Important announcement', 'important announcements were posted.', 'resident/announcements.php');
    }
    $connection->close();
    foreach ($notifications as &$notification) {
        $notification['key'] = hash('sha256', implode('|', [(string)$notification['icon'], (string)$notification['title'], (string)$notification['message'], (string)$notification['link']]));
    }
    unset($notification);
    $activeKeys = array_column($notifications, 'key');
    $dismissedKeys = array_values(array_intersect($_SESSION['dismissed_notification_keys'] ?? [], $activeKeys));
    $_SESSION['dismissed_notification_keys'] = $dismissedKeys;
    return array_values(array_filter($notifications, static fn(array $notification): bool => !in_array($notification['key'], $dismissedKeys, true)));
}

function refreshSession(): void {
    $_SESSION['last_activity'] = time();
    if (!empty($_SESSION['user_id'])) {
        updateUserLastSeen((int)$_SESSION['user_id']);
    }
}

function updateUserLastSeen(?int $userId = null): void {
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    if (empty($userId)) {
        return;
    }
    static $touched = [];
    if (isset($touched[$userId])) return;
    $touched[$userId] = true;

    $connection = connectDb();
    $stmt = $connection->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function userPresenceSummary(?string $lastSeenAt, ?string $lastLoginAt = null): array {
    $reference = $lastSeenAt ?: $lastLoginAt;
    if (empty($reference)) {
        return ['online' => false, 'label' => 'Offline', 'detail' => 'No activity yet'];
    }

    try {
        $seenAt = new DateTimeImmutable($reference, new DateTimeZone('Asia/Manila'));
    } catch (Exception $e) {
        return ['online' => false, 'label' => 'Offline', 'detail' => 'Unknown'];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $secondsAgo = max(0, $now->getTimestamp() - $seenAt->getTimestamp());
    if ($secondsAgo <= 300) {
        return ['online' => true, 'label' => 'Online', 'detail' => 'Active now'];
    }

    $minutes = intdiv($secondsAgo, 60);
    $hours = intdiv($minutes, 60);
    $days = intdiv($hours, 24);

    if ($days > 0) {
        $value = $days;
        $unit = $value === 1 ? 'day' : 'days';
    } elseif ($hours > 0) {
        $value = $hours;
        $unit = $value === 1 ? 'hour' : 'hours';
    } else {
        $value = $minutes;
        $unit = $value === 1 ? 'minute' : 'minutes';
    }

    return ['online' => false, 'label' => 'Offline', 'detail' => 'Offline for ' . $value . ' ' . $unit];
}

function isSessionExpired(int $timeoutMinutes = 15): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $lastActivity = $_SESSION['last_activity'] ?? time();
    return (time() - $lastActivity) > ($timeoutMinutes * 60);
}

function isLoggedIn(bool $checkExpiration = true): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    if ($checkExpiration && isSessionExpired()) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }

    $connection = connectDb();
    $stmt = $connection->prepare('SELECT is_active, is_verified, session_version, role, username, unit_number, account_type, unit_owner_id FROM users WHERE id = ? LIMIT 1');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account || (int)$account['is_active'] !== 1 || (int)$account['is_verified'] !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    if (!isset($_SESSION['session_version'])) {
        $_SESSION['session_version'] = (int)$account['session_version'];
    } elseif ((int)$account['session_version'] !== (int)$_SESSION['session_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }

    $_SESSION['role'] = $account['role'];
    $_SESSION['username'] = $account['username'];
    $_SESSION['unit_number'] = $account['unit_number'];
    $_SESSION['account_type'] = $account['account_type'];
    $_SESSION['unit_owner_id'] = $account['unit_owner_id'];
    // Background feeds must not keep an otherwise idle session alive forever.
    $requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    if (!str_contains($requestPath, '/api/')) $_SESSION['last_activity'] = time();
    updateUserLastSeen($userId);
    return true;
}

function isAdmin(): bool {
    return isLoggedIn() && in_array($_SESSION['role'] ?? 'resident', ['admin', 'superadmin', 'treasurer', 'maintenance', 'security'], true);
}

function isSuperAdmin(): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? 'resident') === 'superadmin';
}

function isTreasurer(): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? 'resident') === 'treasurer';
}

function isMaintenance(): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? 'resident') === 'maintenance';
}

function isSecurity(): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? 'resident') === 'security';
}

/**
 * Whether the logged-in account has been approved by an admin. Always
 * checked fresh from the DB (not the session) so an approval takes
 * effect immediately without the resident needing to log out/in.
 * Staff/admin roles are created directly by a superadmin, so they're
 * always treated as approved here.
 */
function isApproved(): bool {
    if (!isLoggedIn()) {
        return false;
    }
    if (($_SESSION['role'] ?? 'resident') !== 'resident') {
        return true;
    }

    $connection = connectDb();
    $userId = (int)$_SESSION['user_id'];
    return (residentContext($connection,$userId)['approved'] ?? false) === true;
}

/**
 * Call at the top of any resident page that should be blocked until the
 * account is approved (Billing, Book Amenity, Parking, Maintenance,
 * Messages, etc). Sends the resident back to the dashboard, which shows
 * its own "awaiting approval" notice.
 */
function requireApproval(): void {
    if (!isApproved()) {
        redirect('../signuppending.php');
    }
}

function canManageBilling(): bool {
    return isSuperAdmin() || isTreasurer();
}

function isPasswordStrong(string $password): bool {
    if (strlen($password) < 8) {
        return false;
    }

    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }

    if (!preg_match('/\d/', $password)) {
        return false;
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return false;
    }

    return true;
}

function getLoginErrorMessage(string $type): string {
    return match ($type) {
        'empty' => 'Please enter both username and password.',
        'locked' => 'Your account has been locked due to multiple failed login attempts.',
        default => 'Invalid username or password.',
    };
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function generateVerificationCode(int $length = 6): string {
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= (string) random_int(0, 9);
    }
    return $code;
}

function buildUrl(string $path): string {
    $configuredBase = rtrim(appSetting('CONDO_APP_URL'), '/');
    if ($configuredBase !== '') {
        if (!filter_var($configuredBase, FILTER_VALIDATE_URL) || (appIsProduction() && !str_starts_with($configuredBase, 'https://'))) {
            throw new RuntimeException('CONDO_APP_URL must be a valid HTTPS URL in production.');
        }
        return $configuredBase . '/' . ltrim($path, '/');
    }
    if (appIsProduction()) throw new RuntimeException('CONDO_APP_URL is required in production.');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?\z/i', $host)) {
        throw new RuntimeException('Set CONDO_APP_URL for hosts other than localhost.');
    }
    $baseDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $appRoot = rtrim(str_replace('\\', '/', __DIR__), '/') . '/';
    $scriptUrl = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF']);
    if (strncasecmp($scriptFile, $appRoot, strlen($appRoot)) === 0) {
        $relativeScript = substr($scriptFile, strlen($appRoot));
        if (str_ends_with($scriptUrl, '/' . $relativeScript)) {
            $baseDir = substr($scriptUrl, 0, -strlen('/' . $relativeScript));
        }
    }
    return $scheme . '://' . $host . ($baseDir === '.' ? '' : $baseDir) . '/' . ltrim($path, '/');
}

function getLastMailError(): ?string {
    return $_SESSION['mail_error'] ?? null;
}

function sendMail(string $to, string $subject, string $message): bool {
    unset($_SESSION['mail_error']);

    $mail = new PHPMailer(true);
    global $configuredSmtpHost, $configuredSmtpUser, $configuredSmtpPass, $configuredMailFrom;

    try {
        $mail->isSMTP();
        if ($configuredSmtpUser === '' || $configuredSmtpPass === '') return false;
        $mail->SMTPDebug   = 0;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer debug [{$level}]: {$str}");
        };
        $mail->Host       = $configuredSmtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $configuredSmtpUser;
        $mail->Password   = $configuredSmtpPass;
        $mail->SMTPSecure = appSetting('CONDO_SMTP_SECURE', SMTP_SECURE);
        $mail->Port       = (int)appSetting('CONDO_SMTP_PORT', (string)SMTP_PORT);
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPAutoTLS = $mail->SMTPSecure !== PHPMailer::ENCRYPTION_SMTPS;

        $mail->Timeout = 15;
        
        $mail->setFrom($configuredMailFrom, appSetting('CONDO_MAIL_FROM_NAME', MAIL_FROM_NAME));
        $mail->addAddress($to);
        $mail->Subject      = $subject;
        $mail->Body         = $message;
        $mail->isHTML(true);  
        $mail->AltBody      = strip_tags($message); 


        return $mail->send();
    } catch (Exception $e) {
        $_SESSION['mail_error'] = $e->getMessage();
        error_log('Mailer Error: ' . $e->getMessage());
        return false;
    }
}

function ensureAnalyticsTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    return $connection->query("CREATE TABLE IF NOT EXISTS analytics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        event_type VARCHAR(50) NOT NULL,
        event_data VARCHAR(255),
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (event_type),
        INDEX (created_at)
    )") === true;
}

function trackEvent(string $eventType, string $eventData = '', ?int $userId = null): void {
    try {
        $connection = connectDb();
        ensureAnalyticsTable($connection);
        
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $connection->prepare("INSERT INTO analytics (user_id, event_type, event_data, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $userId, $eventType, $eventData, $ipAddress, $userAgent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log('Analytics tracking error: ' . $e->getMessage());
    }
}

function getAnalytics(): array {
    $connection = connectDb();
    ensureAnalyticsTable($connection);
    
    $analytics = [
        'total_logins' => 0,
        'active_residents' => 0,
        'payment_events' => 0,
        'maintenance_events' => 0,
        'booking_events' => 0,
        'total_events' => 0,
        'daily_activity' => [],
        'event_breakdown' => [],
        'payment_trend' => [],
        'maintenance_trend' => [],
        'top_issues' => [],
        'total_billed' => 0.0,
        'total_collected' => 0.0,
        'collection_rate' => 0.0,
        'overdue_count' => 0,
        'overdue_amount' => 0.0,
        'booking_status' => ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0],
        'top_amenities' => [],
        'violations_total' => 0,
        'violations_breakdown' => [],
        'outstanding_fines' => 0.0,
        'top_violation_types' => [],
        'parking_total_slots' => 0,
        'parking_occupied' => 0,
        'parking_available' => 0,
        'parking_maintenance' => 0,
        'parking_occupancy_rate' => 0.0,
        'parking_pending_requests' => 0
    ];
    
    // Total events
    $result = $connection->query("SELECT COUNT(*) AS total FROM analytics");
    if ($result) {
        $analytics['total_events'] = (int)$result->fetch_assoc()['total'];
    }
    
    // Event breakdown
    $result = $connection->query("SELECT event_type, COUNT(*) AS count FROM analytics GROUP BY event_type");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $analytics['event_breakdown'][$row['event_type']] = (int)$row['count'];
            if ($row['event_type'] === 'user_login') $analytics['total_logins'] = (int)$row['count'];
            if ($row['event_type'] === 'payment_submitted') $analytics['payment_events'] = (int)$row['count'];
            if ($row['event_type'] === 'maintenance_request') $analytics['maintenance_events'] = (int)$row['count'];
            if ($row['event_type'] === 'booking_created') $analytics['booking_events'] = (int)$row['count'];
        }
    }
    
    // Daily activity (last 7 days)
    $result = $connection->query("SELECT DATE(created_at) AS date, COUNT(*) AS count FROM analytics WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $analytics['daily_activity'][] = ['date' => $row['date'], 'count' => (int)$row['count']];
        }
    }
    
    // Payment trend (last 30 days)
    if (ensurePaymentsTable($connection)) {
        $result = $connection->query("SELECT DATE(created_at) AS date, COUNT(*) AS count, SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS collected FROM payments WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['payment_trend'][] = ['date' => $row['date'], 'count' => (int)$row['count'], 'collected' => (float)$row['collected'] ?? 0];
            }
        }
    }
    
    // Maintenance trend (last 30 days)
    if (ensureMaintenanceTable($connection)) {
        $result = $connection->query("SELECT DATE(created_at) AS date, COUNT(*) AS count, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed FROM maintenance_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['maintenance_trend'][] = ['date' => $row['date'], 'count' => (int)$row['count'], 'completed' => (int)$row['completed']];
            }
        }
        
        // Top maintenance issues
        $result = $connection->query("SELECT issue_type, COUNT(*) AS count FROM maintenance_requests GROUP BY issue_type ORDER BY count DESC LIMIT 5");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['top_issues'][] = ['issue' => $row['issue_type'], 'count' => (int)$row['count']];
            }
        }
    }
    
    // Collection rate & overdue payments (last 30 days billed vs collected)
    if (ensurePaymentsTable($connection)) {
        $result = $connection->query("SELECT
                SUM(amount) AS billed,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS collected
            FROM payments
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)");
        if ($result) {
            $row = $result->fetch_assoc();
            $analytics['total_billed'] = (float)($row['billed'] ?? 0);
            $analytics['total_collected'] = (float)($row['collected'] ?? 0);
            $analytics['collection_rate'] = $analytics['total_billed'] > 0
                ? round(($analytics['total_collected'] / $analytics['total_billed']) * 100, 1)
                : 0.0;
        }

        $result = $connection->query("SELECT COUNT(*) AS count, SUM(amount) AS total
            FROM payments WHERE status = 'overdue'");
        if ($result) {
            $row = $result->fetch_assoc();
            $analytics['overdue_count'] = (int)($row['count'] ?? 0);
            $analytics['overdue_amount'] = (float)($row['total'] ?? 0);
        }
    }

    // Booking status breakdown & top amenities
    if (ensureBookingsTable($connection)) {
        $result = $connection->query("SELECT status, COUNT(*) AS count FROM bookings GROUP BY status");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['booking_status'][$row['status']] = (int)$row['count'];
            }
        }

        $result = $connection->query("SELECT amenity, COUNT(*) AS count FROM bookings
            WHERE status != 'cancelled' GROUP BY amenity ORDER BY count DESC LIMIT 5");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['top_amenities'][] = ['amenity' => $row['amenity'], 'count' => (int)$row['count']];
            }
        }
    }

    // Violations
    if (function_exists('ensureViolationsTable') && ensureViolationsTable($connection)) {
        $result = $connection->query("SELECT COUNT(*) AS total FROM violations");
        if ($result) {
            $analytics['violations_total'] = (int)$result->fetch_assoc()['total'];
        }

        $result = $connection->query("SELECT status, COUNT(*) AS count FROM violations GROUP BY status");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['violations_breakdown'][$row['status']] = (int)$row['count'];
            }
        }

        $result = $connection->query("SELECT SUM(fine_amount) AS total FROM violations WHERE status = 'unpaid'");
        if ($result) {
            $analytics['outstanding_fines'] = (float)($result->fetch_assoc()['total'] ?? 0);
        }

        $result = $connection->query("SELECT violation_type, COUNT(*) AS count FROM violations GROUP BY violation_type ORDER BY count DESC LIMIT 5");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['top_violation_types'][] = ['type' => $row['violation_type'], 'count' => (int)$row['count']];
            }
        }
    }

    // Parking
    if (function_exists('ensureParkingTables') && ensureParkingTables($connection)) {
        $result = $connection->query("SELECT status, COUNT(*) AS count FROM parking_slots GROUP BY status");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $analytics['parking_total_slots'] += (int)$row['count'];
                if ($row['status'] === 'occupied') $analytics['parking_occupied'] = (int)$row['count'];
                if ($row['status'] === 'available') $analytics['parking_available'] = (int)$row['count'];
                if ($row['status'] === 'maintenance') $analytics['parking_maintenance'] = (int)$row['count'];
            }
        }
        $analytics['parking_occupancy_rate'] = $analytics['parking_total_slots'] > 0
            ? round(($analytics['parking_occupied'] / $analytics['parking_total_slots']) * 100, 1)
            : 0.0;

        $result = $connection->query("SELECT COUNT(*) AS count FROM parking_requests WHERE status = 'pending'");
        if ($result) {
            $analytics['parking_pending_requests'] = (int)$result->fetch_assoc()['count'];
        }
    }

    // Active residents (logged in last 7 days)
    $result = $connection->query("SELECT COUNT(DISTINCT user_id) AS count FROM analytics WHERE event_type = 'user_login' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($result) {
        $analytics['active_residents'] = (int)$result->fetch_assoc()['count'];
    }
    
    return $analytics;
}

function ensureAnnouncementsTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    if (!$connection->query("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        category VARCHAR(50),
        priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
        is_active TINYINT(1) DEFAULT 1,
        expires_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (admin_id),
        INDEX (created_at),
        INDEX (is_active),
        INDEX (expires_at)
    )")) {
        return false;
    }

    // Backfill for tables that already existed before expires_at was added.
    $expiresColumn = $connection->query("SHOW COLUMNS FROM announcements LIKE 'expires_at'");
    if ($expiresColumn && $expiresColumn->num_rows === 0) {
        if (!$connection->query("ALTER TABLE announcements ADD expires_at DATETIME DEFAULT NULL AFTER is_active")) {
            return false;
        }
        if (!$connection->query("ALTER TABLE announcements ADD INDEX (expires_at)")) {
            return false;
        }
    }

    return true;
}

function getAnnouncements(int $limit = 10, bool $activeOnly = true): array {
    $connection = connectDb();
    ensureAnnouncementsTable($connection);
    
    $query = "SELECT id, admin_id, title, content, category, priority, expires_at, created_at FROM announcements";
    if ($activeOnly) {
        $query .= " WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
    }
    $query .= " ORDER BY priority DESC, created_at DESC LIMIT " . (int)$limit;
    
    $result = $connection->query($query);
    $announcements = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
    }
    return $announcements;
}

function createAnnouncement(string $title, string $content, string $category = '', string $priority = 'medium', ?string $expiresAt = null): int|false {
    $connection = connectDb();
    ensureAnnouncementsTable($connection);
    
    $adminId = $_SESSION['user_id'] ?? 0;
    $expiresAt = ($expiresAt === '') ? null : $expiresAt;
    $stmt = $connection->prepare("INSERT INTO announcements (admin_id, title, content, category, priority, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssss', $adminId, $title, $content, $category, $priority, $expiresAt);
    
    if ($stmt->execute()) {
        trackEvent('announcement_created', $title, $adminId);
        return $connection->insert_id;
    }
    return false;
}

function updateAnnouncement(int $announcementId, string $title, string $content, string $category = '', string $priority = 'medium', int $isActive = 1, ?string $expiresAt = null): bool {
    $connection = connectDb();
    ensureAnnouncementsTable($connection);
    
    $expiresAt = ($expiresAt === '') ? null : $expiresAt;
    $stmt = $connection->prepare("UPDATE announcements SET title = ?, content = ?, category = ?, priority = ?, is_active = ?, expires_at = ? WHERE id = ?");
    $stmt->bind_param('ssssisi', $title, $content, $category, $priority, $isActive, $expiresAt, $announcementId);
    
    if ($stmt->execute()) {
        trackEvent('announcement_updated', 'ID: ' . $announcementId, $_SESSION['user_id'] ?? null);
        return true;
    }
    return false;
}

function deleteAnnouncement(int $announcementId): bool {
    $connection = connectDb();
    $stmt = $connection->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param('i', $announcementId);
    
    if ($stmt->execute()) {
        trackEvent('announcement_deleted', 'ID: ' . $announcementId, $_SESSION['user_id'] ?? null);
        return true;
    }
    return false;
}

/**
 * Feature modules. Each file only defines functions (no side effects on
 * require), so it's safe to load all of them here regardless of which
 * page ends up using them. Kept out of this file to stay focused: this
 * file is the bootstrap + original core helpers, includes/ holds the
 * newer, self-contained feature logic.
 */
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/authorization.php';
require_once __DIR__ . '/includes/resident_policy.php';
require_once __DIR__ . '/includes/resident_navigation.php';
require_once __DIR__ . '/includes/remember_me.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/paymongo.php';
require_once __DIR__ . '/includes/parking.php';
require_once __DIR__ . '/includes/notification_outbox.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/ui_icons.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/violations.php';
require_once __DIR__ . '/includes/visitors.php';
require_once __DIR__ . '/includes/maintenance.php';
require_once __DIR__ . '/includes/workflows.php';
require_once __DIR__ . '/includes/resident_services.php';
require_once __DIR__ . '/includes/parking_policy.php';
require_once __DIR__ . '/includes/amenities.php';
require_once __DIR__ . '/includes/access_scanner.php';
require_once __DIR__ . '/includes/visitor_parking.php';
require_once __DIR__ . '/includes/vehicles.php';
require_once __DIR__ . '/includes/authentication.php';

// If there's no active session but a valid remember-me cookie is
// present, this restores the session so the account stays logged in
// even after the browser is closed. Must run after every helper above
// is defined and before any page renders, hence it lives at the very
// end of the bootstrap.
attemptAutoLogin();
?>
