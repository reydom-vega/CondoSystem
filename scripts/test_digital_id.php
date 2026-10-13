<?php
/** Real MySQL and HTTP with disposable data; never modifies the configured application DB. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database = 'condo_ui_test_'.bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$socket = stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address = stream_socket_get_name($socket,false); fclose($socket);
$port = (int)substr(strrchr($address,':'),1); $base = 'http://127.0.0.1:'.$port.'/CondoSystem3/';
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1','CONDO_UI_FIXTURE'=>'1','CONDO_UI_MANIFEST'=>'[]','CONDO_APP_URL'=>rtrim($base,'/')] as $key=>$value) putenv($key.'='.$value);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/scripts/test_digital_id.php';
require_once dirname(__DIR__).'/config.php';
$checks=0; $http=null; $log=null;
function idCheck(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$label); $checks++; }
function idActor(mysqli $db,int $id): void {
    $row=$db->query('SELECT role,username,session_version FROM users WHERE id='.$id)->fetch_assoc();
    $_SESSION=['user_id'=>$id,'role'=>$row['role'],'username'=>$row['username'],'session_version'=>(int)$row['session_version'],'last_activity'=>time()];
}
function idToken(array $issued): string { parse_str(parse_url($issued['qr_url'],PHP_URL_FRAGMENT),$parts); return $parts['credential']; }
function idDenied(callable $callback,string $label): void { try { $callback(); } catch (InvalidArgumentException $error) { idCheck(true,$label); return; } throw new RuntimeException('FAILED: '.$label); }
function idHttp(string $path,?array $data=null): array {
    global $base; static $curl;
    if (!$curl) { $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]); }
    $headers=[];
    curl_setopt_array($curl,[CURLOPT_URL=>$base.$path,CURLOPT_POST=>$data!==null,CURLOPT_HTTPHEADER=>$data===null?[]:['Content-Type: application/json'],CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$headers){$headers[]=trim($line);return strlen($line);}]);
    if ($data!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($data));
    else curl_setopt($curl,CURLOPT_HTTPGET,true);
    $body=curl_exec($curl); if (!is_string($body)) throw new RuntimeException('Fixture HTTP failed.');
    return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body,'json'=>json_decode($body,true),'headers'=>implode("\n",$headers)];
}
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $result=$server->store_result(); if($result)$result->free(); } while($server->more_results() && $server->next_result());
    $db=connectDb();
    $db->query("INSERT INTO users(id,username,full_name,email,contact_number,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES
        (1,'owner','Owner One','owner@example.invalid','','fixture','0101','resident',1,'approved','Resident Owner',NULL),
        (2,'tenant','Tenant Two','tenant@example.invalid','','fixture','0101','resident',1,'approved','Tenant',1),
        (3,'pending','Pending Person','pending@example.invalid','','fixture',NULL,'resident',1,'pending','Tenant',NULL),
        (4,'admin','Admin Staff','admin@example.invalid','','fixture',NULL,'admin',1,'approved',NULL,NULL),
        (5,'security','Security Staff','security@example.invalid','','fixture',NULL,'security',1,'approved',NULL,NULL),
        (6,'superadmin','Superadmin Staff','superadmin@example.invalid','','fixture',NULL,'superadmin',1,'approved',NULL,NULL),
        (7,'treasurer','Treasurer Staff','treasurer@example.invalid','','fixture',NULL,'treasurer',1,'approved',NULL,NULL),
        (8,'maintenance','Maintenance Staff','maintenance@example.invalid','','fixture',NULL,'maintenance',1,'approved',NULL,NULL)");
    $db->query("UPDATE users SET staff_id=CONCAT('STAFF-',LPAD(id,5,'0')) WHERE role<>'resident'");
    $db->query('DROP TABLE digital_id_credentials'); ensureDigitalIdSchema($db); ensureDigitalIdSchema($db);
    idCheck($db->query("SHOW TABLES LIKE 'digital_id_credentials'")->num_rows===1,'additive migration is idempotent');
    foreach ([1=>'Unit Owner',2=>'Tenant',4=>'Admin',5=>'Security Personnel',6=>'Super Admin',7=>'Treasurer',8=>'Maintenance'] as $actor=>$role) {
        idActor($db,$actor); $issued=digitalIdIssue($db); $token=idToken($issued);
        idCheck($issued['profile']['role']===$role,'actual role label '.$role);
        idCheck($issued['ttl']===60 && $issued['expires_at']-time()<=60 && $issued['expires_at']-time()>=59,'server TTL '.$role);
        idCheck((bool)preg_match('/^[a-f0-9]{64}$/D',$token) && !str_contains($issued['qr_url'],'user_id') && parse_url($issued['qr_url'],PHP_URL_QUERY)===null,'opaque fragment credential '.$role);
        $record=$db->query('SELECT * FROM digital_id_credentials ORDER BY id DESC LIMIT 1')->fetch_assoc();
        idCheck($record['token_hash']===hash('sha256',$token) && !str_contains(json_encode($record),$token),'only hash stored '.$role);
        $_SESSION=[]; $public=digitalIdVerify($db,$token,true);
        idCheck($public['valid'] && !isset($public['profile'],$public['subject_name'],$public['unit_number'],$public['reference_id']),'anonymous verification cannot reveal identity '.$role);
        idActor($db,5); $verified=digitalIdVerify($db,$token,true);
        idCheck($verified['valid'] && $verified['profile']['role']===$role && $verified['subject_name']===$issued['profile']['name'],'authorized identity '.$role);
    }
    idActor($db,1); $first=digitalIdIssue($db); $second=digitalIdIssue($db);
    idCheck(idToken($first)!==idToken($second) && digitalIdVerify($db,idToken($first))['verification_result']==='revoked','renewal revokes old screenshot');
    digitalIdRevoke($db); idCheck(digitalIdVerify($db,idToken($second))['verification_result']==='revoked','explicit revocation rejects active screenshot');
    $current=digitalIdIssue($db); idCheck(digitalIdVerify($db,idToken($current))['valid'],'reopening regenerates revoked credential');
    $db->query('UPDATE digital_id_credentials SET expires_at=UTC_TIMESTAMP() WHERE user_id=1');
    idCheck(digitalIdVerify($db,idToken($current))['verification_result']==='expired','expiry boundary is rejected, not accepted');
    idCheck(!digitalIdVerify($db,str_repeat('0',64))['valid'] && !digitalIdVerify($db,'bad-token')['valid'],'unknown and malformed tokens fail closed');
    idCheck(!digitalIdVerify($db,"' OR 1=1 --")['valid'],'SQL-like credentials fail closed');
    idActor($db,3); idDenied(fn()=>digitalIdIssue($db),'pending applicant cannot issue');
    idActor($db,2); $tenant=digitalIdIssue($db); $db->query("UPDATE users SET is_active=0 WHERE id=1");
    idCheck(digitalIdVerify($db,idToken($tenant))['verification_result']==='unauthorized','tenant owner relationship rechecked');
    $db->query('UPDATE users SET is_active=1,session_version=session_version+1 WHERE id=1');
    idCheck(digitalIdVerify($db,idToken($tenant))['verification_result']==='revoked','owner session revocation invalidates tenant screenshot after reactivation');
    idActor($db,2); $tenant=digitalIdIssue($db); putenv('CONDO_TENANT_PARKING=0');
    idCheck(digitalIdVerify($db,idToken($tenant))['verification_result']==='revoked','current tenant permissions checked'); putenv('CONDO_TENANT_PARKING=1');
    idActor($db,4); $staff=digitalIdIssue($db); $db->query('UPDATE users SET is_active=0 WHERE id=4');
    idCheck(digitalIdVerify($db,idToken($staff))['verification_result']==='suspended','disabled staff rejected');
    $db->query("UPDATE users SET is_active=1,role='security',session_version=session_version+1 WHERE id=4");
    idCheck(digitalIdVerify($db,idToken($staff))['verification_result']==='revoked','role and session changes revoke old credential');
    idActor($db,4); $staff=digitalIdIssue($db); $db->query('UPDATE users SET is_verified=0 WHERE id=4');
    idCheck(digitalIdVerify($db,idToken($staff))['verification_result']==='unauthorized','unverified account rejected');
    $db->query("UPDATE users SET is_verified=1,role='admin' WHERE id=4");
    idActor($db,1); $owner=digitalIdIssue($db); $db->query("UPDATE users SET account_type='unknown imported type' WHERE id=1");
    idCheck(digitalIdVerify($db,idToken($owner))['verification_result']==='unauthorized','unknown account type is not assumed owner');
    idDenied(fn()=>digitalIdIssue($db),'unknown type cannot issue'); $db->query("UPDATE users SET account_type='Resident Owner' WHERE id=1");
    idActor($db,1); $owner=digitalIdIssue($db); idActor($db,2);
    idCheck(!isset(digitalIdVerify($db,idToken($owner),true)['profile']),'tenant cannot request another person identity details');
    idActor($db,4); $db->query("UPDATE users SET status='rejected' WHERE id=4");
    idCheck(!isset(digitalIdVerify($db,idToken($owner),true)['profile']),'unapproved reviewing staff cannot see identity');
    idCheck(!verifyAccessScan($db,$owner['qr_url'])['valid'],'unapproved reviewing staff cannot use identity scanner');
    $db->query("UPDATE users SET status='approved' WHERE id=4");
    idActor($db,5); $before=(int)$db->query('SELECT COUNT(*) n FROM visitor_logs')->fetch_assoc()['n'];
    $request='11111111-1111-4111-8111-111111111111'; $scan=recordQrVerification($db,$owner['qr_url'],$request);
    idCheck($scan['verification']['valid'] && $scan['verification']['qr_type']==='digital_id','existing scanner recognizes explicit Digital ID type');
    $again=recordQrVerification($db,$owner['qr_url'],$request);
    idCheck($again['duplicate'] && $again['id']===$scan['id'],'scan submission deduplicates');
    $row=$db->query('SELECT * FROM qr_scan_logs WHERE id='.$scan['id'])->fetch_assoc();
    idCheck(!str_contains(json_encode($row),idToken($owner)) && !str_contains($row['verification_json'],'pass_url'),'audit snapshot contains no raw credential');
    idCheck((int)$db->query('SELECT COUNT(*) n FROM visitor_logs')->fetch_assoc()['n']===$before && $row['scan_action']==='verification','identity verification never records physical entry');
    idCheck(qrHistoryPage($db,qrHistoryFilters(['qr_type'=>'digital_id']))['total']===1,'existing scan history filters Digital IDs');
    idCheck(!verifyAccessScan($db,str_replace('127.0.0.1','external.invalid',$owner['qr_url']))['valid'],'external verification host rejected');
    idActor($db,1); digitalIdRevoke($db); idActor($db,5);
    idCheck(!recordQrVerification($db,$owner['qr_url'],$request)['verification']['valid'],'same-request replay rechecks revocation');

    $log=tempnam(sys_get_temp_dir(),'condo-id-http-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/ui_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__)); fclose($pipes[0]);
    for($attempt=0;$attempt<50;$attempt++) { if(@file_get_contents($base.'__manifest')!==false)break; usleep(100000); }
    $page=idHttp('digital_id_verify.php?fixture_actor=1'); preg_match('/data-csrf="([^"]+)"/',$page['body'],$match); $csrf=$match[1];
    $before=(int)$db->query('SELECT COUNT(*) n FROM digital_id_credentials')->fetch_assoc()['n'];
    idHttp('resident/dashboard.php?fixture_actor=1'); idCheck((int)$db->query('SELECT COUNT(*) n FROM digital_id_credentials')->fetch_assoc()['n']===$before,'ordinary page load never generates QR');
    idCheck(idHttp('api/digital_id.php')['status']===405,'issuance requires POST');
    idCheck(idHttp('api/digital_id.php',['action'=>'issue','csrf_token'=>'bad'])['status']===403,'issuance requires CSRF');
    $issue=idHttp('api/digital_id.php',['action'=>'issue','csrf_token'=>$csrf,'user_id'=>5]);
    idCheck($issue['status']===200 && $issue['json']['profile']['role']==='Unit Owner','cannot select another account to issue');
    $token=idToken($issue['json']);
    idCheck(str_contains($issue['headers'],'no-store') && str_contains($issue['headers'],'no-referrer'),'issuance response forbids caching/referrers');
    idHttp('digital_id_verify.php?fixture_actor=0');
    idCheck(idHttp('api/digital_id.php',['action'=>'issue','csrf_token'=>$csrf])['status']===401,'anonymous issuance rejected');
    $public=idHttp('api/digital_id_verify.php',['credential'=>$token]);
    idCheck($public['status']===200 && $public['json']['verification']['valid'] && !str_contains($public['body'],'Owner One') && !isset($public['json']['verification']['profile']),'public HTTP result has no identity');
    idCheck(str_contains($public['headers'],'no-store') && str_contains($public['headers'],'no-cache'),'verification cannot be cached');
    idCheck(idHttp('api/digital_id_verify.php?credential='.$token)['status']===405,'verification never accepts URL-query credentials');
    $page=idHttp('digital_id_verify.php?fixture_actor=5'); preg_match('/data-csrf="([^"]+)"/',$page['body'],$match); $staffCsrf=$match[1];
    idCheck(idHttp('api/digital_id_verify.php',['credential'=>$token,'request_id'=>$request,'csrf_token'=>'bad'])['status']===403,'private verification requires CSRF');
    $private=idHttp('api/digital_id_verify.php',['credential'=>$token,'request_id'=>'22222222-2222-4222-8222-222222222222','csrf_token'=>$staffCsrf]);
    idCheck($private['status']===200 && $private['json']['verification']['profile']['name']==='Owner One','authorized HTTP verification returns identity');
    idActor($db,1); digitalIdRevoke($db);
    idCheck(idHttp('api/digital_id_verify.php',['credential'=>$token,'request_id'=>'33333333-3333-4333-8333-333333333333','csrf_token'=>$staffCsrf])['json']['verification']['verification_result']==='revoked','HTTP screenshot rejected after revocation');
    $bucket=hash('sha256','digital-id-verification|127.0.0.1'); $db->query("UPDATE authentication_limits SET attempts=600 WHERE bucket='$bucket'");
    idCheck(idHttp('api/digital_id_verify.php',['credential'=>$token,'csrf_token'=>$staffCsrf])['status']===429,'verification rate limit enforced');
    echo 'Passed '.$checks." Digital Condo ID database/security/HTTP checks.\n";
} finally {
    if(is_resource($http)) { proc_terminate($http); proc_close($http); }
    if($log && is_file($log)) unlink($log);
    if(!preg_match('/^condo_ui_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture cleanup.');
    $server->query("DROP DATABASE `$database`");
}
