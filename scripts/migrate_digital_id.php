<?php
/** Add only Digital ID storage; reuse the normal release validator. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--apply') { echo "Usage: php scripts/migrate_digital_id.php --apply\n"; exit; }
putenv('CONDO_MIGRATION_MODE=1');
require_once dirname(__DIR__).'/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($configuredDbHost,$configuredDbUser,$configuredDbPass,$configuredDbName);
$db->set_charset('utf8mb4');
ensureDigitalIdSchema($db);
$problems = deploymentSchemaProblems($db);
if ($problems) { fwrite(STDERR, "Digital ID storage added. Complete the existing schema using scripts/migrate.php --apply before recording this release.\n".implode("\n", $problems)."\n"); exit(1); }
$db->query('CREATE TABLE IF NOT EXISTS app_schema_versions(version VARCHAR(32) PRIMARY KEY,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$version = APP_SCHEMA_VERSION;
$stmt = $db->prepare('INSERT IGNORE INTO app_schema_versions(version) VALUES(?)');
$stmt->bind_param('s',$version); $stmt->execute();
echo "Digital Condo ID migration complete.\n";
