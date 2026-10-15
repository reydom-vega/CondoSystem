<?php
/** Disposable financial fixture only; no provider calls or production payment changes. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'), appSetting('CONDO_DB_USER','root'), appSetting('CONDO_DB_PASS'));
$database = 'condo_treasurer_test_'.bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1'] as $key=>$value) putenv($key.'='.$value);
$_SERVER['REQUEST_METHOD']='GET';
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/treasurer_dashboard.php';
$checks=0;
function tdCheck(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$label); $checks++; }
function tdActor(mysqli $db, int $id): void {
    $u=$db->query('SELECT id,role,username,session_version FROM users WHERE id='.$id)->fetch_assoc();
    $_SESSION=['user_id'=>$id,'role'=>$u['role'],'username'=>$u['username'],'session_version'=>(int)$u['session_version'],'last_activity'=>time()];
}
function tdDenied(callable $callback, string $label): void {
    try { $callback(); } catch (DomainException $error) { tdCheck($error->getCode()===403,$label); return; }
    throw new RuntimeException('FAILED: '.$label);
}
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $result=$server->store_result(); if($result)$result->free(); } while($server->more_results() && $server->next_result());
    $db=connectDb();
    $db->query("INSERT INTO users(id,username,full_name,email,contact_number,password_hash,role,is_verified,status,account_type,unit_number) VALUES
        (1,'treasurer','Treasurer','treasurer@example.invalid','','fixture','treasurer',1,'pending',NULL,NULL),
        (2,'owner','Owner One','owner@example.invalid','','fixture','resident',1,'approved','Resident Owner','0101'),
        (3,'tenant','Tenant Two','tenant@example.invalid','','fixture','resident',1,'approved','Tenant','0102'),
        (4,'admin','Admin','admin@example.invalid','','fixture','admin',1,'approved',NULL,NULL),
        (5,'security','Security','security@example.invalid','','fixture','security',1,'pending',NULL,NULL),
        (6,'maintenance','Maintenance','maintenance@example.invalid','','fixture','maintenance',1,'pending',NULL,NULL),
        (7,'superadmin','Super Admin','superadmin@example.invalid','','fixture','superadmin',1,'approved',NULL,NULL)");
    $insert=$db->prepare('INSERT INTO payments(user_id,amount,payment_method,status,due_date,paid_at,payment_channel) VALUES(?,?,?,?,?,?,?)');
    $records=[
        [2,'500.00','cash','paid','2026-08-01','2026-08-31 23:59:59','cash'],
        [2,'10.01','online','paid','2026-09-01','2026-09-01 00:00:00','gcash'],
        [3,'20.02','online','paid','2026-09-30','2026-09-30 23:59:59','maya'],
        [2,'30.03','cash','paid','2026-10-01','2026-10-01 00:00:00','cash'],
        [2,'99.99','cash','paid','2026-09-01',null,'cash'],
        [2,'1.00','cash','paid','2026-04-01','2026-04-15 12:00:00','cash'],
        [2,'1000.00','cash','paid','2026-03-01','2026-03-31 23:59:59','cash'],
        [2,'999.00','unbilled','rejected','2026-01-01',null,null],
        [2,'888.00','unbilled','rolled_forward','2026-01-01',null,null],
        [2,'100.10','unbilled','pending','2026-10-15',null,null],
        [3,'200.20','unbilled','pending','2026-10-21',null,null],
        [2,'300.30','unbilled','pending','2026-10-22',null,null],
        [2,'1.01','unbilled','pending','2026-10-14',null,null],
        [2,'2.02','unbilled','pending','2026-09-15',null,null],
        [3,'3.03','unbilled','overdue','2026-09-14',null,null],
        [2,'4.04','unbilled','pending','2026-08-16',null,null],
        [3,'5.05','unbilled','overdue','2026-08-15',null,null],
        [2,'0.00','unbilled','pending','2026-01-01',null,null],
    ];
    foreach($records as $record){$insert->bind_param('issssss',...$record);$insert->execute();}
    $now=new DateTimeImmutable('2026-10-15 12:00:00',new DateTimeZone('Asia/Manila'));
    tdActor($db,1);
    $before=$db->query('SELECT * FROM payments ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $d=treasurerDashboardData($db,1,'2026-09',$now);$t=$d['totals'];
    tdCheck($d['month_label']==='September 2026','selected month label');
    tdCheck($t['collected_cents']===3003&&$t['collected_count']===2,'payment-date month boundaries and exact cents');
    tdCheck($t['paid_cents']===166105&&$t['undated_paid_count']===1,'all-time collections include undated paid records once');
    tdCheck($t['outstanding_cents']===61575&&$t['outstanding_count']===9,'only open invoices in balances; reject rolled-forward duplication');
    tdCheck($t['past_due_cents']===1515&&$t['past_due_count']===6,'past-due dates detected even if stored status is pending');
    tdCheck($t['due_soon_cents']===30030&&$t['due_soon_count']===2,'today and day six included, day seven excluded');
    tdCheck(array_sum(array_column($d['aging'],'cents'))===$t['outstanding_cents'],'aging reconciles to open balance');
    tdCheck(array_column($d['aging'],'cents')===[60060,303,707,505],'aging day boundaries 1/30/31/60/61');
    tdCheck(count($d['trend'])===6&&$d['trend'][0]['month']==='Apr 2026'&&$d['trend'][5]['month']==='Sep 2026','six month trend ordered and zero-filled');
    tdCheck(array_column($d['trend'],'cents')===[100,0,0,0,50000,3003],'trend excludes older and undated collections');
    tdCheck(count($d['recent'])===6&&(int)$d['recent'][0]['id']===4,'recent completed payments ordered by payment date');
    tdCheck(count($d['follow_ups'])===6&&(int)$d['follow_ups'][0]['id']===17,'oldest positive follow-ups first, six row limit');
    tdCheck(!in_array(18,array_map('intval',array_column($d['follow_ups'],'id')),true),'zero amount invoices excluded from follow-ups');
    tdCheck(str_contains(json_encode($d['recent']),'Tenant Two'),'tenant payer retained');
    tdCheck(!str_contains(json_encode($d),'password')&&!str_contains(json_encode($d),'paymongo_payment_id'),'no credentials or gateway identifiers exposed');
    tdCheck($before===$db->query('SELECT * FROM payments ORDER BY id')->fetch_all(MYSQLI_ASSOC),'dashboard does not mutate billing records');
    tdCheck(treasurerDashboardMoney(3003)==='₱30.03','currency display preserves cents');
    tdCheck(treasurerDashboardData($db,1,null,$now)['month']==='2026-10','default uses Manila month');
    tdCheck(treasurerDashboardData($db,1,null,$now->setTimezone(new DateTimeZone('UTC')))['today']==='2026-10-15','clock normalized to Manila');
    tdCheck(treasurerDashboardData($db,1,'2026-01',$now)['trend'][0]['month']==='Aug 2025','trend crosses year boundary');
    foreach(['2026-13','2026-2',"2026-09' OR 1=1 --",''] as $month){
        try{treasurerDashboardData($db,1,$month,$now);throw new RuntimeException('Invalid month accepted');}
        catch(InvalidArgumentException $error){tdCheck(true,'invalid month rejected');}
    }
    foreach([2,3,4,5,6,7]as$id){tdActor($db,$id);tdDenied(fn()=>treasurerDashboardData($db,$id,'2026-09',$now),'non-treasurer denied '.$id);}
    tdDenied(fn()=>treasurerDashboardData($db,1,'2026-09',$now),'cannot select another actor');
    tdActor($db,1);$db->query('UPDATE users SET is_active=0 WHERE id=1');tdDenied(fn()=>treasurerDashboardData($db,1),'inactive treasurer denied');
    $db->query('UPDATE users SET is_active=1,is_verified=0 WHERE id=1');tdDenied(fn()=>treasurerDashboardData($db,1),'unverified treasurer denied');
    $db->query('UPDATE users SET is_verified=1,session_version=session_version+1 WHERE id=1');tdDenied(fn()=>treasurerDashboardData($db,1),'revoked session denied');
    tdActor($db,1);$db->query("UPDATE users SET role='maintenance' WHERE id=1");tdDenied(fn()=>treasurerDashboardData($db,1),'database role is rechecked');
    $db->query("UPDATE users SET role='treasurer' WHERE id=1");$_SESSION=[];tdDenied(fn()=>treasurerDashboardData($db,1),'anonymous denied');
    tdActor($db,1);$db->query('DELETE FROM payments');$empty=treasurerDashboardData($db,1,'2026-09',$now);
    tdCheck($empty['totals']['outstanding_cents']===0&&$empty['totals']['collected_cents']===0&&$empty['totals']['record_count']===0,'empty financial totals');
    tdCheck(!$empty['recent']&&!$empty['follow_ups']&&count($empty['aging'])===4&&count($empty['trend'])===6,'empty panels and zero-filled chart');
    echo 'Passed '.$checks.' Treasurer dashboard totals/date/aging/permission/read-only checks. No provider contacted.'.PHP_EOL;
} finally {
    if(preg_match('/^condo_treasurer_test_[a-f0-9]{12}$/D',$database))$server->query("DROP DATABASE `$database`");
}
