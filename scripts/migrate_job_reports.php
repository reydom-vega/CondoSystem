<?php
/** Use the complete versioned migration, including immutable snapshot triggers. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==2||$argv[1]!=='--apply'){echo "Usage: php scripts/migrate_job_reports.php --apply\nBack up first. Uses the complete additive schema migration.\n";exit($argc===1?0:2);}
require __DIR__.'/migrate.php';
