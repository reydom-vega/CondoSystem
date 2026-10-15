<?php
/** Disposable records only; no provider calls or changes to application data. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/includes/environment.php';mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$server=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database='condo_security_test_'.bin2hex(random_bytes(6));$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
foreach(['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1']as$key=>$value)putenv($key.'='.$value);
$_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/CondoSystem3/scripts/test_security_dashboard.php';$_SERVER['HTTP_HOST']='localhost';
require dirname(__DIR__).'/config.php';require dirname(__DIR__).'/includes/security_dashboard.php';$checks=0;
function sc(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$message);$checks++;}
function sdActor(mysqli $db,int $id):void{$r=$db->query('SELECT role,session_version FROM users WHERE id='.$id)->fetch_assoc();$_SESSION=['user_id'=>$id,'role'=>$r['role'],'session_version'=>(int)$r['session_version'],'last_activity'=>time()];}
function sdDenied(callable $action,string $label):void{try{$action();}catch(DomainException $error){sc(true,$label);return;}throw new RuntimeException('FAILED: '.$label);}
try{
    $server->select_db($database);$server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));do{$r=$server->store_result();if($r)$r->free();}while($server->more_results()&&$server->next_result());$db=connectDb();
    $db->query("INSERT INTO users(id,username,full_name,email,password_hash,role,status,is_verified,is_active,unit_number,account_type) VALUES(1,'owner','Owner','owner@example.invalid','fixture','resident','approved',1,1,'0101','Resident Owner'),(4,'admin','Admin','admin@example.invalid','fixture','admin','approved',1,1,NULL,NULL),(5,'guard','Guard','guard@example.invalid','fixture','security','approved',1,1,NULL,NULL),(6,'super','Super','super@example.invalid','fixture','superadmin','approved',1,1,NULL,NULL),(9,'otherguard','Other Guard','otherguard@example.invalid','fixture','security','approved',1,1,NULL,NULL)");
    sdActor($db,5);$empty=securityDashboardData($db,5);sc($empty['visitors_inside']===0&&$empty['qr_checks_today']===0&&$empty['pending_reports']===0,'Valid empty counters');sc(!$empty['recent_visitors']&&!$empty['recent_reports'],'Useful empty lists');
    $db->query("UPDATE users SET status='pending' WHERE id=5");
    $password='SecurityFixture@123';$hash=password_hash($password,PASSWORD_DEFAULT);
    $credential=$db->prepare('UPDATE users SET password_hash=? WHERE id=5');$credential->bind_param('s',$hash);$credential->execute();
    $signedIn=authenticateCredentials($db,'guard',$password);
    sc($signedIn && (int)$signedIn['id']===5 && $signedIn['role']==='security','Legacy active verified Security account can authenticate with its password');
    sc(securityDashboardData($db,5)['visitors_inside']===0,'Active verified legacy staff can open dashboard with default resident approval status');
    foreach(['vehicles.read','visitors.review','visitors.identity.review','parking.review']as$cap)sc(!canAccess($cap),'Removed security management capability '.$cap);
    sc(canAccess('vehicles.lookup')&&canAccess('parking.gate')&&canAccess('security.gate'),'Gate and incident lookup preserved');
    foreach(['Registered Vehicles','Visitor Registrations','Parking & Stickers']as$label)sc(!in_array($label,array_column(staffSidebarLinks(),2),true),'Removed sidebar '.$label);
    $db->query("INSERT INTO visitor_logs(visitor_name,visitor_count,unit_number,logged_by,time_in,time_out,status) VALUES('Active party',3,'0101',5,NOW(),NULL,'checked_in'),('Departed party',2,'0101',5,NOW()-INTERVAL 2 HOUR,NOW(),'checked_out'),('Inconsistent old status',1,'0101',5,NOW()-INTERVAL 1 HOUR,NOW(),'checked_in')");
    $db->query("INSERT INTO violations(user_id,violation_type,description,issued_by,review_status,status) VALUES(NULL,'My pending incident','PRIVATE-NARRATIVE',5,'pending',NULL),(1,'My confirmed incident','PRIVATE-NARRATIVE',5,'approved','unpaid'),(NULL,'OTHER-GUARD-SECRET','OTHER-PRIVATE-NARRATIVE',9,'pending',NULL)");
    $start=(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'));$stmt=$db->prepare("INSERT INTO qr_scan_logs(user_id,username,scanned_content,scan_action,event_time_utc) VALUES(?,'Fixture','PRIVATE-QR-TOKEN',?,?)");
    foreach([[5,'verification',$start],[5,'verification',$start->modify('+1 day')->modify('-1 second')],[5,'verification',$start->modify('-1 second')],[5,'verification',$start->modify('+1 day')],[9,'verification',$start],[5,'check_in',$start]]as[$id,$action,$time]){$at=$time->format('Y-m-d H:i:s');$stmt->bind_param('iss',$id,$action,$at);$stmt->execute();}
    $before=[];foreach(['visitor_logs','violations','qr_scan_logs']as$t)$before[$t]=$db->query("SELECT * FROM `$t` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $data=securityDashboardData($db,5);sc($data['visitors_inside']===3&&$data['visits_inside']===1,'Actual people and active party count distinct');sc($data['pending_reports']===1,'Only own reports awaiting review');sc($data['qr_checks_today']===2,'Own verification checks use Manila day, inclusive/exclusive UTC bounds');sc(count($data['recent_reports'])===2,'Other guards reports excluded');
    foreach(['PRIVATE-NARRATIVE','OTHER-GUARD-SECRET','OTHER-PRIVATE-NARRATIVE','PRIVATE-QR-TOKEN','scanned_content']as$secret)sc(!str_contains(json_encode($data),$secret),'Restricted data absent '.$secret);
    foreach($before as$t=>$rows)sc($rows===$db->query("SELECT * FROM `$t` ORDER BY id")->fetch_all(MYSQLI_ASSOC),'Read-only integrity '.$t);
    sdActor($db,9);sc(securityDashboardData($db,9)['pending_reports']===1,'Another guard sees their own report scope');
    foreach([1,4,6]as$id){sdActor($db,$id);sdDenied(fn()=>securityDashboardData($db,$id),'Other role denied '.$id);}$_SESSION=[];sdDenied(fn()=>securityDashboardData($db,5),'Guest denied');
    sdActor($db,5);sdDenied(fn()=>securityDashboardData($db,9),'Forged actor parameter denied');$db->query('UPDATE users SET is_verified=0 WHERE id=5');sdDenied(fn()=>securityDashboardData($db,5),'Unverified legacy guard denied');$db->query('UPDATE users SET is_verified=1,is_active=0 WHERE id=5');sdActor($db,5);sdDenied(fn()=>securityDashboardData($db,5),'Inactive guard denied');$db->query('UPDATE users SET is_active=1 WHERE id=5');sdActor($db,5);$db->query('UPDATE users SET session_version=session_version+1 WHERE id=5');sdDenied(fn()=>securityDashboardData($db,5),'Revoked session denied');
    echo 'Passed '.$checks.' Security dashboard scope/date/privacy/permission/read-only checks. No provider contacted.'.PHP_EOL;
}finally{if(preg_match('/^condo_security_test_[a-f0-9]{12}$/D',$database))$server->query("DROP DATABASE `$database`");}
