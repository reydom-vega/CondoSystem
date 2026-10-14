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
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1','CONDO_UI_FIXTURE'=>'1','CONDO_TENANT_PERMITS'=>'1','CONDO_TENANT_AMENITIES'=>'1','CONDO_APP_URL'=>rtrim($base,'/'),'CONDO_PASS_SIGNING_KEY'=>str_repeat('c',64)] as $key=>$value) putenv($key.'='.$value);
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
    $db=connectDb();
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
    createBill(2,[['category'=>'Parking','description'=>'Tenant personal parking fixture','amount'=>175]],null,null,date('Y-m-d',strtotime('+7 days')));
    uiActor($db,1);
    $tomorrow=date('Y-m-d',strtotime('+1 day'));
    createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'08:00',5,2);
    $visitor=fixtureCreateResidentServiceRequest($db,1,'visitor',['visitor_name'=>'Family Guest','visitor_count'=>'3','details'=>'Family visit','start_date'=>date('Y-m-d')]);
    $gate=savePropertyGateRequest($db,['permit_type'=>'Property Gate Pass','direction'=>'entry','purpose'=>'New Appliance / Furniture','details'=>'Kitchen equipment','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','transporter'=>'Fixture Delivery','vehicle_plate'=>'ABC123','items'=>[['item_name'=>'Refrigerator','category'=>'Appliance','quantity'=>'1','description'=>'Silver']]]);
    uiActor($db,6); decideResidentServiceRequest($db,$visitor,'approved',''); gateTransition($db,$gate,'approved');
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
    // An actual forbidden role route verifies the shared 403 screen.
    $add('superadmin/staff.php',5,'Access denied');
    putenv('CONDO_UI_MANIFEST='.json_encode($manifest,JSON_THROW_ON_ERROR));
    $log=tempnam(sys_get_temp_dir(),'condo-ui-http-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/ui_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
    fclose($pipes[0]);
    for($attempt=0;$attempt<50;$attempt++) { $probe=@file_get_contents($base.'__manifest'); if($probe!==false)break; usleep(100000); }
    uiCheck($probe!==false,'loopback fixture starts');
    foreach($manifest as $entry) {
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
    $server->query("DROP DATABASE `$database`");
}
