<?php
require_once __DIR__.'/visitor_identity_fixture.php';
/** End-to-end vehicle reports use a disposable database and loopback-only role fixture. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database='condo_ui_test_'.bin2hex(random_bytes(6)); $server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
$port=(int)substr(strrchr($address,':'),1); $base="http://127.0.0.1:$port/CondoSystem3/";
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1','CONDO_UI_FIXTURE'=>'1','CONDO_APP_URL'=>rtrim($base,'/'),'CONDO_VISITOR_VIOLATION_HOST_BILLING'=>'0'] as $key=>$value) putenv($key.'='.$value);
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_vehicle_violations.php'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/violation_workspace.php';
$checks=0; $http=null; $log=null; $uploadFixture=null;
function vehicleCheck(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAILED: '.$label); $checks++; }
function vehicleReject(callable $action,string $label): void { try { $action(); } catch (InvalidArgumentException $error) { vehicleCheck(true,$label); return; } throw new RuntimeException('FAILED: '.$label); }
function vehicleActor(mysqli $db,int $id): void {
    $row=$db->query('SELECT role,username,session_version FROM users WHERE id='.$id)->fetch_assoc();
    $_SESSION=['user_id'=>$id,'role'=>$row['role'],'username'=>$row['username'],'session_version'=>$row['session_version'],'last_activity'=>time()];
}
function vehicleHttp(string $path,?array $post=null): array {
    global $base; static $curl;
    if (!$curl) { $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]); }
    $multipart=$post!==null && count(array_filter($post,fn($value)=>$value instanceof CURLFile))>0;
    curl_setopt_array($curl,[CURLOPT_URL=>$base.$path,CURLOPT_POST=>$post!==null,CURLOPT_POSTFIELDS=>$post!==null ? ($multipart ? $post : http_build_query($post)) : null]);
    if ($post===null) curl_setopt($curl,CURLOPT_HTTPGET,true);
    $body=curl_exec($curl); if (!is_string($body)) throw new RuntimeException(curl_error($curl));
    return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body];
}
try {
    $nameFixtures=[['id'=>11,'full_name'=>'Same Name','unit_number'=>'101'],['id'=>12,'full_name'=>'Same Name','unit_number'=>'102'],['id'=>13,'full_name'=>'Unique Resident','unit_number'=>'103']];
    vehicleCheck(violationResidentFromName($nameFixtures,'  unique   resident ' )===13,'typed name resolves without JavaScript and ignores case/extra whitespace');
    vehicleReject(fn()=>violationResidentFromName($nameFixtures,'Same Name'),'duplicate name requires a qualified suggestion');
    vehicleCheck(violationResidentFromName($nameFixtures,violationResidentOption($nameFixtures[1]))===12,'qualified suggestion resolves the correct duplicate account');
    vehicleReject(fn()=>violationResidentFromName($nameFixtures,'Unknown Person'),'unknown typed name rejected');
    vehicleReject(fn()=>violationResidentFromName($nameFixtures,'Unique Resident',11),'forged hidden account cannot override the typed name');
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $result=$server->store_result(); if ($result) $result->free(); } while ($server->more_results() && $server->next_result());
    $db=connectDb(); ensureResidentAccountSchema($db); ensureAuthenticationSchema($db); ensureAuditLogTable($db);
    ensurePaymentsTable($db); ensureBillingTables($db); ensurePaymongoColumns($db); ensureParkingTables($db); ensureViolationsTable($db);
    ensureVehiclesTable($db); ensureStickerVehicleLinks($db); ensureResidentServicesTables($db); ensureVisitorLogsTable($db); ensureVisitorLogColumns($db);
    ensureNotificationOutboxTable($db);
    $hash=$db->real_escape_string(password_hash('FixtureOnly!2026',PASSWORD_DEFAULT));
    $db->query("INSERT INTO users(id,username,full_name,email,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES
        (1,'owner','Owner Current','owner@example.invalid','$hash','0101','resident',1,'approved','Resident Owner',NULL),
        (2,'tenant','Tenant Current','tenant@example.invalid','$hash','0101','resident',1,'approved','Tenant',1),
        (3,'security','Security Reporter','security@example.invalid','$hash',NULL,'security',1,'approved',NULL,NULL),
        (4,'superadmin','Superadmin Reviewer','super@example.invalid','$hash',NULL,'superadmin',1,'approved',NULL,NULL),
        (5,'admin','Admin Reviewer','admin@example.invalid','$hash',NULL,'admin',1,'approved',NULL,NULL),
        (6,'oldowner','Original Owner','old@example.invalid','$hash','0202','resident',1,'approved','Resident Owner',NULL),
        (7,'treasurer','Treasurer','treasurer@example.invalid','$hash',NULL,'treasurer',1,'approved',NULL,NULL)");
    $ownerVehicle=createVehicle($db,1,'Toyota','Vios','Silver',2025,'ABC-1234','private_uploads/vehicle_documents/fixture.pdf','application/pdf');
    $tenantVehicle=createVehicle($db,2,'Honda','Civic','Blue',2024,'TEN 7890','private_uploads/vehicle_documents/fixture.pdf','application/pdf');
    vehicleActor($db,4); decideVehicle($db,$ownerVehicle,'approved',''); decideVehicle($db,$tenantVehicle,'approved','');
    $db->query("INSERT INTO parking_slots(id,slot_code,level,slot_type) VALUES(1,'B1-R001','B1','resident'),(2,'B1-V001','B1','visitor'),(3,'B2-R002','B2','resident')");
    vehicleCheck(assignParkingSlot(1,1,'0101','ABC-1234'),'registered vehicle linked to parking inventory');
    $today=date('Y-m-d'); $now=date('Y-m-d\TH:i');
    $visitor=fixtureCreateResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Visitor Guest','details'=>'Visit tenant','start_date'=>$today]);
    $db->query("UPDATE resident_service_requests SET status='approved' WHERE id=$visitor");
    $db->query("INSERT INTO visitor_logs(visitor_name,unit_number,logged_by,status) VALUES('Visitor Guest','0101',3,'checked_in')"); $logId=(int)$db->insert_id;
    $db->query("UPDATE resident_service_requests SET visitor_log_id=$logId WHERE id=$visitor");
    vehicleCheck(createParkingRequest(2,'visitor','VIS-456','White sedan',$today,$today),'visitor parking request created');
    $request=(int)$db->query('SELECT MAX(id) AS id FROM parking_requests')->fetch_assoc()['id'];
    $db->query("UPDATE parking_requests SET visitor_registration_id=$visitor WHERE id=$request");
    vehicleCheck(decideParkingRequest($request,'approved',2,''),'visitor parking request approved');
    $db->query("INSERT INTO payments(user_id,amount,payment_method,status,due_date) VALUES(6,1000,'cash','paid',CURRENT_DATE())"); $legacyBill=(int)$db->insert_id;
    $db->query("INSERT INTO bill_items(payment_id,category,description,amount) VALUES($legacyBill,'Violation Fine','Historical paid fine',1000)"); $legacyItem=(int)$db->insert_id;
    $db->query("INSERT INTO violations(user_id,violation_type,penalty_type,fine_amount,status,payment_id,bill_item_id,issued_by,observed_plate,account_name_snapshot,unit_snapshot)
        VALUES(6,'Unauthorized Parking','fine',1000,'paid',$legacyBill,$legacyItem,4,'ABC 1234','Original Owner','0202')"); $legacy=(int)$db->insert_id;
    vehicleActor($db,3);
    foreach (['ABC1234','abc-1234','ABC 1234','ABC'] as $plate) {
        $records=searchViolationVehicles($db,'plate',$plate)['records'];
        vehicleCheck(count(array_filter($records,fn($r)=>$r['source']==='registered' && (int)$r['id']===$ownerVehicle))===1,'plate normalization / partial match: '.$plate);
    }
    $tenant=vehicleViolationRecord($db,'registered',$tenantVehicle);
    vehicleCheck($tenant['registration_type']==='Tenant vehicle' && (int)$tenant['account_id']===2,'tenant identity is distinct from unit owner');
    $guest=searchViolationVehicles($db,'plate','VIS 456')['records'][0];
    vehicleCheck($guest['source']==='visitor' && (int)$guest['visitor_registration_id']===$visitor && $guest['time_in']!==null,'visitor lookup resolves linked registration and gate information');
    $slotRecords=searchViolationVehicles($db,'slot','b1-')['records'];
    vehicleCheck(count(array_filter($slotRecords,fn($r)=>$r['source']==='registered'))===1 && count(array_filter($slotRecords,fn($r)=>$r['source']==='visitor'))===1,'slot search returns registered and active visitor assignments');
    vehicleCheck(searchViolationVehicles($db,'plate','NOTFOUND')['records']===[],'unknown plate returns empty results');
    vehicleCheck(!str_contains(json_encode($slotRecords),'or_cr') && !str_contains(json_encode($slotRecords),'access_token'),'lookup excludes ownership documents and pass secrets');
    $input=['registration_source'=>'registered','related_record_id'=>$ownerVehicle,'vehicle_parking_slot_id'=>1,'observed_plate'=>'ABC 1234',
        'violation_type'=>'Unauthorized Parking','description'=>'Parked across the marked line.','location'=>'Actual incident location B1 ramp','incident_at'=>$now,'penalty_type'=>'fine','fine_amount'=>'0.01','report_remarks'=>'Verified observed plate only.'];
    $beforeItems=(int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n'];
    $report=submitVehicleViolationReport($db,$input);
    $saved=$db->query("SELECT * FROM violations WHERE id=$report")->fetch_assoc();
    vehicleCheck($saved['review_status']==='pending' && $saved['status']===null && $saved['user_id']===null && $saved['payment_id']===null,'new incident is pending with no liability or financial state');
    vehicleCheck((float)$saved['fine_amount']===getViolationFineRates()['Unauthorized Parking'],'browser cannot override the configured fine');
    vehicleCheck((int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n']===$beforeItems,'unapproved report creates no billing charge');
    vehicleReject(fn()=>submitVehicleViolationReport($db,array_replace($input,['related_record_id'=>999999])),'forged vehicle reference rejected');
    vehicleReject(fn()=>submitVehicleViolationReport($db,array_replace($input,['observed_plate'=>'OTHER123'])),'plate mismatch rejected');
    vehicleReject(fn()=>submitVehicleViolationReport($db,array_replace($input,['vehicle_parking_slot_id'=>3])),'stale parking reference rejected');
    vehicleReject(fn()=>submitVehicleViolationReport($db,array_replace($input,['violation_type'=>'Browser custom fine'])),'unconfigured vehicle fine rejected');
    vehicleReject(fn()=>reviewVehicleViolationReport($db,$report,'approve',['responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'Evidence checked']),'security cannot approve a report');
    $record=vehicleViolationRecord($db,'registered',$ownerVehicle); $history=vehicleViolationHistory($db,$record,[]);
    vehicleCheck($history['summary']['total']===2 && $history['summary']['pending']===1,'vehicle history includes exact report and unlinked historical plate');
    $old=array_values(array_filter($history['records'],fn($r)=>(int)$r['id']===$legacy))[0];
    vehicleCheck(!$old['exact_match'] && $old['match_label']==='Potential historical plate match' && $old['account_name_snapshot']==='Original Owner','historical match retains recorded owner and is labelled provisional');
    vehicleCheck(vehicleViolationDetails($db,$report)['audit_events']===[],'security cannot read administrative audits');
    vehicleActor($db,5);
    vehicleReject(fn()=>reviewVehicleViolationReport($db,$report,'approve',['responsible_user_id'=>1,'responsibility_note'=>'Needs investigation']),'approval requires explicit verified responsibility');
    vehicleCheck(reviewVehicleViolationReport($db,$report,'approve',['responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'Observed driver identified and evidence reviewed.']),'admin approves verified resident report');
    vehicleCheck(!reviewVehicleViolationReport($db,$report,'approve',['responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'Repeat approval']),'repeated approval is rejected without duplicate billing');
    vehicleCheck((int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n']===$beforeItems+1,'approved fine creates exactly one billing item');
    vehicleCheck(count(getViolationsForUser(1))===1,'approved violation is visible to the responsible resident');
    vehicleActor($db,1); vehicleCheck(disputeViolation($report,1,'The photo needs further review.'),'resident can dispute an approved vehicle fine');
    vehicleActor($db,5);
    $disputedHistory=vehicleViolationHistory($db,vehicleViolationRecord($db,'registered',$ownerVehicle),[]);
    vehicleCheck($disputedHistory['summary']['disputed']===1 && $disputedHistory['summary']['confirmed']===1,'history summary separates disputed reports from confirmed violations');
    vehicleCheck(resolveViolation($report,'reject_dispute'),'existing dispute resolution remains functional');
    vehicleActor($db,3);
    $tenantInput=array_replace($input,['related_record_id'=>$tenantVehicle,'vehicle_parking_slot_id'=>0,'observed_plate'=>'TEN-7890']);
    $tenantReport=submitVehicleViolationReport($db,$tenantInput);
    $visitorInput=array_replace($input,['registration_source'=>'visitor','related_record_id'=>$request,'vehicle_parking_slot_id'=>2,'observed_plate'=>'VIS456']);
    $visitorReport=submitVehicleViolationReport($db,$visitorInput);
    $unknownInput=array_replace($input,['registration_source'=>'unknown','related_record_id'=>0,'vehicle_parking_slot_id'=>3,'observed_plate'=>'UNK999']);
    $unknownReport=submitVehicleViolationReport($db,$unknownInput);
    vehicleCheck($db->query("SELECT user_id FROM violations WHERE id=$unknownReport")->fetch_assoc()['user_id']===null,'unknown vehicle is not charged to the parking slot owner');
    vehicleActor($db,5);
    vehicleCheck(reviewVehicleViolationReport($db,$tenantReport,'approve',['responsible_user_id'=>2,'responsibility_verified'=>'1','responsibility_note'=>'Tenant responsibility verified.']),'tenant incident can be approved');
    $tenantBill=$db->query("SELECT v.user_id,p.user_id AS billing_user FROM violations v JOIN payments p ON p.id=v.payment_id WHERE v.id=$tenantReport")->fetch_assoc();
    vehicleCheck((int)$tenantBill['user_id']===2 && (int)$tenantBill['billing_user']===1,'tenant citation remains on tenant while unit owner receives the bill');
    vehicleReject(fn()=>reviewVehicleViolationReport($db,$visitorReport,'approve',['responsible_user_id'=>2,'responsibility_verified'=>'1','responsibility_note'=>'Host selected without property policy']),'visitor fine cannot charge host when PMO policy is disabled');
    vehicleCheck($db->query("SELECT review_status FROM violations WHERE id=$visitorReport")->fetch_assoc()['review_status']==='pending','unverified visitor report remains pending investigation');
    putenv('CONDO_VISITOR_VIOLATION_HOST_BILLING=1');
    vehicleReject(fn()=>reviewVehicleViolationReport($db,$visitorReport,'approve',['responsible_user_id'=>2,'responsibility_verified'=>'1','responsibility_note'=>'Host policy applies']),'visitor fine requires incident-specific PMO policy confirmation');
    vehicleCheck(reviewVehicleViolationReport($db,$visitorReport,'approve',['responsible_user_id'=>2,'responsibility_verified'=>'1','visitor_policy_verified'=>'1','responsibility_note'=>'PMO policy and responsible hosting tenant verified.']),'visitor fine can be approved only with configured policy and explicit verification');
    putenv('CONDO_VISITOR_VIOLATION_HOST_BILLING=0');
    vehicleActor($db,3);
    $warning=submitVehicleViolationReport($db,array_replace($input,['penalty_type'=>'warning','violation_type'=>'Vehicle safety reminder']));
    $warningItems=(int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n'];
    vehicleActor($db,5);
    vehicleCheck(reviewVehicleViolationReport($db,$warning,'approve',['responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'Warning only; evidence and driver verified.']),'reviewed vehicle warning can be approved without a fine');
    vehicleCheck((int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n']===$warningItems && $db->query("SELECT status FROM violations WHERE id=$warning")->fetch_assoc()['status']==='warning_issued','vehicle warning never creates a billing charge');
    $db->query("UPDATE users SET full_name='Owner Renamed' WHERE id=1");
    vehicleCheck(json_decode($db->query("SELECT vehicle_snapshot FROM violations WHERE id=$report")->fetch_assoc()['vehicle_snapshot'],true)['account_name']==='Owner Current','incident vehicle snapshot survives subsequent account changes');
    $db->query("UPDATE users SET full_name='Owner Current' WHERE id=1");
    vehicleActor($db,4);
    vehicleCheck(reviewVehicleViolationReport($db,$unknownReport,'reject',['responsibility_note'=>'Incorrect plate recorded.']),'superadmin rejects incorrect report without billing');
    vehicleCheck(count(vehicleViolationDetails($db,$report)['audit_events'])>=3,'superadmin can inspect report and approval audit events');
    $beforeLegacy=$db->query("SELECT user_id,status,payment_id,bill_item_id FROM violations WHERE id=$legacy")->fetch_assoc();
    vehicleCheck($beforeLegacy===['user_id'=>'6','status'=>'paid','payment_id'=>(string)$legacyBill,'bill_item_id'=>(string)$legacyItem],'existing paid violation and billing links retained');
    // A failing audit must roll back both approval and any bill item.
    vehicleActor($db,3); $rollback=submitVehicleViolationReport($db,$input);
    vehicleActor($db,4); $count=(int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n'];
    $db->query("CREATE TRIGGER fail_vehicle_review_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='vehicle_report_approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected vehicle review audit failure'; END IF; END");
    try { reviewVehicleViolationReport($db,$rollback,'approve',['responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'Simulated transaction failure']); throw new RuntimeException('Expected audit failure'); }
    catch (mysqli_sql_exception $error) { vehicleCheck(true,'approval failure propagated'); }
    $db->query('DROP TRIGGER fail_vehicle_review_audit');
    vehicleCheck((int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n']===$count && $db->query("SELECT review_status FROM violations WHERE id=$rollback")->fetch_assoc()['review_status']==='pending','failed approval rolls back charge and report state');
    // More than one history page, with stable incident ordering.
    vehicleActor($db,3); for ($i=0;$i<10;$i++) submitVehicleViolationReport($db,$input);
    $history=vehicleViolationHistory($db,vehicleViolationRecord($db,'registered',$ownerVehicle),['page'=>2]);
    vehicleCheck($history['page']===2 && count($history['records'])>0 && $history['pages']===2,'history pagination returns stable second page');
    vehicleCheck(vehicleViolationHistory($db,$record,['status'=>'paid'])['total']===1,'history financial status filter works');
    vehicleCheck(vehicleViolationHistory($db,$record,['search'=>'not in any record'])['total']===0,'history text filtering handles empty results');
    vehicleCheck(vehicleViolationHistory($db,$record,['from'=>date('Y-m-d',strtotime('+1 day'))])['total']===0,'history date filter works');
    vehicleActor($db,1); vehicleReject(fn()=>searchViolationVehicles($db,'plate','ABC'),'resident lookup forbidden');
    vehicleActor($db,7); vehicleReject(fn()=>searchViolationVehicles($db,'plate','ABC'),'treasurer lookup forbidden');
    $manifest=[['path'=>'superadmin/violations.php','actor'=>3],['path'=>'superadmin/violations.php','actor'=>4],['path'=>'superadmin/violations.php','actor'=>5],['path'=>'resident/residentviolation.php','actor'=>1],['path'=>'resident/residentviolation.php','actor'=>2]];
    putenv('CONDO_UI_MANIFEST='.json_encode($manifest));
    $log=tempnam(sys_get_temp_dir(),'condo-vehicle-http-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/ui_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__)); fclose($pipes[0]);
    for ($i=0;$i<50;$i++) { if (@file_get_contents($base.'__manifest')!==false) break; usleep(100000); }
    $page=vehicleHttp('superadmin/violations.php?fixture_actor=3');
    vehicleCheck($page['status']===200 && str_contains($page['body'],'Vehicle &amp; Parking Lookup') && str_contains($page['body'],'vehicleIncidentFields'),'shared security page renders lookup and auto-fill fields');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page['body'],$token);
    vehicleCheck(!empty($token[1]),'report form has CSRF token');
    $api=vehicleHttp('api/violation_vehicles.php?fixture_actor=3&mode=plate&query=ABC-1234');
    vehicleCheck($api['status']===200 && count(json_decode($api['body'],true)['records'])>=1,'security JSON lookup endpoint works');
    vehicleCheck(vehicleHttp('api/violation_vehicles.php?fixture_actor=1&query=ABC')['status']===403,'resident cannot access JSON lookup');
    vehicleCheck(vehicleHttp('api/violation_vehicles.php?fixture_actor=7&query=ABC')['status']===403,'treasurer cannot access JSON lookup');
    $post=array_replace($input,['action'=>'issue','csrf_token'=>'bad']);
    vehicleCheck(vehicleHttp('superadmin/violations.php?fixture_actor=3',$post)['status']===403,'vehicle report POST rejects invalid CSRF');
    $page=vehicleHttp('superadmin/violations.php?fixture_actor=3'); preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page['body'],$token);
    $posted=vehicleHttp('superadmin/violations.php?fixture_actor=3',array_replace($input,['action'=>'issue','csrf_token'=>$token[1]]));
    vehicleCheck($posted['status']===200 && str_contains($posted['body'],'saved as Pending Review'),'security form submits report through actual page');
    $pending=(int)$db->query('SELECT MAX(id) AS id FROM violations')->fetch_assoc()['id'];
    $denied=vehicleHttp('superadmin/violations.php?fixture_actor=3',['action'=>'review_vehicle_report','violation_id'=>$pending,'decision'=>'approve','responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'Forged security approval','csrf_token'=>$token[1]]);
    vehicleCheck($denied['status']===403,'security cannot POST an approval');
    $page=vehicleHttp('superadmin/violations.php?fixture_actor=5'); preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page['body'],$token);
    $approved=vehicleHttp('superadmin/violations.php?fixture_actor=5',['action'=>'review_vehicle_report','violation_id'=>$pending,'decision'=>'approve','responsible_user_id'=>1,'responsibility_verified'=>'1','responsibility_note'=>'HTTP reviewer verified responsibility.','csrf_token'=>$token[1]]);
    vehicleCheck($approved['status']===200 && str_contains($approved['body'],'Report approved.'),'admin approves via actual shared page');
    vehicleCheck(vehicleHttp('resident/residentviolation.php?fixture_actor=1')['status']===200,'resident violation page still renders');
    $page=vehicleHttp('superadmin/violations.php?fixture_actor=3'); preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page['body'],$token);
    $uploadFixture=tempnam(sys_get_temp_dir(),'condo-vehicle-evidence-');
    file_put_contents($uploadFixture,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jO2kAAAAASUVORK5CYII='));
    $evidencePost=vehicleHttp('superadmin/violations.php?fixture_actor=3',array_replace($input,['action'=>'issue','csrf_token'=>$token[1],'evidence_photo'=>new CURLFile($uploadFixture,'image/png','evidence.png')]));
    vehicleCheck($evidencePost['status']===200 && str_contains($evidencePost['body'],'saved as Pending Review'),'security can attach incident evidence through multipart form');
    $evidenceReport=(int)$db->query('SELECT MAX(id) AS id FROM violations')->fetch_assoc()['id'];
    $evidenceName=$db->query("SELECT evidence_path FROM violations WHERE id=$evidenceReport")->fetch_assoc()['evidence_path'];
    vehicleCheck((bool)preg_match('/^[a-f0-9]{40}\.png$/D',$evidenceName) && is_file(dirname(__DIR__).'/private_uploads/violation_evidence/'.$evidenceName),'evidence stored with a private randomized filename');
    $private=vehicleHttp('violation_evidence.php?fixture_actor=1&id='.$evidenceReport);
    vehicleCheck($private['status']===404 && $private['body']==='Not found','unapproved incident evidence is not available to a resident');
    vehicleCheck(vehicleHttp('violation_evidence.php?fixture_actor=3&id='.$evidenceReport)['status']===200,'authorized security can view incident evidence');
    vehicleCheck(ensureVehicleViolationColumns($db) && ensureParkingLookupColumns($db),'migration helpers are idempotent');
    echo "Passed $checks vehicle lookup, history, review, billing and HTTP checks in a disposable database.\n";
    $page=vehicleHttp('superadmin/violations.php?fixture_actor=5'); preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page['body'],$token);
    vehicleCheck(str_contains($page['body'],'name="resident_name"') && str_contains($page['body'],'id="violationResidentNames"') && !str_contains($page['body'],'<select name="user_id"'),'admin receives a typed resident field with suggestions');
    $typedPost=['action'=>'issue','resident_name'=>'  owner current  ','user_id'=>'','violation_type'=>'Unauthorized Parking','description'=>'Typed owner violation fixture','fine_amount'=>1000,'csrf_token'=>$token[1]];
    $typed=vehicleHttp('superadmin/violations.php?fixture_actor=5',$typedPost);
    $saved=$db->query("SELECT user_id FROM violations WHERE description='Typed owner violation fixture'")->fetch_assoc();
    vehicleCheck($typed['status']===200 && str_contains($typed['body'],'added to the resident') && (int)($saved['user_id'] ?? 0)===1,'manual owner name submits and bills the correct account');
    $typed=vehicleHttp('superadmin/violations.php?fixture_actor=4',array_replace($typedPost,['resident_name'=>'Tenant Current','description'=>'Typed tenant violation fixture']));
    $saved=$db->query("SELECT v.user_id,p.user_id AS billing_user FROM violations v JOIN payments p ON p.id=v.payment_id WHERE v.description='Typed tenant violation fixture'")->fetch_assoc();
    vehicleCheck($typed['status']===200 && (int)($saved['user_id'] ?? 0)===2 && (int)$saved['billing_user']===1,'superadmin typed tenant name retains owner billing integration');
    $beforeTyped=(int)$db->query('SELECT COUNT(*) AS n FROM violations')->fetch_assoc()['n'];
    foreach([['resident_name'=>'Unknown Person','user_id'=>1],['resident_name'=>'Owner Current','user_id'=>2],['resident_name'=>'','user_id'=>1]] as $invalidName) {
        $typed=vehicleHttp('superadmin/violations.php?fixture_actor=5',array_replace($typedPost,$invalidName));
        vehicleCheck($typed['status']===200 && str_contains($typed['body'],'alert error') && (int)$db->query('SELECT COUNT(*) AS n FROM violations')->fetch_assoc()['n']===$beforeTyped,'invalid/mismatched typed identity creates no violation or charge');
    }
    if (in_array('--preview',$argv,true)) { echo "Vehicle UI preview: $base\nPress Enter to finish.\n"; fgets(STDIN); }
} finally {
    if (is_resource($http)) { proc_terminate($http); proc_close($http); }
    if ($log && is_file($log)) unlink($log);
    if ($uploadFixture && is_file($uploadFixture)) unlink($uploadFixture);
    if (!preg_match('/^condo_ui_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe vehicle fixture cleanup.');
    if ($server->query("SHOW TABLES LIKE 'violations'")->num_rows) {
        foreach ($server->query('SELECT evidence_path FROM violations WHERE evidence_path IS NOT NULL')->fetch_all(MYSQLI_ASSOC) as $row) {
            if (preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/D',$row['evidence_path'])) {
                $evidence=dirname(__DIR__).'/private_uploads/violation_evidence/'.$row['evidence_path']; if (is_file($evidence)) unlink($evidence);
            }
        }
    }
    $server->query("DROP DATABASE `$database`");
}
