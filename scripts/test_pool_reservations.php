<?php
/** Real MySQL, concurrent approvals and loopback HTTP. No production data/provider calls. */
if(PHP_SAPI!=='cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if(($argv[1] ?? '')==='--approve') {
    if(!preg_match('/^condo_gate_test_[a-f0-9]{12}$/D',getenv('CONDO_DB_NAME') ?: '')) exit(1);
    $_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_pool_reservations.php'; $_SERVER['HTTP_HOST']='localhost';
    require_once __DIR__.'/../config.php';
    $_SESSION=['user_id'=>(int)$argv[3],'role'=>(int)$argv[3]===4?'admin':'superadmin','last_activity'=>time(),'session_version'=>0];
    $db=connectDb(); echo "READY\n"; flush();
    try { decideAmenityBooking($db,(int)$argv[2],'approved'); echo "APPROVED\n"; }
    catch(AmenityScheduleConflict $e) { echo "CONFLICT\n"; }
    exit;
}
$server=new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost',getenv('CONDO_DB_USER') ?: 'root',getenv('CONDO_DB_PASS') ?: '');
$testDb='condo_gate_test_'.bin2hex(random_bytes(6)); $server->query("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
putenv('CONDO_DB_NAME='.$testDb); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=1'); putenv('CONDO_TENANT_AMENITIES=1');
putenv('CONDO_PAYMONGO_SECRET_KEY=sk_test_fixture'); putenv('CONDO_PAYMONGO_WEBHOOK_SECRET=whsk_fixture');
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_pool_reservations.php'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/resident_accounts.php';
$checks=0; $http=null; $cookie=null; $httpLog=null;
function poolCheck(bool $value,string $name):void { global $checks; if(!$value) throw new RuntimeException('FAILED: '.$name); $checks++; }
function poolReject(callable $action,string $name,string $class=InvalidArgumentException::class):void { try{$action();}catch(Throwable $e){if($e instanceof $class){poolCheck(true,$name);return;}throw $e;}throw new RuntimeException('FAILED: '.$name); }
function poolActor(int $id):void { $_SESSION=['user_id'=>$id,'role'=>match($id){4=>'admin',5=>'security',6=>'superadmin',default=>'resident'},'username'=>'Fixture','last_activity'=>time(),'session_version'=>0]; }
function poolRow(mysqli $db,int $id):array { return $db->query('SELECT * FROM bookings WHERE id='.$id)->fetch_assoc(); }
function poolCreate(mysqli $db,int $actor,string $date,string $time,int $duration=1,int $guests=1,string $amenity='Swimming Pool'):int {
    poolActor($actor); createAmenityBooking($db,$actor,$amenity,$date,$time,$guests,$duration); return (int)$db->query('SELECT MAX(id) id FROM bookings')->fetch_assoc()['id'];
}
try {
    $server->select_db($testDb); $server->multi_query(file_get_contents(__DIR__.'/../database.sql')); do{$r=$server->store_result();if($r)$r->free();}while($server->more_results() && $server->next_result());
    $db=connectDb();
    $db->query("INSERT INTO users(id,username,full_name,email,contact_number,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES(1,'owner','Owner One','owner@example.invalid','','fixture','0101','resident',1,'approved','Resident Owner',NULL),(2,'tenant','Tenant Two','tenant@example.invalid','','fixture','0101','resident',1,'approved','Tenant',1),(3,'other','Other Owner','other@example.invalid','','fixture','0102','resident',1,'approved','Resident Owner',NULL),(4,'admin','Admin Full Name','admin@example.invalid','','fixture',NULL,'admin',1,'approved',NULL,NULL),(5,'guard','Guard Full Name','guard@example.invalid','','fixture',NULL,'security',1,'approved',NULL,NULL),(6,'super','Superadmin Full Name','super@example.invalid','','fixture',NULL,'superadmin',1,'approved',NULL,NULL)");
    $tomorrow=date('Y-m-d',strtotime('+1 day')); $next=date('Y-m-d',strtotime('+2 days')); $raceDate=date('Y-m-d',strtotime('+3 days'));
    foreach([1,2,3] as $hours) {
        $id=poolCreate($db,$hours===3?2:1,$tomorrow,sprintf('%02d:00',6+$hours),$hours,$hours*5);
        $row=poolRow($db,$id); poolCheck($row['hourly_rate']==='300.00' && $row['total_fee']===($hours*300).'.00' && (int)$row['duration_hours']===$hours,'hourly price snapshot '.$hours);
        poolCheck($row['unit_number']==='0101' && $row['status']==='pending' && $row['payment_id']===null,'authorized unit snapshot without premature bill '.$hours);
    }
    poolActor(1);
    foreach([0,4,-1] as $invalid) poolReject(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'12:00',1,$invalid),'duration rejects '.$invalid);
    poolReject(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'20:00',1,2),'closing time enforced');
    poolReject(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'05:00',1,1),'opening time enforced');
    poolReject(fn()=>createAmenityBooking($db,1,'Swimming Pool','2026-02-30','12:00',1),'strict date');
    poolReject(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'12:30',1),'hour boundary');
    poolReject(fn()=>createAmenityBooking($db,2,'Swimming Pool',$tomorrow,'12:00',1),'cannot create as another resident');
    poolReject(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'12:00',21),'attendee capacity');
    foreach(amenityCatalog() as $name=>$entry) if(!$entry['booking']) poolReject(fn()=>createAmenityBooking($db,1,$name,$tomorrow,'12:00',1),'view-only '.$name);
    $available=poolAvailability($db,$tomorrow,3); poolCheck(!array_filter($available['slots'],fn($s)=>$s['status']==='booked'),'pending pool requests do not block availability');
    $first=poolCreate($db,1,$next,'09:00',3,20); $overlap=poolCreate($db,2,$next,'08:00',2,1);
    poolActor(4); poolCheck(decideAmenityBooking($db,$first,'approved'),'admin approval'); $approved=poolRow($db,$first); $billId=(int)$approved['payment_id'];
    poolCheck($approved['status']==='approved' && (int)$approved['approved_by']===4 && $approved['approved_at']!==null,'approval snapshot');
    $bill=$db->query('SELECT * FROM payments WHERE id='.$billId)->fetch_assoc();
    poolCheck((int)$bill['user_id']===1 && $bill['amount']==='900.00' && $bill['status']==='pending' && $bill['billing_scope']==='amenity_reservation','exactly one unpaid unit-owner bill');
    $item=$db->query('SELECT * FROM bill_items WHERE payment_id='.$billId)->fetch_assoc(); poolCheck($item['category']==='Amenity Reservation' && str_contains($item['description'],'Booking #'.$first),'bill links original booking');
    poolCheck(decideAmenityBooking($db,$first,'approved') && (int)poolRow($db,$first)['payment_id']===$billId,'repeated approval idempotent');
    $before=(int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n'];
    poolReject(fn()=>decideAmenityBooking($db,$overlap,'approved'),'second overlapping approval rejected',AmenityScheduleConflict::class);
    poolCheck((int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n']===$before && poolRow($db,$overlap)['payment_id']===null,'failed approval produces no bill');
    $slots=poolAvailability($db,$next,2)['slots']; $byStart=array_column($slots,null,'start');
    poolCheck($byStart['08:00']['status']==='booked' && $byStart['11:00']['status']==='booked' && $byStart['12:00']['status']==='available','overlaps blocked and boundary adjacency available');
    poolCheck(!isset(array_column(poolAvailability($db,$next,3)['slots'],null,'start')['19:00']),'duration removes starts extending after closing');
    poolActor(2); poolReject(fn()=>decideAmenityBooking($db,$first,'cancelled'),'cannot cancel another user booking');
    poolActor(5); poolReject(fn()=>decideAmenityBooking($db,$overlap,'approved'),'security cannot approve');
    poolActor(4); poolReject(fn()=>decideAmenityBooking($db,$overlap,'confirmed'),'admin cannot mark pool paid');
    poolActor(6); poolCheck(!addBillItem($billId,'Other','Unauthorized addition',1),'reservation invoice total cannot be edited');
    // A failed bill insert must roll back both the reservation and the charge.
    $rollback=poolCreate($db,1,$next,'13:00',1); poolActor(4);
    $db->query("CREATE TRIGGER pool_bill_failure BEFORE INSERT ON bill_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected pool bill failure'");
    poolReject(fn()=>decideAmenityBooking($db,$rollback,'approved'),'billing failure rolls approval back',mysqli_sql_exception::class); $db->query('DROP TRIGGER pool_bill_failure');
    poolCheck(poolRow($db,$rollback)['status']==='pending' && poolRow($db,$rollback)['payment_id']===null && (int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n']===$before,'bill failure leaves no orphan charge');
    // Independent PHP/MySQL sessions contend for the same pool/date mutex.
    $race1=poolCreate($db,1,$raceDate,'09:00',3); $race2=poolCreate($db,2,$raceDate,'10:00',2);
    $lock='amenity:'.sha1($testDb.':Swimming Pool:'.$raceDate); $q=$db->prepare('SELECT GET_LOCK(?,10)'); $q->bind_param('s',$lock);$q->execute(); $q->get_result()->fetch_assoc();
    $workers=[];
    foreach([[$race1,4],[$race2,6]] as [$booking,$actor]) {
        $process=proc_open([PHP_BINARY,__FILE__,'--approve',(string)$booking,(string)$actor],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        poolCheck(is_resource($process) && trim(fgets($pipes[1]))==='READY','concurrent worker ready'); $workers[]=[$process,$pipes];
    }
    $q=$db->prepare('SELECT RELEASE_LOCK(?)'); $q->bind_param('s',$lock); $q->execute(); $q->get_result()->fetch_assoc(); $outcomes=[];
    foreach($workers as [$process,$pipes]) { $outcomes[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);poolCheck(proc_close($process)===0 && $errors==='','concurrent worker completed'); }
    sort($outcomes); poolCheck($outcomes===['APPROVED','CONFLICT'],'simultaneous overlapping approvals produce one winner');
    poolCheck((int)$db->query("SELECT COUNT(*) n FROM bookings WHERE booking_date='$raceDate' AND status='approved'")->fetch_assoc()['n']===1,'only one active reservation after concurrency');
    // Tenant booking belongs to the owner once, and payment confirmation stays trusted.
    $tenant=poolCreate($db,2,$next,'16:00',3,1); poolActor(4); decideAmenityBooking($db,$tenant,'approved'); $tenantBill=(int)poolRow($db,$tenant)['payment_id'];
    poolCheck((int)$db->query('SELECT user_id FROM payments WHERE id='.$tenantBill)->fetch_assoc()['user_id']===1,'tenant pool fee charged once to unit owner');
    poolCheck(residentCanPayBill($db,1,1,$tenantBill) && !residentCanPayBill($db,2,1,$tenantBill),'existing owner-only unit payment rules retained');
    $db->query("UPDATE payments SET paymongo_checkout_id='cs_pool_fixture',payment_method='online' WHERE id=$tenantBill");
    poolActor(2); poolReject(fn()=>decideAmenityBooking($db,$tenant,'cancelled'),'online checkout cancellation requires PMO');
    $context=['internal_payment_id'=>$tenantBill,'checkout_id'=>'cs_pool_fixture','paymongo_payment_id'=>'pay_pool_fixture','payment_channel'=>'gcash','amount'=>90000,'currency'=>'PHP','payment_status'=>'paid','event_type'=>'checkout_session.payment.paid','livemode'=>false];
    poolCheck(!confirmPaymongoCheckoutPayment($db,array_replace($context,['amount'=>1]))['confirmed'] && poolRow($db,$tenant)['status']==='approved','incorrect provider amount cannot confirm');
    poolCheck(confirmPaymongoCheckoutPayment($db,$context)['confirmed'] && poolRow($db,$tenant)['status']==='confirmed','verified provider payment confirms booking');
    poolCheck(!confirmPaymongoCheckoutPayment($db,$context)['confirmed'],'duplicate provider event idempotent');
    poolActor(2); poolReject(fn()=>decideAmenityBooking($db,$tenant,'cancelled'),'paid cancellation requires PMO');
    poolActor(4); poolReject(fn()=>decideAmenityBooking($db,$tenant,'cancelled','Reviewed',true),'admin without billing capability cannot perform PMO financial cancellation');
    poolActor(6); poolCheck(decideAmenityBooking($db,$tenant,'cancelled','PMO approved; refund reviewed separately',true),'authorized PMO cancellation after review');
    poolCheck($db->query('SELECT status FROM payments WHERE id='.$tenantBill)->fetch_assoc()['status']==='paid','PMO cancellation preserves paid financial history');
    poolCheck(array_column(poolAvailability($db,$next,3)['slots'],null,'start')['16:00']['status']==='available','reviewed cancellation releases schedule');
    poolActor(1); poolCheck(decideAmenityBooking($db,$first,'cancelled'),'unpaid cancellation permitted');
    poolCheck($db->query('SELECT status FROM payments WHERE id='.$billId)->fetch_assoc()['status']==='rejected','unpaid cancellation voids dedicated charge');
    poolActor(4); decideAmenityBooking($db,$rollback,'approved'); $cash=(int)poolRow($db,$rollback)['payment_id']; $db->query("UPDATE payments SET payment_method='cash' WHERE id=$cash");
    poolActor(6); poolCheck(confirmCashBillPayment($db,$cash) && poolRow($db,$rollback)['status']==='confirmed','authorized cash receipt confirms pool booking');
    $hall=poolCreate($db,1,$tomorrow,'08:00',1,50,'Function Hall'); poolActor(2);
    poolReject(fn()=>createAmenityBooking($db,2,'Function Hall',$tomorrow,'10:00',20),'Function Hall retains exclusive full-day pending hold');
    poolActor(4); poolCheck(decideAmenityBooking($db,$hall,'approved') && poolRow($db,$hall)['status']==='confirmed' && poolRow($db,$hall)['payment_id']===null,'Function Hall approval behavior retained without new automatic bill');
    // Completed historical periods remain occupied for their original hours.
    $historyDate=date('Y-m-d',strtotime('-1 day'));
    $db->query("INSERT INTO bookings(user_id,amenity,booking_date,booking_time,end_time,status,duration_hours) VALUES(1,'Swimming Pool','$historyDate','09:00','12:00','completed',3)");
    poolCheck(array_column(poolAvailability($db,$historyDate,2)['slots'],null,'start')['08:00']['status']==='booked','completed original hours are not reusable');
    foreach(amenityCatalog() as $name=>$entry) poolCheck(count(amenityImages($entry['slug']))===1,'existing local image found: '.$name);
    poolCheck(amenityImages('../config.php')===[],'catalogue image path allowlist');
    // Browser-facing authorization, CSRF, tampering and conflict response contracts.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); $address=stream_socket_get_name($socket,false); fclose($socket); $port=(int)substr(strrchr($address,':'),1);
    $base='http://127.0.0.1:'.$port.'/CondoSystem3/'; putenv('CONDO_APP_URL='.rtrim($base,'/'));
    $cookie=tempnam(sys_get_temp_dir(),'pool-cookie-'); $httpLog=tempnam(sys_get_temp_dir(),'pool-http-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/gate_fixture_router.php'],[0=>['pipe','r'],1=>['file',$httpLog,'a'],2=>['file',$httpLog,'a']],$httpPipes,dirname(__DIR__));
    $request=function(string $route,int $actor,?array $post=null) use($base,$cookie):array {
        $ch=curl_init($base.$route.(str_contains($route,'?')?'&':'?').'fixture_actor='.$actor);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>15]);
        if($post!==null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($post),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$status,$body];
    };
    for($i=0;$i<50;$i++) { $ready=@fsockopen('127.0.0.1',$port,$errno,$errstr,.1); if($ready){fclose($ready);break;}usleep(50000); }
    [$status,$html]=$request('resident/book_amenity.php',2); poolCheck($status===200,'tenant amenity page available');
    preg_match('/data-csrf="([a-f0-9]+)"/',$html,$token); $csrf=$token[1];
    poolCheck(substr_count($html,'data-open-booking=')===2 && str_contains($html,'amenity-swimming-pool') && str_contains($html,'amenity-function-hall'),'only two reservable cards and local photographs');
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);$xp=new DOMXPath($dom);
    poolCheck($xp->query('//article[contains(@class,"amenity-card")]')->length===9 && $xp->query('//article[contains(@class,"amenity-card")]//button[@data-open-booking]')->length===2,'all nine amenity cards, seven view-only');
    foreach($xp->query('//form[translate(@method,"POST","post")="post"]') as $form) poolCheck($xp->query('.//input[@name="csrf_token"]',$form)->length===1,'rendered booking CSRF token');
    [$status,$body]=$request('api/amenity_bookings.php?date='.$next.'&duration=2',2); $data=json_decode($body,true); poolCheck($status===200 && isset($data['slots']) && !str_contains($body,'Tenant Two') && !str_contains($body,'payment_id'),'availability endpoint scoped and identity-free');
    [$status]=$request('api/amenity_bookings.php?date='.$next,5); poolCheck($status===403,'unauthorized staff cannot browse pool schedule');
    $input=['action'=>'create','amenity'=>'Swimming Pool','booking_date'=>date('Y-m-d',strtotime('+4 days')),'booking_time'=>'10:00','duration_hours'=>'2','attendees'=>'1','total_fee'=>'0.01','hourly_rate'=>'0.01','user_id'=>3,'unit_number'=>'9999'];
    [$status]=$request('api/amenity_bookings.php',2,$input+['csrf_token'=>'invalid']); poolCheck($status===403,'invalid CSRF rejected');
    [$status,$body]=$request('api/amenity_bookings.php',2,$input+['csrf_token'=>$csrf]); poolCheck($status===200,'resident submission API');
    $tampered=$db->query('SELECT * FROM bookings ORDER BY id DESC LIMIT 1')->fetch_assoc(); poolCheck((int)$tampered['user_id']===2 && $tampered['unit_number']==='0101' && $tampered['hourly_rate']==='300.00' && $tampered['total_fee']==='600.00','posted identity, unit and price ignored');
    [$status,$adminHtml]=$request('superadmin/bookingrequest.php',4); poolCheck($status===200 && str_contains($adminHtml,'data-pool-schedule') && str_contains($adminHtml,'Total fee'),'Admin schedule and billing columns');
    preg_match('/data-csrf="([a-f0-9]+)"/',$adminHtml,$token); $adminCsrf=$token[1];
    [$status,$body]=$request('api/amenity_bookings.php',4,['action'=>'approved','booking_id'=>$overlap,'csrf_token'=>$adminCsrf]);
    poolCheck($status===200,'released unpaid slot can be approved later');
    $clash=poolCreate($db,1,date('Y-m-d',strtotime('+4 days')),'11:00',1); poolActor(4); decideAmenityBooking($db,(int)$tampered['id'],'approved');
    [$status,$body]=$request('api/amenity_bookings.php',4,['action'=>'approved','booking_id'=>$clash,'csrf_token'=>$adminCsrf]);
    poolCheck($status===409 && (json_decode($body,true)['code'] ?? '')==='schedule_conflict','structured approval conflict response');
    [$status,$body]=$request('api/amenity_bookings.php',2,['action'=>'create','amenity'=>'Swimming Pool','booking_date'=>$tampered['booking_date'],'booking_time'=>'11:00','duration_hours'=>1,'attendees'=>1,'csrf_token'=>$csrf]);
    poolCheck($status===409 && (json_decode($body,true)['title'] ?? '')==='Time Slot Already Booked','structured resident conflict response');
    [$status]=$request('api/amenity_bookings.php',2,['action'=>'approved','booking_id'=>$clash,'csrf_token'=>$csrf]); poolCheck($status===403,'resident API cannot approve');
    $fullDate=date('Y-m-d',strtotime('+6 days'));
    foreach([6,9,12,15,18] as $hour) { $full=poolCreate($db,1,$fullDate,sprintf('%02d:00',$hour),3); poolActor(4); decideAmenityBooking($db,$full,'approved'); }
    foreach([1,2,3] as $duration) poolCheck(poolAvailability($db,$fullDate,$duration)['fully_booked'],'fully-booked day for duration '.$duration);
    putenv('CONDO_TENANT_AMENITIES=0'); poolActor(2);
    poolReject(fn()=>createAmenityBooking($db,2,'Swimming Pool',$fullDate,'10:00',1),'tenant permission disabled on backend');
    putenv('CONDO_TENANT_AMENITIES=1');
    $moved=poolCreate($db,3,date('Y-m-d',strtotime('+7 days')),'08:00',1); $db->query("UPDATE users SET unit_number='0103' WHERE id=3"); poolActor(4);
    poolReject(fn()=>decideAmenityBooking($db,$moved,'approved'),'changed unit cannot approve old unit snapshot'); $db->query("UPDATE users SET unit_number='0102' WHERE id=3");
    $late=poolCreate($db,1,$next,'20:00',1); poolActor(4); decideAmenityBooking($db,$late,'approved'); $lateBill=(int)poolRow($db,$late)['payment_id'];
    $db->query("UPDATE payments SET paymongo_checkout_id='cs_late_pool',payment_method='online' WHERE id=$lateBill");
    poolActor(6); decideAmenityBooking($db,$late,'cancelled','PMO reviewed outstanding online checkout',true);
    $lateContext=array_replace($context,['internal_payment_id'=>$lateBill,'checkout_id'=>'cs_late_pool','paymongo_payment_id'=>'pay_late_pool','amount'=>30000]);
    poolCheck(confirmPaymongoCheckoutPayment($db,$lateContext)['confirmed'] && poolRow($db,$late)['status']==='cancelled','late trusted payment retains PMO cancellation');
    poolActor(4); decideAmenityBooking($db,$moved,'approved'); $vacantBill=(int)poolRow($db,$moved)['payment_id'];
    $vacantPaid=poolCreate($db,3,date('Y-m-d',strtotime('+7 days')),'10:00',1);poolActor(4);decideAmenityBooking($db,$vacantPaid,'approved');$vacantPaidBill=(int)poolRow($db,$vacantPaid)['payment_id'];
    $db->query("UPDATE payments SET payment_method='cash' WHERE id=$vacantPaidBill");poolActor(6);poolCheck(confirmCashBillPayment($db,$vacantPaidBill),'vacancy paid reservation fixture');
    poolCheck(unassignResidentUnit($db,3,'0102'),'residency revocation with active pool reservations');
    poolCheck(poolRow($db,$moved)['status']==='cancelled' && $db->query('SELECT status FROM payments WHERE id='.$vacantBill)->fetch_assoc()['status']==='rejected','vacancy releases approved pool booking and voids unpaid fee');
    poolCheck(poolRow($db,$vacantPaid)['status']==='cancelled' && $db->query('SELECT status FROM payments WHERE id='.$vacantPaidBill)->fetch_assoc()['status']==='paid','vacancy preserves paid financial history for PMO');
    if(in_array('--preview',$argv,true)) { echo 'Disposable amenity preview: '.$base."resident/book_amenity.php?fixture_actor=2\nPress Enter to finish.\n";fgets(STDIN); }
    echo "Passed $checks pool, gallery and billing integration checks.\n";
} finally {
    if(is_resource($http)) { proc_terminate($http);foreach($httpPipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($http); }
    foreach([$cookie,$httpLog] as $file) if($file && is_file($file)) unlink($file);
    if(!preg_match('/^condo_gate_test_[a-f0-9]{12}$/D',$testDb)) throw new RuntimeException('Unsafe test database cleanup.');
    $server->query("DROP DATABASE `$testDb`");
}
