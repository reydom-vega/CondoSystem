<?php
/** Cross-database restore attempts are confined to disposable fixture targets. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('CONDO_BACKUP_FUNCTIONS_ONLY',true);
require_once __DIR__.'/backup.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$target='condo_backup_target_test_'.bin2hex(random_bytes(6));
$escapeUser='condo_escape_test_'.bin2hex(random_bytes(6));
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'condo_backup_fixture_'.bin2hex(random_bytes(6));
mkdir($directory,0700);
$dump=$directory.'/database.sql'; $manifest=$directory.'/manifest.json';
$checks=0; $created=false;
$legacyTarget='condo_backup_legacy_test_'.bin2hex(random_bytes(6)); $legacyCreated=false;
function backupCheck(bool $condition,string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: '.$label);
    $checks++;
}
function backupFixtureCommand(array $arguments,array $environment=[]): array {
    $process=proc_open(array_merge([PHP_BINARY],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),array_merge(getenv(),$environment));
    if (!is_resource($process)) throw new RuntimeException('Could not launch isolated validation.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['status'=>proc_close($process),'output'=>$out,'error'=>$error];
}
function backupFixtureCli(string $dump): array { return backupFixtureCommand([__DIR__.'/validate_upgrade.php','--backup='.$dump]); }
function backupFixtureMigration(string $database): array {
    if (!preg_match('/^condo_backup_(?:target|legacy)_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture migration target.');
    return backupFixtureCommand([__DIR__.'/migrate.php','--apply'],['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'0','CONDO_APP_URL'=>'http://localhost/CondoSystem3']);
}
function backupFixtureDump(string $database,string $dump): void {
    if (!preg_match('/^condo_backup_(?:target|legacy)_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture dump target.');
    $root=dirname(__DIR__);
    $binary=appSetting('CONDO_MYSQLDUMP_BINARY',PHP_OS_FAMILY==='Windows' ? dirname($root,2).'/mysql/bin/mysqldump.exe' : 'mysqldump');
    $process=proc_open([$binary,'--single-transaction','--quick','--hex-blob','--triggers','--skip-routines','--skip-events','--host='.appSetting('CONDO_DB_HOST','localhost'),'--user='.appSetting('CONDO_DB_USER','root'),'--result-file='.$dump,$database],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['MYSQL_PWD'=>appSetting('CONDO_DB_PASS')]));
    if (!is_resource($process)) throw new RuntimeException('Could not launch fixture dump.');
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    backupCheck(proc_close($process)===0 && is_file($dump) && filesize($dump)>0,'fixture SQL dump written');
}
function backupFixtureBase(mysqli $server,string $database): void {
    if (!preg_match('/^condo_backup_(?:target|legacy)_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture schema target.');
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $result=$server->store_result(); if ($result) $result->free(); } while ($server->more_results() && $server->next_result());
    $result=backupFixtureMigration($database);
    backupCheck($result['status']===0,'fixture migration creates prerequisite schema: '.$result['error']);
}
function backupFixtureAccounts(mysqli $server): array {
    $result=$server->query("SELECT User,Host FROM mysql.user WHERE User LIKE 'condo\\_restore\\_%' ORDER BY User,Host");
    return $result->fetch_all(MYSQLI_ASSOC);
}
function backupFixtureDatabases(mysqli $server): array {
    return $server->query("SHOW DATABASES LIKE 'condo\\_upgrade\\_test\\_%'")->fetch_all(MYSQLI_NUM);
}
try {
    file_put_contents($dump,'Fixture-only SQL data');
    writeBackupManifest($directory,$dump);
    $data=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
    backupCheck(hash_equals($data['database_sha256'],hash_file('sha256',$dump)),'written manifest has matching dump checksum');
    unlink($manifest); mkdir($manifest,0700);
    $threw=false; try { writeBackupManifest($directory,$dump); } catch (RuntimeException $e) { $threw=true; }
    backupCheck($threw,'manifest write failure refuses a complete backup');
    rmdir($manifest);
    $threw=false; try { writeBackupManifest($directory,$directory.'/missing.sql'); } catch (RuntimeException $e) { $threw=true; }
    backupCheck($threw,'checksum failure refuses a complete backup');

    $server->query("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4"); $created=true;
    $server->query("CREATE TABLE `{$target}`.sentinel (id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB");
    $server->query("INSERT INTO `{$target}`.sentinel VALUES (1,0)");
    $accountsBefore=backupFixtureAccounts($server); $databasesBefore=backupFixtureDatabases($server);
    $attempts=[
        "SELECT 1; USE `{$target}`; UPDATE sentinel SET value=1;",
        "/*!50000 USE `{$target}` */;\nUPDATE sentinel SET value=2;",
        "UPDATE `{$target}`.sentinel SET value=3;",
        "CREATE USER '{$escapeUser}'@'localhost' IDENTIFIED BY 'FixtureOnly!42';",
    ];
    foreach ($attempts as $index=>$sql) {
        file_put_contents($dump,$sql); writeBackupManifest($directory,$dump);
        $result=backupFixtureCli($dump);
        backupCheck($result['status']===1 && str_contains($result['error'],'restricted restore account'),'untrusted operation rejected by limited connection '.($index+1));
        backupCheck((int)$server->query("SELECT value FROM `{$target}`.sentinel WHERE id=1")->fetch_assoc()['value']===0,'separate fixture database unchanged '.($index+1));
        backupCheck(backupFixtureAccounts($server)===$accountsBefore,'temporary restore account cleaned after rejection '.($index+1));
        backupCheck(backupFixtureDatabases($server)===$databasesBefore,'temporary restore database cleaned after rejection '.($index+1));
    }
    backupCheck((int)$server->query("SELECT COUNT(*) AS n FROM mysql.user WHERE User='{$escapeUser}'")->fetch_assoc()['n']===0,'dump cannot create a global account');
    define('CONDO_UPGRADE_FUNCTIONS_ONLY',true);
    require_once __DIR__.'/validate_upgrade.php';
    backupFixtureBase($server,$target);
    $hash=password_hash('FixtureOnly!42',PASSWORD_DEFAULT);
    $residents=[
        [1,'0101','Resident Owner','approved',1,1,2],
        [2,'101','Tenant','approved',1,1,7],
        [3,'0101','Family/Relative of the Owner','approved',1,1,8],
        [4,'0102','Resident Owner','approved',1,1,3],
        [5,'102','Resident Owner','approved',1,1,4],
        [6,'0102','Tenant','approved',1,1,9],
        [7,'0103','Tenant','approved',1,1,10],
        [8,'0101','Tenant','pending',1,1,11],
        [9,'0104',null,'approved',1,1,0],
        [10,'0104','Tenant','approved',1,1,12],
        [11,'0101','Friend of Owner','approved',1,1,13],
        [12,'0105','Resident Owner','approved',0,1,14],
        [13,'0105','Tenant','approved',1,1,15],
        [14,'0106','Resident Owner','approved',1,0,16],
        [15,'0106','Tenant','approved',1,1,17],
        [16,'0101','Tenant','approved',0,1,18],
    ];
    $insert=$server->prepare('INSERT INTO users(id,full_name,username,email,contact_number,unit_number,resident_id,account_type,password_hash,role,is_verified,status,is_active,session_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $types='i'.str_repeat('s',9).'isii'; $role='resident';
    foreach ($residents as [$id,$unit,$type,$status,$active,$verified,$version]) {
        $name='Fixture '.$id; $username='backup_fixture'.$id; $email='backup'.$id.'@example.invalid'; $phone='091700000'.str_pad((string)$id,2,'0',STR_PAD_LEFT);
        $insert->bind_param($types,$id,$name,$username,$email,$phone,$unit,$unit,$type,$hash,$role,$verified,$status,$active,$version); $insert->execute();
    }
    $server->query('ALTER TABLE users ADD fixture_binary VARBINARY(8) DEFAULT NULL');
    $server->query('UPDATE users SET fixture_binary=0xFFFE0041');
    $server->query("INSERT INTO payments(id,user_id,amount,payment_method,status,due_date) VALUES(1,2,123.45,'unbilled','pending',CURDATE())");
    $server->query("INSERT INTO bill_items(id,payment_id,category,description,amount) VALUES(1,1,'Water','Preserved statement detail',123.45)");
    $server->query("INSERT INTO maintenance_requests(id,user_id,issue_type,description,status) VALUES(1,2,'Fixture','Preserved maintenance detail','pending')");
    // A .1 snapshot has neither owner links nor their supporting column.
    $server->query('ALTER TABLE users DROP COLUMN unit_owner_id');
    $rows=$server->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $plan=residentOwnerLinkPlan($rows);
    backupCheck($plan===[2=>1,3=>1,10=>9,11=>1,16=>1],'pure owner-link plan matches unique approved owners and preserves prior inactive-child behavior');
    backupCheck(array_intersect_key($plan,array_flip([6,7,8,13,15]))===[],'ambiguous unmatched pending and inactive/unverified-owner links are not planned');
    $columns=upgradeSnapshotColumns($server);
    $expected=snapshotUpgradeState($server,$plan,$columns);
    backupFixtureDump($target,$dump); writeBackupManifest($directory,$dump);
    $restored=backupFixtureCli($dump);
    backupCheck($restored['status']===0 && str_contains($restored['output'],'planned owner links'),'isolated upgrade accepts exact .2 owner-link backfill including binary account fields: '.$restored['error']);
    backupCheck(backupFixtureAccounts($server)===$accountsBefore && backupFixtureDatabases($server)===$databasesBefore,'successful upgrade cleans restricted account and restored database');
    $migrated=backupFixtureMigration($target);
    backupCheck($migrated['status']===0,'fixture owner-link migration applies: '.$migrated['error']);
    backupCheck(snapshotUpgradeState($server,[],$columns,false)===$expected,'migration preserves every existing protected field except planned owner links and one session revocation');
    foreach ($server->query('SELECT id,unit_owner_id,session_version FROM users ORDER BY id') as $row) {
        $id=(int)$row['id']; $original=$residents[$id-1][6];
        backupCheck((isset($plan[$id]) ? (int)$row['unit_owner_id']===$plan[$id] && (int)$row['session_version']===$original+1 : $row['unit_owner_id']===null && (int)$row['session_version']===$original),'exact link and version outcome for fixture resident '.$id);
    }
    backupCheck(residentOwnerLinkPlan($server->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC))===[],'existing approved owner links require no repeated backfill');
    $baseline=snapshotUpgradeState($server);
    backupCheck(backupFixtureMigration($target)['status']===0 && snapshotUpgradeState($server)===$baseline,'repeated migration leaves existing links versions and all business records unchanged');
    foreach ([
        "UPDATE users SET role='security' WHERE id=2",
        "UPDATE users SET account_type='Resident Owner' WHERE id=2",
        'UPDATE users SET session_version=session_version+1 WHERE id=6',
        'UPDATE users SET session_version=session_version+1 WHERE id=2',
        'UPDATE users SET unit_owner_id=4 WHERE id=2',
        "UPDATE users SET email='unexpected@example.invalid' WHERE id=2",
        'UPDATE users SET fixture_binary=0xFFFF0041 WHERE id=2',
        'UPDATE payments SET amount=amount+1 WHERE id=1',
        "UPDATE bill_items SET description='Unexpected financial detail' WHERE id=1",
        "UPDATE maintenance_requests SET description='Unexpected workflow detail' WHERE id=1",
    ] as $index=>$mutation) {
        $server->begin_transaction();
        try { $server->query($mutation); backupCheck(snapshotUpgradeState($server)!==$baseline,'unexpected privilege version identity financial or workflow mutation rejected '.($index+1)); }
        finally { $server->rollback(); }
        backupCheck(snapshotUpgradeState($server)===$baseline,'mutation fixture rolled back '.($index+1));
    }
    // Older accounts receive only the exact ensureUserRoles column defaults.
    $server->query("CREATE DATABASE `{$legacyTarget}` CHARACTER SET utf8mb4"); $legacyCreated=true;
    backupFixtureBase($server,$legacyTarget);
    $server->query("INSERT INTO users(id,full_name,username,email,contact_number,unit_number,resident_id,account_type,password_hash,role,is_verified,status,is_active,session_version) VALUES(1,'Old Owner','old_owner','oldowner@example.invalid','09170000001','0101','0101','Resident Owner','FixtureOnlyHash','resident',1,'approved',1,0),(2,'Old Tenant','old_tenant','oldtenant@example.invalid','09170000002','101','101','Tenant','FixtureOnlyHash','resident',1,'approved',1,0)");
    $server->query('ALTER TABLE users DROP COLUMN unit_owner_id,DROP COLUMN is_active,DROP COLUMN status,DROP COLUMN session_version');
    $oldPlan=residentOwnerLinkPlan($server->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC));
    backupCheck($oldPlan===[2=>1],'pre-.1 exact approved/active defaults permit unique owner planning');
    $oldColumns=upgradeSnapshotColumns($server); $oldExpected=snapshotUpgradeState($server,$oldPlan,$oldColumns);
    backupFixtureDump($legacyTarget,$dump); writeBackupManifest($directory,$dump);
    $oldRestore=backupFixtureCli($dump);
    backupCheck($oldRestore['status']===0,'isolated upgrade accepts older missing account columns with exact defaults: '.$oldRestore['error']);
    backupCheck(backupFixtureMigration($legacyTarget)['status']===0 && snapshotUpgradeState($server,[],$oldColumns,false)===$oldExpected,'older upgrade preserves all prior fields while adding exact account defaults and planned link/version');
    backupCheck(backupFixtureAccounts($server)===$accountsBefore && backupFixtureDatabases($server)===$databasesBefore,'older upgrade cleans temporary restore resources');
    echo "{$checks} backup/restore checks passed; disposable fixtures only, no provider calls.\n";
} finally {
    if ($created && preg_match('/^condo_backup_target_test_[a-f0-9]{12}$/D',$target)) $server->query("DROP DATABASE `{$target}`");
    if ($legacyCreated && preg_match('/^condo_backup_legacy_test_[a-f0-9]{12}$/D',$legacyTarget)) $server->query("DROP DATABASE `{$legacyTarget}`");
    if (preg_match('/^condo_escape_test_[a-f0-9]{12}$/D',$escapeUser)) $server->query("DROP USER IF EXISTS '{$escapeUser}'@'localhost'");
    if (is_file($dump)) unlink($dump);
    if (is_file($manifest)) unlink($manifest); elseif(is_dir($manifest)) rmdir($manifest);
    if (is_dir($directory)) rmdir($directory);
}
