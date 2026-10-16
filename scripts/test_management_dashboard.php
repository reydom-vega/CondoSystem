<?php
/** Fictional operations in a disposable database. No providers or production changes. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database = 'condo_management_test_'.bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1'] as $key=>$value) putenv($key.'='.$value);
$_SERVER['REQUEST_METHOD']='GET';
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/management_dashboard.php';
require_once dirname(__DIR__).'/includes/management_dashboard_content.php';
$checks=0; $now=new DateTimeImmutable('2026-10-15 12:00:00',new DateTimeZone('Asia/Manila'));
function mdCheck(bool $ok,string $label): void { global $checks; if(!$ok)throw new RuntimeException('FAILED: '.$label); $checks++; }
function mdActor(mysqli $db,int $id): void { $row=$db->query('SELECT role,session_version FROM users WHERE id='.$id)->fetch_assoc(); $_SESSION=['user_id'=>$id,'role'=>$row['role'],'session_version'=>(int)$row['session_version'],'last_activity'=>time()]; }
function mdInsert(mysqli $db,string $table,array $row): int {
    $stmt=$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')');
    $values=array_values($row);$stmt->bind_param(str_repeat('s',count($values)),...$values);$stmt->execute();return (int)$db->insert_id;
}
function mdDenied(callable $callback,string $label): void { try{$callback();}catch(DomainException $error){mdCheck($error->getCode()===403,$label);return;}throw new RuntimeException('FAILED: '.$label); }
function mdQueue(array $data,string $key): ?int { foreach($data['queues']as$row)if($row['key']===$key)return $row['count'];return null; }
function mdHash(mysqli $db): string { $data=[];foreach($db->query('SHOW TABLES')as$table){$name=array_values($table)[0];$rows=$db->query('SELECT * FROM `'.$name.'`')->fetch_all(MYSQLI_ASSOC);$data[$name]=$rows;}return hash('sha256',json_encode($data)); }
try {
    $server->select_db($database);$server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do{$result=$server->store_result();if($result)$result->free();}while($server->more_results()&&$server->next_result());$db=connectDb();
    foreach([1=>'admin',2=>'superadmin']as$id=>$role)mdInsert($db,'users',['id'=>$id,'username'=>$role,'full_name'=>ucfirst($role),'email'=>$role.'@example.invalid','password_hash'=>'fixture','role'=>$role,'is_verified'=>1,'status'=>'pending']);
    mdActor($db,1);$empty=managementDashboardData($db,1,$now);
    mdCheck($empty['pending_total']===0&&$empty['maintenance']['active']===0&&$empty['messages']['conversations']===0,'Empty queues have explicit numeric zeros');
    mdCheck($empty['bookings']===[]&&$empty['residents']['rows']===[],'Empty panels are honest');
    mdCheck(!isset($empty['finance'])&&!isset($empty['failed_notifications']),'Admin cannot read financial or notification-delivery data');
    ob_start();renderManagementDashboardContent($empty);$html=ob_get_clean();mdCheck(str_contains($html,'No requests are currently waiting')&&!str_contains($html,'data-management-finance'),'Empty Admin view has useful feedback and no finance');
    mdActor($db,2);$superEmpty=managementDashboardData($db,2,$now);mdCheck($superEmpty['finance']['collected_cents']===0&&$superEmpty['finance']['past_due_cents']===0,'Empty Super Admin finance has explicit zeros');
    $inventory=loadUnitInventory();$unit=$inventory[0]['unit_number'];$otherUnit=$inventory[1]['unit_number'];
    foreach([3=>['approved',1,1,$unit],4=>['approved',1,1,ltrim($unit,'0')],5=>['approved',1,1,$otherUnit],6=>['pending',1,1,$inventory[2]['unit_number']],7=>['approved',0,1,$inventory[3]['unit_number']],8=>['approved',1,0,$inventory[4]['unit_number']],9=>['approved',1,1,'UNKNOWN-UNIT']]as$id=>[$status,$active,$verified,$number]) {
        mdInsert($db,'users',['id'=>$id,'username'=>'resident'.$id,'full_name'=>$id===3?'Resident <script>private()</script>':'Resident '.$id,'email'=>'resident'.$id.'@example.invalid','password_hash'=>'fixture','role'=>'resident','status'=>$status,'is_active'=>$active,'is_verified'=>$verified,'unit_number'=>$number,'account_type'=>$id===4?'Tenant':'Resident Owner']);
    }
    foreach([10=>'security',11=>'treasurer',12=>'maintenance']as$id=>$role)mdInsert($db,'users',['id'=>$id,'username'=>$role,'full_name'=>$role,'email'=>$role.'@example.invalid','password_hash'=>'fixture','role'=>$role,'is_verified'=>1,'status'=>'pending']);
    foreach([['pending','normal'],['reopened','normal'],['in_progress','urgent'],['completed','urgent'],['closed','urgent'],['rejected','urgent']]as[$status,$urgency])mdInsert($db,'maintenance_requests',['user_id'=>3,'issue_type'=>'Maintenance '.$status,'description'=>'PRIVATE-MAINTENANCE-NARRATIVE','location'=>'Lobby','status'=>$status,'urgency'=>$urgency]);
    foreach([['pending','2026-10-15'],['pending','2026-10-14'],['approved','2026-10-15'],['confirmed','2026-10-21'],['approved','2026-10-22'],['approved','2026-10-14'],['cancelled','2026-10-16']]as[$status,$day])mdInsert($db,'bookings',['user_id'=>3,'amenity'=>'Swimming Pool','booking_date'=>$day,'booking_time'=>'09:00','end_time'=>'10:00','status'=>$status]);
    foreach([['visitor','pending'],['visitor','approved'],['permit','pending'],['permit','changes_requested'],['permit','rejected']]as[$kind,$status])mdInsert($db,'resident_service_requests',['user_id'=>3,'request_kind'=>$kind,'details'=>'PRIVATE-PASS-DETAILS','start_date'=>'2026-10-15','end_date'=>'2026-10-15','status'=>$status,'access_token'=>bin2hex(random_bytes(32))]);
    $parking=mdInsert($db,'parking_requests',['user_id'=>3,'vehicle_plate'=>'APPROVED','start_date'=>'2026-10-15','status'=>'approved']);
    mdInsert($db,'parking_requests',['user_id'=>3,'vehicle_plate'=>'PENDING','start_date'=>'2026-10-15','status'=>'pending']);
    mdInsert($db,'parking_requests',['user_id'=>3,'vehicle_plate'=>'RESIDENT','start_date'=>'2026-10-15','status'=>'pending','request_type'=>'resident_assignment']);
    mdInsert($db,'parking_extensions',['parking_request_id'=>$parking,'requested_by'=>3,'expected_start_utc'=>'2026-10-14 16:00:00','requested_until'=>'2026-10-16 16:00:00','reason'=>'PRIVATE-EXTENSION-REASON','status'=>'pending','request_key'=>bin2hex(random_bytes(32)),'created_at'=>'2026-10-14 16:00:00']);
    foreach(['pending','rejected']as$status)mdInsert($db,'vehicles',['user_id'=>3,'make'=>'Toyota','model'=>'Vios','color'=>'Silver','year'=>2022,'plate_number'=>'CAR-'.$status,'normalized_plate'=>'CAR'.$status,'or_cr_path'=>'private-fixture.pdf','or_cr_mime'=>'application/pdf','status'=>$status]);
    foreach([['pending','warning_issued'],['approved','disputed'],['legacy','disputed'],['approved','unpaid'],['rejected','disputed'],['approved','waived']]as[$review,$status])mdInsert($db,'violations',['user_id'=>3,'violation_type'=>'Parking','description'=>'PRIVATE-VIOLATION-NARRATIVE','penalty_type'=>'warning','status'=>$status,'review_status'=>$review]);
    foreach([[3,'resident',0],[3,'resident',0],[4,'resident',0],[4,'resident',1],[3,'admin',0]]as[$user,$sender,$read])mdInsert($db,'messages',['user_id'=>$user,'sender_role'=>$sender,'body'=>'PRIVATE-MESSAGE-BODY','is_read'=>$read]);
    foreach([[3,'checked_in',null],[2,'checked_in',null],[7,'checked_out','2026-10-15 11:00:00']]as[$people,$status,$out])mdInsert($db,'visitor_logs',['visitor_name'=>'Private visitor','visitor_count'=>$people,'unit_number'=>$unit,'logged_by'=>10,'status'=>$status,'time_out'=>$out]);
    foreach([[1,null],[1,'2026-10-15 11:00:00'],[0,null]]as[$active,$expires])mdInsert($db,'announcements',['admin_id'=>2,'title'=>'Notice','content'=>'Existing announcement','is_active'=>$active,'expires_at'=>$expires]);
    foreach([['100.01','paid','2026-10-01 00:00:00','2026-10-15'],['200.02','paid','2026-10-15 10:00:00','2026-10-15'],['999.00','paid','2026-09-30 23:59:59','2026-09-30'],['111.00','paid','2026-11-01 00:00:00','2026-11-01'],['888.88','paid',null,'2026-10-15'],['10.10','pending',null,'2026-10-14'],['20.20','overdue',null,'2026-10-01'],['30.30','pending',null,'2026-10-15'],['40.40','pending',null,'2026-11-01'],['9999.00','rolled_forward',null,'2026-10-01'],['9999.00','rejected',null,'2026-10-01']]as[$amount,$status,$paid,$due])mdInsert($db,'payments',['user_id'=>3,'amount'=>$amount,'payment_method'=>'cash','status'=>$status,'paid_at'=>$paid,'due_date'=>$due,'created_at'=>'2026-08-01 00:00:00']);
    $stickerBill=mdInsert($db,'payments',['user_id'=>3,'amount'=>'1000.00','payment_method'=>'cash','status'=>'paid','paid_at'=>'2026-09-15 10:00:00','due_date'=>'2026-09-15']);
    foreach([['pending','1000.00','not_submitted'],['cancelled','1000.00','pending'],['paid','1000.00','issued'],['paid','777.00','pending']]as[$status,$amount,$claim])mdInsert($db,'parking_sticker_orders',['user_id'=>3,'quantity'=>1,'amount'=>$amount,'status'=>$status,'claim_status'=>$claim,'bill_payment_id'=>$stickerBill]);
    foreach([[1,'submitted'],[2,'submitted'],[10,'submitted'],[12,'reviewed']]as[$user,$status])mdInsert($db,'job_reports',['reference'=>'JR-FIXTURE-'.$user,'user_id'=>$user,'staff_id'=>'STAFF-'.$user,'staff_name'=>'Staff '.$user,'staff_role'=>$user===2?'superadmin':'admin','report_type'=>'daily','period_start'=>'2026-10-14','period_end'=>'2026-10-15','version'=>1,'request_key'=>bin2hex(random_bytes(32)),'request_digest'=>bin2hex(random_bytes(32)),'snapshot'=>'{"notes":"PRIVATE-FILED-NOTES"}','snapshot_hash'=>hash('sha256','{"notes":"PRIVATE-FILED-NOTES"}'),'status'=>$status,'filed_at'=>'2026-10-15 01:00:00']);
    mdInsert($db,'notification_outbox',['deduplication_key'=>bin2hex(random_bytes(32)),'channel'=>'email','recipient'=>'recipient@example.invalid','subject'=>'PRIVATE-NOTIFICATION-SUBJECT','body'=>'PRIVATE-NOTIFICATION-BODY','status'=>'failed']);
    $before=mdHash($db);mdActor($db,1);$admin=managementDashboardData($db,1,$now);mdActor($db,2);$super=managementDashboardData($db,2,$now);
    mdCheck(mdHash($db)===$before,'Dashboard snapshots do not change records or schema');
    foreach(['maintenance'=>2,'bookings'=>2,'visitors'=>1,'permits'=>1,'parking'=>2,'extensions'=>1,'vehicles'=>1,'stickers'=>1,'violations'=>3]as$key=>$count)mdCheck(mdQueue($admin,$key)===$count,'Accurate action queue '.$key);
    mdCheck($admin['pending_total']===14&&$super['pending_total']===17,'Review task totals match authorized queues');
    mdCheck(mdQueue($admin,'accounts')===null&&mdQueue($admin,'staff_reports')===null,'Privileged queues excluded for Admin');
    mdCheck(mdQueue($super,'accounts')===1,'Only active verified pending applications need account approval');
    mdCheck(mdQueue($super,'staff_reports')===2,'Super Admin own filed report excluded from independent review queue');
    mdCheck($admin['maintenance']['active']===3&&$admin['maintenance']['urgent']===1,'Only active maintenance contributes to active and urgent totals');
    mdCheck($admin['maintenance']['rows'][0]['urgency']==='urgent','Urgent maintenance appears first');
    mdCheck($admin['messages']['messages']===3&&$admin['messages']['conversations']===2,'Conversations are deduplicated while unread message total stays exact');
    mdCheck($admin['units']['occupied']===2&&$admin['units']['total']===count($inventory),'Occupancy uses distinct inventory units and approved active verified accounts');
    mdCheck($admin['units']['unassigned']===$admin['units']['total']-2&&$admin['units']['percent']===round(2/count($inventory)*100,1),'Inventory complement and non-integer percentage are accurate');
    mdCheck($admin['residents']['total']===6&&$admin['residents']['approved']===4,'Active resident accounts exclude disabled identities and remain distinct from occupied units');
    mdCheck(count($admin['bookings'])===2&&array_column($admin['bookings'],'status')===['approved','confirmed'],'Upcoming bookings include today through sixth day, excluding pending/cancelled/history');
    mdCheck($admin['visitors']['inside']===5&&$admin['visitors']['visits']===2,'Visitor groups counted as people rather than registrations');
    mdCheck($admin['announcements']===1,'Expired and inactive announcements excluded');
    mdCheck($admin['unresolved_violations']===3,'Open violations use real unpaid/disputed states and approved review status');
    mdCheck(!isset($admin['finance'])&&!isset($admin['failed_notifications']),'Populated Admin snapshot still excludes privileged information');
    mdCheck($super['finance']['collected_cents']===30003,'Collections use paid_at month with exact cents, not bill creation date');
    mdCheck($super['finance']['outstanding_cents']===10100,'Outstanding excludes rejected and rolled-forward bills');
    mdCheck($super['finance']['past_due_cents']===3030&&$super['finance']['past_due_count']===2,'Past due includes pending bills already past due and excludes today');
    mdCheck($super['finance']['undated_paid_count']===1&&$super['failed_notifications']===1,'Missing paid dates and failed deliveries are explicit');
    ob_start();renderManagementDashboardContent($super);$html=ob_get_clean();
    foreach(['PRIVATE-MAINTENANCE-NARRATIVE','PRIVATE-PASS-DETAILS','PRIVATE-EXTENSION-REASON','PRIVATE-VIOLATION-NARRATIVE','PRIVATE-MESSAGE-BODY','PRIVATE-FILED-NOTES','PRIVATE-NOTIFICATION-BODY']as$secret)mdCheck(!str_contains(json_encode($super).$html,$secret),'Sensitive content stays out of overview: '.$secret);
    mdCheck(str_contains($html,'&lt;script&gt;private()&lt;/script&gt;')&&!str_contains($html,'<script>private()'),'User-provided names escaped in shared renderer');
    foreach([3,10,11,12]as$id){mdActor($db,$id);mdDenied(fn()=>managementDashboardData($db,$id,$now),'Non-management account denied '.$id);}
    mdActor($db,1);$_SESSION['role']='superadmin';$spoof=managementDashboardData($db,1,$now);mdCheck($spoof['role']==='admin'&&!isset($spoof['finance']),'Existing authentication restores trusted role without exposing privileged data');
    mdActor($db,1);$_SESSION['session_version']++;mdDenied(fn()=>managementDashboardData($db,1,$now),'Revoked session denied');
    mdActor($db,1);mdDenied(fn()=>managementDashboardData($db,2,$now),'Different actor ID denied');
    mdActor($db,1);$db->query('UPDATE users SET is_active=0 WHERE id=1');mdDenied(fn()=>managementDashboardData($db,1,$now),'Disabled staff denied');$db->query('UPDATE users SET is_active=1,is_verified=0 WHERE id=1');mdDenied(fn()=>managementDashboardData($db,1,$now),'Unverified staff denied');
    $_SESSION=[];mdDenied(fn()=>managementDashboardData($db,2,$now),'Guest denied');
    echo 'Passed '.$checks.' management dashboard counts, money, privacy, role and read-only checks. No provider contacted.'.PHP_EOL;
} finally { if(!preg_match('/^condo_management_test_[a-f0-9]{12}$/D',$database))throw new RuntimeException('Unsafe fixture cleanup.');$server->query("DROP DATABASE `$database`"); }
