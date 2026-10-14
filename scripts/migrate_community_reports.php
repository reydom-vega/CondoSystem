<?php
/** Additive, targeted installation; does not modify existing business records. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||$argv[1]!=='--apply'){echo "Usage: php scripts/migrate_community_reports.php --apply\nBack up first. Adds private community-report storage and tables.\n";exit($argc===1?0:2);}
putenv('CONDO_MIGRATION_MODE=1');
require_once dirname(__DIR__).'/config.php';
$db=connectDb();$lock='condo-migrate:'.sha1($configuredDbName);$s=$db->prepare('SELECT GET_LOCK(?,5) acquired');$s->bind_param('s',$lock);$s->execute();
if(!(int)$s->get_result()->fetch_assoc()['acquired']){fwrite(STDERR,"Another migration is running.\n");exit(1);}
try{
    ensureCommunityReportSchema($db);
    $problems=deploymentSchemaProblems($db);
    if($problems)throw new RuntimeException(implode('; ',$problems));
    $db->query('CREATE TABLE IF NOT EXISTS app_schema_versions(version VARCHAR(32) PRIMARY KEY,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $version=APP_SCHEMA_VERSION;$s=$db->prepare('INSERT IGNORE INTO app_schema_versions(version) VALUES(?)');$s->bind_param('s',$version);$s->execute();
    echo "Community reports migration complete. Existing case and business records preserved.\n";
}catch(Throwable $error){fwrite(STDERR,"Community reports migration failed; schema version was not recorded.\n");error_log($error->getMessage());exit(1);}
finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}
