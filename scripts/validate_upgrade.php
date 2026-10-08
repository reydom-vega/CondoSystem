<?php
/** Restore only as a temporary account scoped to one disposable database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/environment.php';
// The bootstrap ends with remember-me restoration. An empty CLI session and
// cookie collection ensure definition loading cannot access the working DB.
$_COOKIE=[];
if (session_status()===PHP_SESSION_NONE) session_id('');
$_SESSION=[];
require_once dirname(__DIR__).'/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function validateUpgradeCommand(array $args,array $env): void {
    $process=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env);
    if (!is_resource($process)) throw new RuntimeException('Could not launch isolated migration/preflight.');
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Restored-copy readiness check failed. Inspect schema/configuration before changing the working database.');
}

/** Preserve every pre-existing column in the protected business tables. */
function upgradeSnapshotColumns(mysqli $db): array {
    $columns=[];
    foreach (['users','payments','bill_items','parking_sticker_orders','bookings','resident_service_requests','parking_requests','vehicles','visitor_logs','violations','maintenance_requests','parking_slots','parking_sticker_vehicles'] as $table) {
        if (!$db->query("SHOW TABLES LIKE '{$table}'")->num_rows) continue;
        $columns[$table]=array_column($db->query("SHOW COLUMNS FROM `{$table}`")->fetch_all(MYSQLI_ASSOC),'Field');
        if ($table==='users') $columns[$table]=array_values(array_unique(array_merge($columns[$table],array_keys(upgradeAccountColumnDefaults()))));
        sort($columns[$table]);
    }
    return $columns;
}

/** Exact defaults added by ensureUserRoles, not replacements for stored NULLs. */
function upgradeAccountColumnDefaults(): array {
    return ['account_type'=>null,'unit_owner_id'=>null,'is_active'=>1,'status'=>'approved','session_version'=>0];
}

/** Only planned owner links and their single session-version increment may differ. */
function snapshotUpgradeState(mysqli $db,array $ownerLinks=[],?array $columns=null,bool $allowMissingDefaults=true): array {
    $state=[];
    foreach ($columns ?? upgradeSnapshotColumns($db) as $table=>$fields) {
        if (!$db->query("SHOW TABLES LIKE '{$table}'")->num_rows) throw new RuntimeException('Upgrade removed a protected business table.');
        $hash=hash_init('sha256'); $rows=$db->query("SELECT * FROM `{$table}` ORDER BY id");
        while($row=$rows->fetch_assoc()) {
            $protected=[];
            foreach ($fields as $field) {
                if (!array_key_exists($field,$row) && !($allowMissingDefaults && $table==='users' && array_key_exists($field,upgradeAccountColumnDefaults()))) throw new RuntimeException('Upgrade removed a protected business column.');
                $value=array_key_exists($field,$row) ? $row[$field] : upgradeAccountColumnDefaults()[$field];
                $protected[$field]=$value===null ? null : (string)$value;
            }
            if ($table==='users' && isset($ownerLinks[(int)$row['id']])) {
                if ($protected['unit_owner_id']!==null || !array_key_exists('session_version',$protected)) throw new RuntimeException('Expected owner backfill does not match the restored account.');
                $protected['unit_owner_id']=(string)$ownerLinks[(int)$row['id']];
                if ($protected['session_version']!==null) $protected['session_version']=(string)((int)$protected['session_version']+1);
            }
            hash_update($hash,serialize($protected)."\n");
        }
        $state[$table]=hash_final($hash);
    }
    return $state;
}

if (defined('CONDO_UPGRADE_FUNCTIONS_ONLY') && CONDO_UPGRADE_FUNCTIONS_ONLY===true) return;
if ($argc!==2 || !str_starts_with($argv[1],'--backup=')) {
    echo "Usage: php scripts/validate_upgrade.php --backup=<trusted database.sql snapshot>\n"
        . "Operator credentials need CREATE/DROP USER, CREATE/DROP DATABASE and GRANT for the disposable database.\n"
        . "Dump SQL is never restored with those operator credentials. DEFINER/global exports may require a reviewed portable dump.\n";
    exit($argc===1 || ($argv[1] ?? '')==='--help' ? 0 : 2);
}
$server=null; $restore=null; $databaseCreated=false; $userCreated=false;
$copy='condo_upgrade_test_'.bin2hex(random_bytes(6));
$testUser='condo_restore_'.bin2hex(random_bytes(6));
$password=bin2hex(random_bytes(32));
$account=null; $phase='snapshot validation'; $failure=false;

