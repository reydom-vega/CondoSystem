<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||$argv[1]!=='--apply'){echo "Usage: php scripts/migrate_visitor_parking.php --apply\nBack up first; additive parking fee and extension migration.\n";exit($argc===1?0:2);}
putenv('CONDO_MIGRATION_MODE=1');require_once dirname(__DIR__).'/config.php';$db=connectDb();
$lock='condo-migrate:'.sha1($configuredDbName);$s=$db->prepare('SELECT GET_LOCK(?,5) acquired');$s->bind_param('s',$lock);$s->execute();if(!(int)$s->get_result()->fetch_assoc()['acquired'])throw new RuntimeException('Another migration is running.');
try{ensureVisitorParkingFeeSchema($db);$problems=deploymentSchemaProblems($db);if($problems)throw new RuntimeException(implode('; ',$problems));$version=APP_SCHEMA_VERSION;$s=$db->prepare('INSERT IGNORE INTO app_schema_versions(version) VALUES(?)');$s->bind_param('s',$version);$s->execute();echo "Visitor parking migration complete; existing movements and bills preserved.\n";}finally{$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}
