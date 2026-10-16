<?php
require_once __DIR__.'/visitor_identity_fixture.php';
/** Full UI route fixture and asset/markup checks. Disposable MySQL only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database = 'condo_ui_test_'.bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
$port=(int)substr(strrchr($address,':'),1); $base='http://127.0.0.1:'.$port.'/CondoSystem3/';
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1','CONDO_UI_FIXTURE'=>'1','CONDO_REPORT_INDEPENDENT_REVIEWER_IDS'=>'6','CONDO_TENANT_PERMITS'=>'1','CONDO_TENANT_AMENITIES'=>'1','CONDO_APP_URL'=>rtrim($base,'/'),'CONDO_PASS_SIGNING_KEY'=>str_repeat('c',64)] as $key=>$value) putenv($key.'='.$value);
$reportFixtureStorage=sys_get_temp_dir().'/condo_report_ui_'.bin2hex(random_bytes(6));putenv('CONDO_REPORT_STORAGE='.$reportFixtureStorage);
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_ui_redesign.php'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
require_once dirname(__DIR__).'/config.php';
$http=null; $log=null; $checks=0;
function uiCheck(bool $condition,string $name):void { global $checks; if(!$condition) throw new RuntimeException('FAILED: '.$name); $checks++; }
function uiHttpRead(string $url): string {
    static $curl;
    if(!$curl) { $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]); }
    curl_setopt($curl,CURLOPT_URL,$url);
    $body=curl_exec($curl);
    if(!is_string($body)) throw new RuntimeException('UI fixture HTTP error: '.curl_error($curl));
    return $body;
}
function uiActor(mysqli $db,int $id):void {
    $user=$db->query('SELECT role,username,session_version,account_type FROM users WHERE id='.$id)->fetch_assoc();
    $_SESSION=['user_id'=>$id,'role'=>$user['role'],'username'=>$user['username'],'account_type'=>$user['account_type'],'session_version'=>$user['session_version'],'last_activity'=>time()];
}
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $result=$server->store_result(); if($result)$result->free(); } while($server->more_results() && $server->next_result());
    $db=connectDb(); ensureCommunityReportSchema($db); ensureVisitorParkingFeeSchema($db); ensureJobReportSchema($db);
    $hash=$db->real_escape_string(password_hash('FixtureOnly!2026',PASSWORD_DEFAULT));
    $db->query("INSERT INTO users(id,username,full_name,email,contact_number,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES
      (1,'owner','Owner One','owner@example.invalid','09123456789','$hash','0101','resident',1,'approved','Resident Owner',NULL),
      (2,'tenant','Tenant Two','tenant@example.invalid','09123456789','$hash','0101','resident',1,'approved','Tenant',1),
      (3,'applicant','Pending Applicant','pending@example.invalid','09123456789','$hash',NULL,'resident',1,'pending','Tenant',NULL),
      (4,'admin','Admin Staff','admin@example.invalid','09123456789','$hash',NULL,'admin',1,'approved',NULL,NULL),
      (5,'security','Security Staff','security@example.invalid','09123456789','$hash',NULL,'security',1,'approved',NULL,NULL),
      (6,'superadmin','Superadmin Staff','super@example.invalid','09123456789','$hash',NULL,'superadmin',1,'approved',NULL,NULL),
      (7,'treasurer','Treasurer Staff','treasurer@example.invalid','09123456789','$hash',NULL,'treasurer',1,'approved',NULL,NULL),
      (8,'maintenance','Maintenance Staff','maintenance@example.invalid','09123456789','$hash',NULL,'maintenance',1,'approved',NULL,NULL)");
    if(in_array('--security-only',$argv,true))$db->query("UPDATE users SET status='pending' WHERE role='security'");
    if(in_array('--staff-digital-id',$argv,true))$db->query("UPDATE users SET status='pending' WHERE role<>'resident'");
    if(in_array('--treasurer-only',$argv,true))$db->query("UPDATE users SET status='pending' WHERE role='treasurer'");
    $db->query("INSERT INTO announcements(admin_id,title,content,category,priority) VALUES(6,'Community schedule','Please contact the management office for assistance.','General','high')");
    $db->query("INSERT INTO maintenance_requests(user_id,issue_type,description,location,urgency) VALUES(1,'Plumbing','Please check the kitchen tap.','Kitchen','normal')");
    $db->query("INSERT INTO messages(user_id,sender_role,body) VALUES(1,'resident','May I ask about the community schedule?'),(1,'admin','The management office can help you with the current schedule.')");
    $db->query("INSERT INTO violations(user_id,violation_type,description,penalty_type,status,issued_by) VALUES(1,'Noise','Reminder to observe community quiet hours.','warning','warning_issued',6)");
    // Resident/tenant presentation fixtures cover each financial and dispute state.
    $db->query("INSERT INTO violations(user_id,violation_type,description,location,penalty_type,fine_amount,status,issued_by,observed_plate,incident_at,dispute_reason,due_date) VALUES
      (1,'Unauthorized Parking','Vehicle was parked in a restricted space.','Basement 1','fine',1000,'unpaid',6,'UI-1234',NOW(),NULL,DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),
      (1,'Noise Complaint','An evening noise complaint was recorded.','Unit 0101','fine',500,'disputed',6,NULL,NOW(),'Please review the recorded incident time.',DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),
      (1,'Littering','Fine payment was recorded by management.','Lobby','fine',250,'paid',6,NULL,NOW(),NULL,CURDATE()),
      (1,'Pet Violation','Management waived this fine after review.','Garden','fine',300,'waived',6,NULL,NOW(),NULL,CURDATE()),
      (2,'Unauthorized Parking','Tenant vehicle incident for UI verification.','Basement 2','fine',1000,'unpaid',6,'TEN-5678',NOW(),NULL,DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),
      (2,'Noise Complaint','Tenant fine is under review.','Unit 0101','fine',500,'disputed',6,NULL,NOW(),'Please review this report.',DATE_ADD(CURDATE(),INTERVAL 1 MONTH))");
    uiActor($db,6);
    $bill=createBill(1,[['category'=>'Association Dues','description'=>'UI fixture bill','amount'=>1250]],date('Y-m-01'),date('Y-m-t'),date('Y-m-d',strtotime('+15 days')));
    // Current bills, tenant-owned parking and completed history for billing UI checks.
    $paidBill=createBill(1,[['category'=>'Water','description'=>'Completed fixture statement','amount'=>425]],null,null,date('Y-m-d'));
    $db->query("UPDATE payments SET status='paid',payment_method='cash',payment_channel='cash',paid_at=NOW() WHERE id=".(int)$paidBill);
    if(in_array('--treasurer-only',$argv,true))$db->query("INSERT INTO payments(user_id,amount,payment_method,status,due_date,paid_at,payment_channel) VALUES
      (1,15125.50,'cash','paid',DATE_SUB(CURDATE(),INTERVAL 2 MONTH),DATE_SUB(NOW(),INTERVAL 2 MONTH),'cash'),
      (2,22340.75,'online','paid',DATE_SUB(CURDATE(),INTERVAL 1 MONTH),DATE_SUB(NOW(),INTERVAL 1 MONTH),'maya'),
      (1,12500.00,'online','paid',DATE_SUB(CURDATE(),INTERVAL 1 DAY),DATE_SUB(NOW(),INTERVAL 1 DAY),'gcash'),
      (1,3890.75,'unbilled','pending',DATE_SUB(CURDATE(),INTERVAL 35 DAY),NULL,NULL),
      (2,1250.50,'unbilled','overdue',DATE_SUB(CURDATE(),INTERVAL 4 DAY),NULL,NULL),
      (1,2750.00,'unbilled','pending',DATE_ADD(CURDATE(),INTERVAL 3 DAY),NULL,NULL)");
    createBill(2,[['category'=>'Parking','description'=>'Tenant personal parking fixture','amount'=>175]],null,null,date('Y-m-d',strtotime('+7 days')));
    uiActor($db,1);
    $tomorrow=date('Y-m-d',strtotime('+1 day'));
    createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'08:00',5,2);
    $visitor=fixtureCreateResidentServiceRequest($db,1,'visitor',['visitor_name'=>'Family Guest','visitor_count'=>'3','details'=>'Family visit','start_date'=>date('Y-m-d')]);
    $gate=savePropertyGateRequest($db,['permit_type'=>'Property Gate Pass','direction'=>'entry','purpose'=>'New Appliance / Furniture','details'=>'Kitchen equipment','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','transporter'=>'Fixture Delivery','vehicle_plate'=>'ABC123','items'=>[['item_name'=>'Refrigerator','category'=>'Appliance','quantity'=>'1','description'=>'Silver']]]);
    uiActor($db,6); decideResidentServiceRequest($db,$visitor,'approved',''); gateTransition($db,$gate,'approved');
    if(in_array('--visitor-parking-only',$argv,true)||in_array('--property-documents-only',$argv,true)){
        $approvalFixtureStorage=sys_get_temp_dir().'/condo-approval-ui-'.bin2hex(random_bytes(12));putenv('CONDO_VISITOR_ID_STORAGE='.$approvalFixtureStorage);visitorIdStorageDirectory(true);
        $db->query("INSERT INTO parking_slots(slot_code,slot_type) VALUES('VP-UI-OWNER','visitor'),('VP-UI-TENANT','visitor'),('VP-UI-RECEIPT','visitor')");
        $slots=$db->query("SELECT id FROM parking_slots WHERE slot_code LIKE 'VP-UI-%' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        foreach([[1,'UI-OWNER',0,2],[2,'UI-TENANT',1,48],[1,'UI-PAID',2,2]]as[$host,$plate,$index,$hours]){
            uiActor($db,$host);$guest=fixtureCreateResidentServiceRequest($db,$host,'visitor',['visitor_name'=>'Guest '.$plate,'visitor_count'=>'1','details'=>'Parking UI test','start_date'=>date('Y-m-d')]);
            $plan=['vehicle_type'=>'Car','vehicle_model'=>'Vios','vehicle_color'=>'Silver','expected_arrival'=>date('Y-m-d'). 'T00:00','expected_departure'=>date('Y-m-d\TH:i',time()+$hours*3600),'parking_fee_ack'=>'1','parking_rate_version'=>'2','extension_reason'=>'Family staying overnight'];
            $policy=getParkingPolicy($db);$db->begin_transaction();try{$parkingId=insertLinkedVisitorParking($db,$host,$guest,$plate,'Silver car',date('Y-m-d'),substr($plan['expected_departure'],0,10),$policy,$plan);$db->commit();}catch(Throwable $error){$db->rollback();throw $error;}
            uiActor($db,4);decideResidentServiceRequest($db,$guest,'approved','');if(!decideParkingRequest($parkingId,'approved',(int)$slots[$index]['id'],''))throw new RuntimeException('Parking UI seed approval failed.');
            if($hours>24){uiActor($db,$host);vpRequestExtension($db,$parkingId,['requested_until'=>date('Y-m-d\TH:i',time()+$hours*3600),'extension_reason'=>'Family staying overnight','request_key'=>bin2hex(random_bytes(24))]);}
            if($plate==='UI-PAID'){uiActor($db,5);vpVehicleCheckIn($db,$parkingId);$db->query('UPDATE parking_requests SET actual_check_in=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 90 MINUTE) WHERE id='.$parkingId);$paid=vpCheckoutSession($db,$parkingId);$paidParkingBill=(int)$paid['payment_id'];if(!$paidParkingBill){uiActor($db,4);$paid=vpFinalizeRateReview($db,$parkingId,['late_rule'=>'present_at_start','reason'=>'Confirmed regular late-arrival policy for fixture.']);$paidParkingBill=(int)$paid['payment_id'];}$db->query("UPDATE payments SET payment_method='cash' WHERE id=$paidParkingBill");uiActor($db,6);if(!confirmCashBillPayment($db,$paidParkingBill))throw new RuntimeException('Parking UI seed payment failed.');}
        }
    }
    if(in_array('--approval-only',$argv,true)){
        $approvalFixtureStorage=sys_get_temp_dir().'/condo-approval-ui-'.bin2hex(random_bytes(12));putenv('CONDO_VISITOR_ID_STORAGE='.$approvalFixtureStorage);visitorIdStorageDirectory(true);
        foreach([[1,'APPROVAL-OWNER',2],[2,'APPROVAL-TENANT',48]]as[$host,$plate,$hours]){
            uiActor($db,$host);$guest=fixtureCreateResidentServiceRequest($db,$host,'visitor',['visitor_name'=>'Guest '.$plate,'visitor_count'=>'1','details'=>'Family visit for combined approval','start_date'=>date('Y-m-d')]);
            $name=bin2hex(random_bytes(24)).'.png';$image=imagecreatetruecolor(640,400);$background=imagecolorallocate($image,245,246,250);imagefill($image,0,0,$background);$ink=imagecolorallocate($image,30,40,60);imagestring($image,5,30,50,'TEST ID DOCUMENT - FICTIONAL VISITOR',$ink);imagestring($image,5,30,110,$plate,$ink);imagepng($image,$approvalFixtureStorage.'/'.$name);imagedestroy($image);
            $s=$db->prepare("UPDATE resident_service_requests SET identity_status='pending',id_document_name=? WHERE id=?");$s->bind_param('si',$name,$guest);$s->execute();
            $code='APPROVAL-'.$host;$db->query("INSERT INTO parking_slots(slot_code,slot_type) VALUES('$code','visitor')");$slot=(int)$db->insert_id;
            $plan=['vehicle_type'=>'Car','vehicle_model'=>'Vios','vehicle_color'=>'Silver','expected_arrival'=>date('Y-m-d\TH:i'),'expected_departure'=>date('Y-m-d\TH:i',time()+$hours*3600),'parking_fee_ack'=>'1','parking_rate_version'=>'2','extension_reason'=>'Family staying overnight','requested_slot_id'=>(string)$slot];
            $policy=getParkingPolicy($db);$db->begin_transaction();try{insertLinkedVisitorParking($db,$host,$guest,$plate,'Silver car',date('Y-m-d'),substr($plan['expected_departure'],0,10),$policy,$plan);$db->commit();}catch(Throwable $error){$db->rollback();throw $error;}
        }
    }
    if(in_array('--audit-only',$argv,true)){
        $statement=$db->prepare("INSERT INTO audit_logs(admin_id,admin_name,admin_role,actor_name_snapshot,actor_identifier,actor_role_snapshot,action,entity_type,entity_id,details,outcome,created_at) VALUES(6,'Super Admin','superadmin','Super Admin','STAFF-00006','superadmin','update','parking_slot',?,'password=PRIVATE-TEST-TOKEN; reporter-private@example.invalid',?,?)");
        for($index=1;$index<=80;$index++){$outcome=$index%5===0?'failed':($index%7===0?null:'success');$at=date('Y-m-d').' 08:'.str_pad((string)($index%60),2,'0',STR_PAD_LEFT).':00';$statement->bind_param('iss',$index,$outcome,$at);$statement->execute();}
    }
    $manifest=[]; $add=static function(string $path,int $actor,string $label) use (&$manifest):void { $manifest[]=['path'=>$path,'actor'=>$actor,'label'=>$label]; };
    foreach(['homepage.php','login.php','signup.php','forgot_password.php','reset_password.php','verify.php','verify_pending.php','resend_verification.php'] as $page) $add($page,0,'Public / account');
    $add('signuppending.php',3,'Pending approval'); $add('logout.php',1,'Sign out confirmation');
    foreach([1,2,4,5,6,7,8] as $actor) {
        uiActor($db,$actor); $label=portalUiRoleLabel();
        if($actor<=2) {
            foreach(residentSidebarLinks() as [$path]) $add('resident/'.$path,$actor,$label);
            $add('resident/edit_profile.php',$actor,$label);
            if($actor===1) $add('resident/payment_return.php?payment_id='.$bill,$actor,$label);
        } else foreach(staffSidebarLinks() as [$path]) $add($path,$actor,$label);
    }
    foreach ([1,2] as $actor) $add('resident/vehicles.php',$actor,'Legacy vehicle bookmark');
    foreach ([4,6] as $actor) foreach (['registeredvehicles.php','parkinginventory.php'] as $page) $add('superadmin/'.$page,$actor,'Legacy parking bookmark');
    $add('superadmin/parking_configuration.php',6,'Parking configuration bookmark');
    $add('maintenance/maintenancerequests.php',8,'Maintenance');
    $visitorRow=$db->query('SELECT * FROM resident_service_requests WHERE id='.$visitor)->fetch_assoc();
    $add('resident_service_pass.php?id='.$visitor.'&token='.$visitorRow['access_token'],1,'Visitor QR pass');
    $gateRow=gateRequest($db,$gate); $add('resident_service_pass.php?id='.$gate.'&token='.gateToken($gateRow),1,'Property gate pass');
    // Generate a real authorized parking pass using its existing signature.
    $db->query("INSERT INTO parking_slots(slot_code,slot_type) VALUES('UI-V01','visitor')"); $slot=(int)$db->insert_id;
    $db->query("INSERT INTO parking_requests(user_id,vehicle_plate,start_date,end_date,status,slot_id) VALUES(1,'ABC123',CURDATE(),CURDATE(),'approved',$slot)"); $parking=(int)$db->insert_id;
    $add('parking_pass.php?request_id='.$parking.'&signature='.parkingPassSignature($parking),1,'Parking pass');
    if(in_array('--management-only',$argv,true)){
        $db->query("INSERT INTO maintenance_requests(user_id,issue_type,description,location,urgency,status,created_at) VALUES(1,'Electrical','Fixture urgent work','Lobby','urgent','in_progress',DATE_SUB(NOW(),INTERVAL 2 DAY)),(2,'Plumbing','Fixture completed work','Kitchen','urgent','completed',DATE_SUB(NOW(),INTERVAL 5 DAY))");
        $db->query("INSERT INTO bookings(user_id,amenity,booking_date,booking_time,end_time,status,unit_number) VALUES(1,'Function Hall',CURDATE(),'09:00','18:00','approved','0101'),(2,'Swimming Pool',DATE_ADD(CURDATE(),INTERVAL 1 DAY),'14:00','16:00','confirmed','0101')");
        uiActor($db,1);fixtureCreateResidentServiceRequest($db,1,'visitor',['visitor_name'=>'Pending Guest','visitor_count'=>'1','details'=>'Management fixture','start_date'=>date('Y-m-d')]);
        savePropertyGateRequest($db,['permit_type'=>'Property Gate Pass','direction'=>'entry','purpose'=>'New Appliance / Furniture','details'=>'Fixture appliance','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','items'=>[['item_name'=>'Chair','category'=>'Furniture','quantity'=>'1','description'=>'Fixture']]]);
        $db->query("INSERT INTO messages(user_id,sender_role,body) VALUES(1,'resident','Private dashboard fixture message A'),(1,'resident','Private dashboard fixture message B'),(2,'resident','Private dashboard fixture message C')");
        $db->query("INSERT INTO vehicles(user_id,make,model,color,year,plate_number,normalized_plate,or_cr_path,or_cr_mime,status) VALUES(1,'Toyota','Vios','Silver',2022,'UI-DASH','UIDASH','private-fixture.pdf','application/pdf','pending')");
        $db->query("INSERT INTO parking_requests(user_id,vehicle_plate,start_date,end_date,status) VALUES(1,'UI-PENDING',CURDATE(),CURDATE(),'pending')");
        $db->query("INSERT INTO parking_extensions(parking_request_id,requested_by,expected_start_utc,requested_until,reason,status,request_key,created_at) VALUES($parking,1,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 2 DAY),'Fixture extension','pending',REPEAT('b',64),UTC_TIMESTAMP(6))");
        $db->query("INSERT INTO violations(user_id,violation_type,description,penalty_type,status,issued_by,review_status) VALUES(1,'Parking observation','Private violation narrative','warning','warning_issued',5,'pending')");
        $db->query("INSERT INTO payments(user_id,amount,payment_method,status,due_date,paid_at,created_at) VALUES(1,150.25,'unbilled','pending',DATE_SUB(CURDATE(),INTERVAL 3 DAY),NULL,NOW()),(1,1999.99,'cash','paid',CURDATE(),NOW(),DATE_SUB(NOW(),INTERVAL 2 MONTH)),(2,999.99,'cash','paid',CURDATE(),DATE_SUB(NOW(),INTERVAL 2 MONTH),NOW())");
        $db->query("INSERT INTO visitor_logs(visitor_name,visitor_count,unit_number,logged_by,status,time_in) VALUES('Guest group',3,'0101',5,'checked_in',NOW())");
        $db->query("INSERT INTO notification_outbox(deduplication_key,event_kind,channel,recipient,subject,body,status) VALUES(REPEAT('c',64),'fixture','email','test@example.invalid','Private notification subject','Private notification body','failed')");
        $db->query("UPDATE users SET staff_id=CONCAT('STAFF-',LPAD(id,5,'0')) WHERE role<>'resident'");
        uiActor($db,4);$input=['type'=>'daily','period'=>date('Y-m-d',strtotime('-1 day')),'notes'=>jrNotes(['remarks'=>'Private filed notes']),'request_key'=>bin2hex(random_bytes(24)),'confirmed'=>'1'];$input['preview_hash']=jrPreview($db,$input)['preview_hash'];jrFile($db,$input);
    }
    if(in_array('--archive-only',$argv,true)){
        foreach([[9,'Former Owner','Resident Owner','0102',null,'resident'],[10,'Former Tenant','Tenant','0101',1,'resident'],[11,'Former Staff',null,null,null,'maintenance']] as [$id,$name,$kind,$unit,$owner,$role]) {
            $s=$db->prepare("INSERT INTO users(id,username,full_name,email,password_hash,role,account_type,unit_number,unit_owner_id,is_active,is_verified,status,staff_id) VALUES(?,?,?,?,?,?,?,?,?,1,1,'approved',?)");$login='former'.$id;$email=$login.'@example.invalid';$staffId=$role==='resident'?null:'STAFF-'.str_pad((string)$id,5,'0',STR_PAD_LEFT);$s->bind_param('isssssssis',$id,$login,$name,$email,$hash,$role,$kind,$unit,$owner,$staffId);$s->execute();
        }
        ensureAccountArchiveSchema($db);
        $db->query("INSERT INTO payments(user_id,amount,payment_method,status,due_date,unit_number_snapshot) VALUES(9,2750.50,'unbilled','pending',CURDATE(),'0102'),(10,1500.75,'unbilled','pending',CURDATE(),'0101')");
        $db->query("INSERT INTO vehicles(user_id,make,model,color,year,plate_number,normalized_plate,or_cr_path,or_cr_mime,status,unit_number_snapshot) VALUES(9,'Toyota','Vios','White',2025,'ARC-999','ARC999','fixture.pdf','application/pdf','approved','0102')");
        $db->query("INSERT INTO maintenance_requests(user_id,issue_type,description,location,status,unit_number_snapshot) VALUES(9,'Plumbing','Historical archived fixture work','Kitchen','in_progress','0102')");
        uiActor($db,11);$input=['type'=>'daily','period'=>date('Y-m-d'),'notes'=>jrNotes(['remarks'=>'Preserved former staff report']),'request_key'=>bin2hex(random_bytes(24)),'confirmed'=>'1'];$input['preview_hash']=jrPreview($db,$input)['preview_hash'];jrFile($db,$input);
        uiActor($db,6);$archiveEvents=[];
        foreach([[9,'archive_account','0102'],[10,'end_occupancy','0101'],[11,'archive_staff','']] as [$id,$mode,$unit]) {
            $input=['user_id'=>$id,'mode'=>$mode,'unit_number'=>$unit,'reason_code'=>'moved_out','reason'=>'Fictional departure for Archive UI tests','confirmed'=>'1','cancel_unused'=>'1','parking_cleared'=>'1','request_key'=>bin2hex(random_bytes(32))];$input['preview_hash']=archivePreview($db,$input)['preview_hash'];$archiveEvents[]=archiveApply($db,$input)['event_id'];
        }
        $manifest=[];foreach(['superadmin/archive.php','superadmin/archive.php?tab=tenants','superadmin/archive.php?tab=staff','superadmin/units.php','superadmin/residents.php','superadmin/staff.php?tab=information','superadmin/generate_bills.php','superadmin/parking.php','resident/visitors.php'] as $path)$manifest[]=['path'=>$path,'actor'=>str_starts_with($path,'resident/')?1:6,'label'=>'Archive workflow'];
        foreach($archiveEvents as $event)$manifest[]=['path'=>'superadmin/archive.php?id='.$event,'actor'=>6,'label'=>'Archive history'];
    }
    // An actual forbidden role route verifies the shared 403 screen.
    $add('superadmin/staff.php',5,'Access denied');
    $add('report_tracking.php',0,'Anonymous tracking');
    if(in_array('--security-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>$entry['actor']===5&&$entry['label']!=='Access denied'));
    if(in_array('--treasurer-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>$entry['actor']===7&&$entry['label']!=='Access denied'));
    if(in_array('--analytics-only',$argv,true))$manifest=[['path'=>'superadmin/analytics.php','actor'=>6,'label'=>'System Analytics']];
    if(in_array('--audit-only',$argv,true))$manifest=[['path'=>'superadmin/auditlog.php','actor'=>6,'label'=>'Super Admin audit workspace']];
    if(in_array('--jobs-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>in_array(explode('?',$entry['path'])[0],['job_report.php','superadmin/staff.php'],true)&&$entry['label']!=='Access denied'));
    if(in_array('--reports-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>in_array($entry['path'],['resident/reports.php','superadmin/reports.php','report_tracking.php'],true)));
    if(in_array('--approval-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>in_array(explode('?',$entry['path'])[0],['resident/visitors.php','resident/parking.php','superadmin/parking.php','superadmin/service_requests.php','security/scanner.php'],true)&&!str_contains($entry['path'],'kind=permit')));
    if(in_array('--visitor-parking-only',$argv,true)||in_array('--parking-only',$argv,true)){
        $manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>in_array(explode('?',$entry['path'])[0],['resident/parking.php','resident/visitors.php','resident/payments.php','superadmin/service_requests.php','superadmin/parking.php','superadmin/unitpayments.php','security/scanner.php','superadmin/scanner.php','resident_service_pass.php'],true)));
        if(isset($paidParkingBill))$manifest[]=['path'=>'resident/payment_receipt.php?id='.$paidParkingBill,'actor'=>1,'label'=>'Paid visitor parking PDF'];
    }
    if(in_array('--property-documents-only',$argv,true)){
        $manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>in_array(explode('?',$entry['path'])[0],['resident/permits.php','resident/visitors.php','resident_service_pass.php','parking_pass.php','job_report.php','superadmin/staff.php'],true)&&$entry['label']!=='Access denied'));
        $manifest[]=['path'=>'resident/payment_receipt.php?id='.$paidBill,'actor'=>1,'label'=>'Paid billing receipt PDF'];
        $manifest[]=['path'=>'resident/payment_receipt.php?id='.$paidParkingBill,'actor'=>1,'label'=>'Paid visitor parking receipt PDF'];
    }
    if(in_array('--archive-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>!in_array($entry['label'],['Access denied','Anonymous tracking'],true)));
    if(in_array('--management-only',$argv,true))$manifest=array_values(array_filter($manifest,static fn(array $entry):bool=>in_array($entry['path'],['admin/admin_dashboard.php','superadmin/admin_dashboard.php'],true)));
    putenv('CONDO_UI_MANIFEST='.json_encode($manifest,JSON_THROW_ON_ERROR));
    $log=tempnam(sys_get_temp_dir(),'condo-ui-http-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/ui_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
    fclose($pipes[0]);
    for($attempt=0;$attempt<50;$attempt++) { $probe=@file_get_contents($base.'__manifest'); if($probe!==false)break; usleep(100000); }
    uiCheck($probe!==false,'loopback fixture starts');
    foreach($manifest as $entry) {
        if(str_starts_with($entry['path'],'resident/payment_receipt.php'))continue;
        $url=$base.$entry['path'].(str_contains($entry['path'],'?')?'&':'?').'fixture_actor='.$entry['actor'];
        $body=uiHttpRead($url);
        uiCheck(is_string($body) && str_contains($body,'portal-ui'),'rendered shared UI: '.$entry['label'].' '.$entry['path']);
        uiCheck(substr_count($body,'assets/vendor/gsap/gsap.min.js')===1,'one GSAP import: '.$entry['path']);
        uiCheck(str_contains($body,'assets/css/design-system.css') && str_contains($body,'assets/css/components.css') && str_contains($body,'assets/css/responsive.css'),'shared assets: '.$entry['path']);
        uiCheck(substr_count($body,'assets/js/theme.js')===1 && substr_count($body,'assets/css/theme.css')===1 && strpos($body,'assets/js/theme.js')<strpos($body,'</head>'),'one theme bootstrap before body: '.$entry['path']);
        uiCheck(substr_count($body,'assets/js/loading-ui.js')===1 && substr_count($body,'assets/css/loading-ui.css')===1 && str_contains($body,'name="loading-logo-dark"') && str_contains($body,'name="loading-logo-light"'),'one shared themed loader: '.$entry['path']);
        uiCheck(!str_contains($body,'Fatal error') && !str_contains($body,'Warning:'),'no PHP rendering errors: '.$entry['path']);
        $dom=new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($body); libxml_clear_errors(); $xp=new DOMXPath($dom);
        foreach ($xp->query('//link[@rel="stylesheet"]') as $stylesheet) {
            $href = $stylesheet->getAttribute('href');
            if (preg_match('~^(?:https?:)?//~i', $href) && !str_starts_with($href, $base)) continue;
            parse_str((string)parse_url($href, PHP_URL_QUERY), $assetQuery);
            uiCheck(isset($assetQuery['v']) && ctype_digit((string)$assetQuery['v']) && (int)$assetQuery['v'] > 2, 'stylesheet cache version: '.$entry['path'].' '.$href);
        }
        foreach($xp->query('//form[translate(@method,"POST","post")="post"]') as $form) uiCheck($xp->query('.//input[@name="csrf_token"]',$form)->length===1,'CSRF retained: '.$entry['path']);
    }
    foreach(['assets/css/design-system.css','assets/css/components.css','assets/css/responsive.css','assets/css/theme.css','assets/js/theme.js','assets/vendor/gsap/gsap.min.js','assets/js/ui-components.js','assets/js/animations.js'] as $asset) uiCheck(strlen((string)file_get_contents($base.$asset))>100,'local shared asset: '.$asset);
    echo 'Passed '.$checks.' UI route, asset and CSRF checks across '.count($manifest)." role/page combinations.\n";
    if(in_array('--preview',$argv,true)) { echo 'UI preview: '.$base."__manifest\nPress Enter to finish.\n"; fgets(STDIN); }
} finally {
    if(is_resource($http)) {proc_terminate($http);proc_close($http);}
    if($log && is_file($log)) unlink($log);
    if(!preg_match('/^condo_ui_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture cleanup.');
    // Preview sessions may outlive a database-service restart; reconnect for cleanup.
    $db=new mysqli($configuredDbHost,$configuredDbUser,$configuredDbPass,$database);$db->set_charset('utf8mb4');
    $server=new mysqli($configuredDbHost,$configuredDbUser,$configuredDbPass);
    // Browser tests can submit real multipart vehicle registrations to this fixture.
    if (isset($db) && $db->query("SHOW TABLES LIKE 'vehicles'")->num_rows) {
        foreach ($db->query('SELECT or_cr_path,vehicle_photo_path FROM vehicles')->fetch_all(MYSQLI_ASSOC) as $vehicle) {
            removeUnusedVehicleUpload($vehicle['or_cr_path']);
            removeUnusedVehicleUpload($vehicle['vehicle_photo_path']);
        }
    }
    if (isset($db) && profilePhotoSchemaReady($db)) {
        foreach ($db->query('SELECT profile_photo FROM users WHERE profile_photo IS NOT NULL')->fetch_all(MYSQLI_ASSOC) as $account) {
            $photo=$account['profile_photo'];
            if (profilePhotoFilename($photo) && is_file(profilePhotoDirectory().'/'.$photo)) unlink(profilePhotoDirectory().'/'.$photo);
        }
    }
    if(isset($db) && $db->query("SHOW TABLES LIKE 'report_attachments'")->num_rows) foreach($db->query('SELECT stored_name FROM report_attachments')as$file){$path=reportEvidencePath($file['stored_name']);if($path)unlink($path);}
    if(isset($approvalFixtureStorage)&&preg_match('~[/\\\\]condo-approval-ui-[a-f0-9]{24}$~D',$approvalFixtureStorage)){foreach(glob($approvalFixtureStorage.'/*.png')as$file)unlink($file);rmdir($approvalFixtureStorage);}
    $server->query("DROP DATABASE `$database`");
    if(is_dir($reportFixtureStorage)&&str_starts_with($reportFixtureStorage,sys_get_temp_dir().'/condo_report_ui_'))rmdir($reportFixtureStorage);
}