try {
    $file=realpath(substr($argv[1],9));
    if (!$file || !is_file($file)) throw new RuntimeException('Backup file is missing.');
    $sql=file_get_contents($file);
    if (!is_string($sql) || $sql==='') throw new RuntimeException('Backup file could not be read.');
    $manifestFile=dirname($file).'/manifest.json';
    if (!is_file($manifestFile)) throw new RuntimeException('Snapshot manifest is missing.');
    $manifest=json_decode(file_get_contents($manifestFile),true,512,JSON_THROW_ON_ERROR);
    $expected=$manifest['database_sha256'] ?? '';
    if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected) || !hash_equals($expected,hash('sha256',$sql))) throw new RuntimeException('Snapshot manifest/checksum does not match.');
    // Defense in depth only: account permissions enforce isolation even when
    // comments, stored programs or unusual syntax bypass textual detection.
    if (preg_match('/^\s*(?:USE\s|(?:CREATE|ALTER|DROP)\s+DATABASE\b)/im',$sql)) throw new RuntimeException('Use a table-only dump without database-switching statements.');
    $original=appSetting('CONDO_DB_NAME','Condo_System');
    if (preg_match('/`'.preg_quote($original,'/').'`\s*\./i',$sql)) throw new RuntimeException('Review cross-database references before restoration.');

    $phase='temporary account provisioning';
    $server=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
    $host=(string)$server->query("SELECT SUBSTRING_INDEX(USER(),'@',-1) AS client_host")->fetch_assoc()['client_host'];
    if (!preg_match('/^[A-Za-z0-9_.:%-]+$/D',$host)) throw new RuntimeException('Could not determine a safe local restore account host.');
    $account="'{$testUser}'@'".$server->real_escape_string($host)."'";
    $server->query("CREATE DATABASE `{$copy}` CHARACTER SET utf8mb4"); $databaseCreated=true;
    $server->query("CREATE USER {$account} IDENTIFIED BY '{$password}'"); $userCreated=true;
    // Database-level GRANT treats underscores as wildcard characters unless
    // partial_revokes is enabled. Grant precisely this generated database.
    $variable=$server->query("SHOW VARIABLES LIKE 'partial_revokes'")->fetch_assoc();
    $grantName=strcasecmp((string)($variable['Value'] ?? ''),'ON')===0 ? $copy : str_replace('_','\\_',$copy);
    $server->query("GRANT ALL PRIVILEGES ON `{$grantName}`.* TO {$account}");

    $phase='isolated restore';
    $restore=new mysqli(appSetting('CONDO_DB_HOST','localhost'),$testUser,$password,$copy);
    $restore->set_charset('utf8mb4');
    $restore->multi_query($sql);
    do { $result=$restore->store_result(); if($result) $result->free(); } while($restore->more_results() && $restore->next_result());
    $columns=upgradeSnapshotColumns($restore);
    $ownerLinks=residentOwnerLinkPlan($restore->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC));
    $expectedState=snapshotUpgradeState($restore,$ownerLinks,$columns);
    $env=array_merge(getenv(),[
        'CONDO_DB_NAME'=>$copy,'CONDO_DB_USER'=>$testUser,'CONDO_DB_PASS'=>$password,
        'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'0','CONDO_APP_URL'=>'http://localhost/CondoSystem3',
    ]);
    $phase='isolated migration/preflight';
    validateUpgradeCommand([__DIR__.'/migrate.php','--apply'],$env);
    if ($expectedState!==snapshotUpgradeState($restore,[],$columns,false)) throw new RuntimeException('Upgrade changed existing account or workflow/financial states beyond the planned owner-link backfill. Review before applying.');
    validateUpgradeCommand([__DIR__.'/preflight.php'],$env);
} catch (Throwable $e) {
    $failure=true;
    fwrite(STDERR,"Upgrade validation failed during {$phase}.\n");
    if ($phase==='temporary account provisioning') fwrite(STDERR,"Operator credentials require CREATE/DROP USER, CREATE/DROP DATABASE and GRANT for the disposable test database. No privileged restore was attempted.\n");
    elseif ($phase==='isolated restore') fwrite(STDERR,"Dump access or syntax was rejected by the restricted restore account. Review DEFINER/global/cross-database statements; privileged restore fallback is prohibited.\n");
    elseif ($e instanceof RuntimeException && !$e instanceof mysqli_sql_exception) fwrite(STDERR,$e->getMessage()."\n");
} finally {
    if ($restore instanceof mysqli) { try { $restore->close(); } catch (Throwable $e) {} }
    if ($server instanceof mysqli) {
        if ($userCreated) {
            try {
                if (!preg_match('/^condo_restore_[a-f0-9]{12}$/D',$testUser) || $account===null) throw new RuntimeException('Unsafe account cleanup target.');
                $server->query("DROP USER {$account}");
            } catch (Throwable $e) { $failure=true; fwrite(STDERR,"Temporary account cleanup failed. Remove only generated account {$testUser} after reviewing its host.\n"); }
        }
        if ($databaseCreated) {
            try {
                if (!preg_match('/^condo_upgrade_test_[a-f0-9]{12}$/D',$copy)) throw new RuntimeException('Unsafe database cleanup target.');
                $server->query("DROP DATABASE `{$copy}`");
            } catch (Throwable $e) { $failure=true; fwrite(STDERR,"Temporary database cleanup failed. Remove only generated database {$copy} after reviewing its target.\n"); }
        }
        $server->close();
    }
    unset($password,$env);
}
if ($failure) exit(1);
echo "Backup restored with database-scoped temporary credentials, upgraded and passed read-only preflight. Existing business data and account states preserved except planned owner links and session revocation; temporary database and account removed.\n";
