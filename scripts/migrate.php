<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || $argv[1] !== '--apply') {
    fwrite(STDOUT, "Usage: php scripts/migrate.php --apply\nBack up the database and private_uploads before applying migrations.\nThis upgrades an existing base schema; import database.sql on a fresh installation.\n");
    exit($argc === 1 || ($argv[1] ?? '') === '--help' ? 0 : 2);
}

// Explicit CLI mode is the only production path permitted to perform DDL.
putenv('CONDO_MIGRATION_MODE=1');
$db = null;
$locked = false;
$step = 'bootstrap';
try {
    require_once dirname(__DIR__) . '/config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($configuredDbHost, $configuredDbUser, $configuredDbPass, $configuredDbName);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+08:00'");
    $lockName = 'condo-migrate:' . sha1((string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name']);
    $lock = $db->prepare('SELECT GET_LOCK(?, 5) AS acquired');
    $lock->bind_param('s', $lockName); $lock->execute();
    $locked = (int)$lock->get_result()->fetch_assoc()['acquired'] === 1;
    if (!$locked) throw new RuntimeException('Another migration is running.');
    $users = $db->query("SHOW TABLES LIKE 'users'");
    if (!$users->num_rows) throw new RuntimeException('Import the base database schema first.');

    $functions = [
        'ensureUserRoles', 'ensureResidentAccountSchema', 'ensureAuthenticationSchema', 'ensurePaymentsTable', 'ensureBillingTables', 'ensurePaymongoColumns',
        'ensureReminderColumn', 'ensureNotificationOutboxTable', 'ensureMaintenanceTable', 'ensureMessagesTable', 'ensureBookingsTable',
        'ensureAmenityBookingSchema', 'ensureAnalyticsTable', 'ensureAnnouncementsTable',
        'ensureRememberTokensTable', 'ensurePhoneVerificationColumns', 'ensureAuditLogTable',
        'ensureParkingTables', 'ensureParkingPolicyTable', 'ensureParkingStickerOrdersTable',
        'ensureVehiclesTable', 'ensureStickerVehicleLinks', 'ensureViolationsTable',
        'ensureVisitorLogsTable', 'ensureVisitorLogColumns', 'ensureResidentServicesTables', 'ensureQrScanHistorySchema',
    ];
    foreach ($functions as $function) {
        $step = $function;
        if ($function($db) === false) throw new RuntimeException('A schema update did not complete.');
    }
    $step = 'access storage';
    $db->query("CREATE TABLE IF NOT EXISTS access_signing_keys (key_name VARCHAR(30) PRIMARY KEY, key_value CHAR(64) NOT NULL) ENGINE=InnoDB");
    $key = bin2hex(random_bytes(32));
    $seed = $db->prepare("INSERT IGNORE INTO access_signing_keys (key_name,key_value) VALUES ('parking',?)");
    $seed->bind_param('s', $key); $seed->execute();
    $db->query("CREATE TABLE IF NOT EXISTS qr_scan_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, username VARCHAR(100) NOT NULL,
        scanned_content TEXT NOT NULL, content_type VARCHAR(20) NOT NULL DEFAULT 'text',
        scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id), INDEX(scanned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $step = 'schema validation';
    $problems = deploymentSchemaProblems($db);
    if ($problems) throw new RuntimeException(implode('; ', $problems));
    $db->query("CREATE TABLE IF NOT EXISTS app_schema_versions (version VARCHAR(32) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $version = APP_SCHEMA_VERSION;
    $record = $db->prepare('INSERT IGNORE INTO app_schema_versions (version) VALUES (?)');
    $record->bind_param('s', $version); $record->execute();
    fwrite(STDOUT, "Migration complete: {$version}. Run php scripts/preflight.php next.\n");
} catch (Throwable $e) {
    // Do not print connection credentials or sensitive database exception text.
    fwrite(STDERR, "Migration failed during {$step}. The version was not recorded.\n");
    if ($e instanceof RuntimeException && !$e instanceof mysqli_sql_exception) fwrite(STDERR, $e->getMessage() . "\n");
    error_log('Condo migration: ' . $e->getMessage());
    exit(1);
} finally {
    if ($db instanceof mysqli && $locked) {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s', $lockName); $release->execute();
    }
}
