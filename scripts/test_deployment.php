<?php
/** Fresh-install and upgrade checks are confined to a disposable database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/environment.php';
require_once dirname(__DIR__) . '/includes/deployment_schema.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'), appSetting('CONDO_DB_USER','root'), appSetting('CONDO_DB_PASS'));
$database = 'condo_deploy_test_' . bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
putenv('CONDO_DB_NAME=' . $database);
putenv('CONDO_APP_ENV=local');
putenv('CONDO_APP_URL=http://localhost/CondoSystem3');
putenv('CONDO_AUTO_MIGRATE=0');
$checks = 0;
function deploymentTestCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
}
function deploymentTestCli(array $arguments, string $input = ''): array {
    $process = proc_open(array_merge([PHP_BINARY], $arguments), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not launch CLI check.');
    if ($input !== '') fwrite($pipes[0],$input);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['status'=>proc_close($process),'output'=>$output,'error'=>$error];
}
try {
    $server->select_db($database);
    $server->multi_query(file_get_contents(dirname(__DIR__) . '/database'));
    do { $result = $server->store_result(); if ($result) $result->free(); } while ($server->more_results() && $server->next_result());
    $before = deploymentTestCli(['scripts/preflight.php']);
    deploymentTestCheck($before['status'] === 1, 'base-only install fails readiness without changes');
    $marker = $server->query("SHOW TABLES LIKE 'app_schema_versions'");
    deploymentTestCheck($marker->num_rows === 0, 'preflight does not create schema marker');
    $noApply = deploymentTestCli(['scripts/migrate.php']);
    deploymentTestCheck($noApply['status'] === 0 && str_contains($noApply['output'], '--apply'), 'migration requires explicit apply');
    deploymentTestCheck($server->query("SHOW TABLES LIKE 'app_schema_versions'")->num_rows === 0, 'migration help makes no database changes');

    $migration = deploymentTestCli(['scripts/migrate.php','--apply']);
    deploymentTestCheck($migration['status'] === 0, 'fresh base migration completes: ' . trim($migration['error']));
    deploymentTestCheck(deploymentSchemaProblems($server) === [], 'required schema and engines validated');
    $first = $server->query("SELECT applied_at FROM app_schema_versions WHERE version='" . APP_SCHEMA_VERSION . "'")->fetch_assoc();
    deploymentTestCheck((bool)$first, 'successful migration records application version');
    $repeat = deploymentTestCli(['scripts/migrate.php','--apply']);
    deploymentTestCheck($repeat['status'] === 0, 'migration can be repeated safely');
    $second = $server->query("SELECT applied_at FROM app_schema_versions WHERE version='" . APP_SCHEMA_VERSION . "'")->fetch_assoc();
    deploymentTestCheck($first === $second, 'repeated migration preserves version timestamp');
    $ready = deploymentTestCli(['scripts/preflight.php']);
    deploymentTestCheck($ready['status'] === 0 && str_contains($ready['output'], '0 failure(s)'), 'migrated install passes schema readiness');
    $production = deploymentTestCli(['scripts/preflight.php','--production']);
    deploymentTestCheck($production['status'] === 1 && str_contains($production['output'], 'FAIL CONDO_APP_ENV'), 'production mode fails incomplete production settings');

    $password = 'DeploymentFixture!42';
    $provision = deploymentTestCli(['create_admin.php','fixture_admin','staff@example.invalid','--password-stdin','--full-name=Fixture Administrator'],$password . "\n");
    deploymentTestCheck($provision['status'] === 0, 'CLI provisioning creates initial administrator');
    $staff = $server->query("SELECT * FROM users WHERE username='fixture_admin'")->fetch_assoc();
    deploymentTestCheck($staff['role']==='superadmin' && $staff['status']==='approved' && password_verify($password,$staff['password_hash']), 'provisioning stores strong password hash and approved staff role');
    deploymentTestCheck(!str_contains($provision['output'] . $provision['error'],$password), 'provisioning never echoes stdin password');
    $duplicate = deploymentTestCli(['create_admin.php','fixture_admin','staff@example.invalid','--password-stdin'],$password . "\n");
    deploymentTestCheck($duplicate['status'] === 1, 'existing staff cannot be overwritten without update flag');
    $server->query("INSERT INTO remember_tokens (user_id,selector,validator_hash,expires_at) VALUES (" . (int)$staff['id'] . ",'deploymentselectorfixture',REPEAT('a',64),DATE_ADD(NOW(),INTERVAL 1 DAY))");
    $server->query("UPDATE users SET reset_token=REPEAT('b',64),reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=" . (int)$staff['id']);
    $updated = deploymentTestCli(['create_admin.php','fixture_admin','staff@example.invalid','--password-stdin','--update','--role=security'],$password . "\n");
    deploymentTestCheck($updated['status'] === 0, 'explicit update changes a staff account');
    $changed = $server->query("SELECT * FROM users WHERE username='fixture_admin'")->fetch_assoc();
    deploymentTestCheck($changed['role']==='security' && (int)$changed['session_version']===1 && $changed['reset_token']===null, 'staff update invalidates sessions and password recovery');
    deploymentTestCheck((int)$server->query('SELECT COUNT(*) AS total FROM remember_tokens')->fetch_assoc()['total']===0, 'staff update revokes persistent login tokens');
    $server->query("INSERT INTO users (full_name,username,email,contact_number,password_hash,role) VALUES ('Fixture Resident','fixture_resident','resident@example.invalid','TEST','fixture','resident')");
    $resident = deploymentTestCli(['create_admin.php','fixture_resident','resident@example.invalid','--password-stdin','--update'],$password . "\n");
    deploymentTestCheck($resident['status']===1 && $server->query("SELECT role FROM users WHERE username='fixture_resident'")->fetch_assoc()['role']==='resident', 'CLI cannot convert housing account into staff');
    $conflict = deploymentTestCli(['create_admin.php','fixture_admin','resident@example.invalid','--password-stdin','--update'],$password . "\n");
    deploymentTestCheck($conflict['status']===1, 'conflicting username/email accounts are rejected');
    $noMatch = deploymentTestCli(['create_admin.php','fixture_missing','missing@example.invalid','--password-stdin','--update'],$password . "\n");
    deploymentTestCheck($noMatch['status']===1, 'update does not silently create an account');
    deploymentTestCheck((int)$server->query("SELECT COUNT(*) AS total FROM audit_logs WHERE admin_name='CLI provisioning'")->fetch_assoc()['total']===2, 'CLI provisioning audits successful changes only');

    $server->query('ALTER TABLE qr_scan_logs ENGINE=MyISAM');
    $server->query('DELETE FROM app_schema_versions');
    $invalid = deploymentTestCli(['scripts/migrate.php','--apply']);
    deploymentTestCheck($invalid['status'] === 1, 'migration rejects nontransactional storage');
    deploymentTestCheck($server->query('SELECT COUNT(*) AS total FROM app_schema_versions')->fetch_assoc()['total'] == 0, 'failed validation does not record release');
    deploymentTestCheck($server->query("SHOW TABLE STATUS LIKE 'qr_scan_logs'")->fetch_assoc()['Engine'] === 'MyISAM', 'migration does not silently convert business storage');
    $server->query('ALTER TABLE qr_scan_logs ENGINE=InnoDB');
    $server->query('ALTER TABLE parking_sticker_vehicles ADD INDEX fixture_vehicle_fk (vehicle_id)');
    $server->query('ALTER TABLE parking_sticker_vehicles DROP INDEX ux_sticker_vehicle');
    deploymentTestCheck(in_array('Missing unique index: parking_sticker_vehicles.vehicle_id', deploymentSchemaProblems($server), true), 'schema validation catches missing sticker uniqueness constraint');
    echo "{$checks} deployment checks passed using a disposable database.\n";
} finally {
    // Never drop a user-supplied or application database.
    if (preg_match('/^condo_deploy_test_[a-f0-9]{12}$/D', $database)) $server->query("DROP DATABASE `{$database}`");
}
