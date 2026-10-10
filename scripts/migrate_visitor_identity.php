<?php
/** Additive upgrade; existing visitor approvals and logs are preserved. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || ($argv[1] ?? '') !== '--apply') { echo "Usage: php scripts/migrate_visitor_identity.php --apply\n"; exit(2); }
putenv('CONDO_MIGRATION_MODE=1');
$db = null; $locked = false;
try {
    require_once dirname(__DIR__) . '/config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($configuredDbHost, $configuredDbUser, $configuredDbPass, $configuredDbName);
    $db->set_charset('utf8mb4');
    $lockName = 'condo-migrate:' . sha1((string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name']);
    $stmt = $db->prepare('SELECT GET_LOCK(?,5) AS acquired'); $stmt->bind_param('s', $lockName); $stmt->execute();
    $locked = (int)$stmt->get_result()->fetch_assoc()['acquired'] === 1;
    if (!$locked) throw new RuntimeException('Another migration is running.');
    if (!ensureVisitorIdentitySchema($db)) throw new RuntimeException('Identity schema upgrade failed.');
    visitorIdRetentionDays(); visitorIdStorageDirectory(true);
    if (deploymentSchemaProblems($db)) throw new RuntimeException('Required schema is incomplete. Run the full migration.');
    $db->query('CREATE TABLE IF NOT EXISTS app_schema_versions (version VARCHAR(32) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $version = APP_SCHEMA_VERSION;
    $stmt = $db->prepare('INSERT IGNORE INTO app_schema_versions(version) VALUES (?)'); $stmt->bind_param('s', $version); $stmt->execute();
    echo "Visitor identity migration complete: $version. Existing registrations require ID verification before entry.\n";
} catch (Throwable $e) {
    error_log('Visitor identity migration: ' . $e->getMessage()); fwrite(STDERR, "Identity migration failed. Check the application error log.\n"); exit(1);
} finally {
    if ($db instanceof mysqli && $locked) { $stmt = $db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s', $lockName); $stmt->execute(); }
}
