<?php
/** Targeted, repeatable migration: adds only the optional profile-photo field. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--apply') { echo "Usage: php scripts/migrate_profile_photos.php --apply\n"; exit; }
putenv('CONDO_MIGRATION_MODE=1');
require_once dirname(__DIR__).'/config.php';
$db=connectDb();
$lockName='condo-migrate:'.sha1((string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name']);
$lock=$db->prepare('SELECT GET_LOCK(?,5) AS acquired'); $lock->bind_param('s',$lockName); $lock->execute();
if ((int)$lock->get_result()->fetch_assoc()['acquired'] !== 1) { fwrite(STDERR,"Another migration is running.\n"); exit(1); }
try {
    if (!ensureProfilePhotoSchema($db)) throw new RuntimeException('Profile photo schema could not be added.');
    profilePhotoDirectory(true);
    if (!deploymentSchemaProblems($db)) {
        $db->query('CREATE TABLE IF NOT EXISTS app_schema_versions(version VARCHAR(32) PRIMARY KEY,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $version=APP_SCHEMA_VERSION; $stmt=$db->prepare('INSERT IGNORE INTO app_schema_versions(version) VALUES(?)'); $stmt->bind_param('s',$version); $stmt->execute();
    }
    echo "Profile photo storage ready. Existing user data was preserved.\n";
} finally { $stmt=$db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s',$lockName); $stmt->execute(); }
