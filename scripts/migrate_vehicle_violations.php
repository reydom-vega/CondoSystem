<?php
/** Targeted upgrade: no resident-owner relinking or unrelated data migrations. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (($argv[1] ?? '')!=='--apply' || $argc!==2) { echo "Usage: php scripts/migrate_vehicle_violations.php --apply\n"; exit(2); }
putenv('CONDO_MIGRATION_MODE=1');
$locked=false; $db=null;
try {
    require_once dirname(__DIR__).'/config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db=new mysqli($configuredDbHost,$configuredDbUser,$configuredDbPass,$configuredDbName);
    $db->set_charset('utf8mb4');
    $lockName='condo-migrate:'.sha1((string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name']);
    $lock=$db->prepare('SELECT GET_LOCK(?,5) AS acquired'); $lock->bind_param('s',$lockName); $lock->execute();
    $locked=(int)$lock->get_result()->fetch_assoc()['acquired']===1;
    if (!$locked) throw new RuntimeException('Another migration is running.');
    if (!ensureVehicleViolationColumns($db) || !ensureParkingLookupColumns($db)) throw new RuntimeException('Vehicle schema upgrade did not complete.');
    $problems=deploymentSchemaProblems($db);
    if ($problems) throw new RuntimeException('Required application schema is incomplete. Run the full scripts/migrate.php --apply upgrade.');
    $db->query('CREATE TABLE IF NOT EXISTS app_schema_versions (version VARCHAR(32) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $version=APP_SCHEMA_VERSION; $stmt=$db->prepare('INSERT IGNORE INTO app_schema_versions(version) VALUES (?)'); $stmt->bind_param('s',$version); $stmt->execute();
    echo "Vehicle violation migration complete: $version. Existing records and bills retained.\n";
} catch (Throwable $error) {
    error_log('Vehicle violation migration failed: '.$error->getMessage()); fwrite(STDERR,"Vehicle violation migration failed; schema version was not recorded. Check the application error log.\n"); exit(1);
} finally {
    if ($locked && $db instanceof mysqli) { $stmt=$db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s',$lockName); $stmt->execute(); }
}
