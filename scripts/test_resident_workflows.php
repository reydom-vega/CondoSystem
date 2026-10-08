<?php
/** Integration checks use a disposable database; existing condo data is untouched. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost', getenv('CONDO_DB_USER') ?: 'root', getenv('CONDO_DB_PASS') ?: '');
$testDb = 'condo_workflow_test_' . bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
putenv('CONDO_DB_NAME=' . $testDb);
$_SERVER['PHP_SELF'] = '/CondoSystem3/scripts/test_resident_workflows.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config.php';
$checks = 0;
function checkWorkflow(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
}
function expectWorkflowRejection(callable $action, string $label): void {
    try { $action(); } catch (InvalidArgumentException $e) { checkWorkflow(true, $label); return; }
    throw new RuntimeException('FAILED: ' . $label);
}
function workflowTestActor(int $id, string $role): void {
    $_SESSION = ['user_id'=>$id,'role'=>$role,'username'=>'Test ' . $role,'last_activity'=>time(),'session_version'=>0];
}
function postWorkflowFixture(string $page, array $input, bool $validCsrf = true): string {
    $process = proc_open([PHP_BINARY,__DIR__ . '/render_workflow_page.php',$page,json_encode($input),$validCsrf ? 'valid' : 'invalid'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $html = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    checkWorkflow($exit === 0 && $errors === '', 'page submission executes: ' . $page . ': ' . $errors);
    return $html;
}
try {
    $server->select_db($testDb);
    $server->query("CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(100), full_name VARCHAR(100), email VARCHAR(100), contact_number VARCHAR(30), unit_number VARCHAR(30), role VARCHAR(30), is_verified TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $server->query("INSERT INTO users (id,username,full_name,email,unit_number,role) VALUES (1,'resident1','Resident One','one@example.invalid','101','resident'),(2,'resident2','Resident Two','two@example.invalid','102','resident'),(3,'security','Security','security@example.invalid',NULL,'security'),(4,'manager','Manager','manager@example.invalid',NULL,'superadmin')");
    $db = connectDb();
    ensureResidentServicesTables($db);
    ensureVisitorLogsTable($db); ensureVisitorLogColumns($db);
    ensureAmenityBookingSchema($db); ensureParkingTables($db);
    ensureVehiclesTable($db); ensureStickerVehicleLinks($db);
    for ($i=1;$i<=4;$i++) createVehicle($db,$i===3?2:1,'Toyota','Vios','Silver',2025,'TEST'.$i,'private_uploads/vehicle_documents/fixture.pdf','application/pdf');
    workflowTestActor(4,'superadmin');
    for ($i=1;$i<=4;$i++) checkWorkflow(decideVehicle($db,$i,'approved',''),'fixture vehicle approved '.$i);
    workflowTestActor(1,'resident');
    checkWorkflow(buildUrl('resident_service_pass.php') === 'http://localhost/CondoSystem3/resident_service_pass.php', 'application root URL from nested script');
    checkWorkflow(workflowDate('2026-02-28') && !workflowDate('2026-02-30'), 'strict calendar dates');
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $input = ['visitor_name'=>'Guest One','visitor_contact'=>'123','details'=>'Family visit','start_date'=>$today];
    $visitorId = createResidentServiceRequest($db,1,'visitor',$input);
    $permitId = createResidentServiceRequest($db,1,'permit',['permit_type'=>'Renovation','details'=>'Kitchen work','start_date'=>$today,'end_date'=>$tomorrow]);
    expectWorkflowRejection(fn()=>createResidentServiceRequest($db,1,'visitor',array_merge($input,['visitor_name'=>''])), 'visitor name required');
    expectWorkflowRejection(fn()=>createResidentServiceRequest($db,1,'permit',['permit_type'=>'Unknown','details'=>'Work','start_date'=>$today]), 'permit type allowlist');
    checkWorkflow(count(getResidentServiceRequests($db,'visitor',2)) === 0, 'resident isolation');
    workflowTestActor(3,'security');
    checkWorkflow(!decideResidentServiceRequest($db,$permitId,'approved',''), 'security cannot approve permits');
    checkWorkflow(decideResidentServiceRequest($db,$visitorId,'approved','Welcome'), 'security approves visitor registration');
    checkWorkflow(!decideResidentServiceRequest($db,$visitorId,'rejected',''), 'decisions cannot be replayed');
    $visitor = getResidentServiceRequests($db,'visitor',1)[0];
    checkWorkflow(getResidentServicePass($db,$visitorId,str_repeat('0',64)) === null, 'forged visitor token rejected');
    checkWorkflow(verifyAccessScan($db,residentServicePassUrl($visitor))['valid'], 'approved visitor pass verifies');
    checkWorkflow(!verifyAccessScan($db,'https://example.invalid/resident_service_pass.php?id=1')['recognized'], 'foreign QR cannot authorize entry');
    checkWorkflow(checkInRegisteredVisitor($db,$visitorId,$visitor['access_token']), 'visitor check-in creates gate log');
    checkWorkflow(!checkInRegisteredVisitor($db,$visitorId,$visitor['access_token']), 'duplicate check-in rejected');
    $visitor = getResidentServiceRequests($db,'visitor',1)[0];
    checkWorkflow($visitor['status'] === 'checked_in' && $visitor['visitor_log_id'] > 0, 'registration linked to gate log');
    checkWorkflow(logVisitorOut($db,(int)$visitor['visitor_log_id'],3), 'checkout succeeds');
    checkWorkflow(getResidentServiceRequests($db,'visitor',1)[0]['status'] === 'checked_out', 'checkout synchronizes resident status');
    workflowTestActor(4,'superadmin');
    checkWorkflow(decideResidentServiceRequest($db,$permitId,'approved','Authorized'), 'management approves permit');
    checkWorkflow(saveParkingPolicy($db,['sticker_price'=>'1250.50','sticker_max_quantity'=>'3','visitor_max_days'=>'5']), 'parking policy updated');
    checkWorkflow(!saveParkingPolicy($db,['sticker_price'=>'-1','sticker_max_quantity'=>'3','visitor_max_days'=>'5']), 'invalid policy rejected');
    workflowTestActor(1,'resident');
    checkWorkflow(!saveParkingPolicy($db,['sticker_price'=>'1','sticker_max_quantity'=>'1','visitor_max_days'=>'1']), 'resident cannot change policy');
    $billId = purchaseParkingStickers($db,1,2,[1,2]);
    checkWorkflow($billId !== false, 'sticker bill created');
    $order = getLatestParkingStickerOrder(1);
    checkWorkflow((float)$order['amount'] === 2501.0, 'configured sticker price used');
    checkWorkflow(purchaseParkingStickers($db,1,1,[1]) === false, 'duplicate sticker order rejected');
    checkWorkflow((int)$db->query('SELECT COUNT(*) AS n FROM payments')->fetch_assoc()['n'] === 1, 'duplicate order does not leave orphan bill');
    $db->query("CREATE TRIGGER reject_test_sticker BEFORE INSERT ON parking_sticker_orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated claim failure'");
    checkWorkflow(purchaseParkingStickers($db,2,1,[3]) === false, 'claim failure is handled');
    checkWorkflow((int)$db->query('SELECT COUNT(*) AS n FROM payments')->fetch_assoc()['n'] === 1, 'claim failure rolls back the bill');
    checkWorkflow((int)$db->query('SELECT COUNT(*) AS n FROM bill_items')->fetch_assoc()['n'] === 1, 'claim failure rolls back bill items');
    $db->query('DROP TRIGGER reject_test_sticker');
    workflowTestActor(4,'superadmin');
    checkWorkflow(!markParkingStickerIssued((int)$order['id'],4), 'unpaid sticker cannot be issued');
    $db->query('UPDATE payments SET status = \'paid\' WHERE id = ' . (int)$billId);
    checkWorkflow(markParkingStickerIssued((int)$order['id'],4), 'paid sticker issued');
    checkWorkflow(!markParkingStickerIssued((int)$order['id'],4), 'sticker cannot be issued twice');
    $issuedVehicles=getStickerVehicles($db,(int)$order['id']);
    checkWorkflow(count($issuedVehicles)===2 && str_starts_with($issuedVehicles[0]['sticker_number'],'CS-'), 'issuance records a sticker number per selected vehicle');
    checkWorkflow($issuedVehicles[0]['sticker_number']!==$issuedVehicles[1]['sticker_number'], 'sticker numbers are unique');
    workflowTestActor(1,'resident');
    checkWorkflow(purchaseParkingStickers($db,1,1,[3])===false,'cannot buy sticker for another resident vehicle');
    checkWorkflow(purchaseParkingStickers($db,1,1,[1])===false,'issued vehicle cannot get duplicate sticker');
    checkWorkflow(purchaseParkingStickers($db,1,1)===false,'new sticker order requires a vehicle');
    $pendingVehicleId=createVehicle($db,1,'Honda','Civic','Blue',2025,'NEW 123','private_uploads/vehicle_documents/fixture.pdf','application/pdf');
    checkWorkflow(purchaseParkingStickers($db,1,1,[$pendingVehicleId])===false,'pending vehicle cannot get a sticker');
    checkWorkflow((int)$db->query('SELECT COUNT(*) AS n FROM payments')->fetch_assoc()['n']===1,'invalid vehicle selections leave no orphan bills');
    expectWorkflowRejection(fn()=>createVehicle($db,2,'Honda','Civic','Blue',2025,'NEW-123','private_uploads/vehicle_documents/fixture.pdf','application/pdf'),'normalized duplicate plate rejected');
    checkWorkflow(!decideVehicle($db,$pendingVehicleId,'approved',''),'resident cannot approve vehicle');
    workflowTestActor(3,'security');
    checkWorkflow(!decideVehicle($db,$pendingVehicleId,'approved',''),'security cannot approve ownership registration');
    checkWorkflow(!markParkingStickerIssued((int)$order['id'],3),'security cannot issue resident stickers');
    workflowTestActor(4,'superadmin');
    checkWorkflow(!decideVehicle($db,$pendingVehicleId,'rejected',''),'vehicle rejection requires reason');
    postWorkflowFixture('superadmin/registeredvehicles.php',['action'=>'review_vehicle','vehicle_id'=>$pendingVehicleId,'decision'=>'approved']);
    checkWorkflow($db->query('SELECT status FROM vehicles WHERE id='.(int)$pendingVehicleId)->fetch_assoc()['status']==='approved','admin review form approves vehicle');
    $invalidUpload=postWorkflowFixture('resident/vehicles.php',['action'=>'register_vehicle','make'=>'Honda','model'=>'Civic','color'=>'Blue','year'=>'2025','plate_number'=>'UPLOAD1']);
    checkWorkflow(str_contains($invalidUpload,'Upload a valid OR/CR'),'vehicle registration rejects missing multipart document');
    $db->query("INSERT INTO parking_slots (id,slot_code,slot_type) VALUES (1,'V-01','visitor'),(2,'R-01','resident')");
    createParkingRequest(1,'visitor','ABC123','Sedan',$today,$tomorrow);
    $request1 = (int)$db->query('SELECT MAX(id) AS id FROM parking_requests')->fetch_assoc()['id'];
    createParkingRequest(2,'visitor','XYZ456','Sedan',$today,$tomorrow);
    $request2 = (int)$db->query('SELECT MAX(id) AS id FROM parking_requests')->fetch_assoc()['id'];
    checkWorkflow(!decideParkingRequest($request1,'approved',null,''), 'parking approval requires slot');
    checkWorkflow(!decideParkingRequest($request1,'approved',2,''), 'visitor cannot receive resident slot');
    checkWorkflow(decideParkingRequest($request1,'approved',1,''), 'parking reservation approved');
    checkWorkflow(!decideParkingRequest($request2,'approved',1,''), 'overlapping parking reservation rejected');
    checkWorkflow(getParkingPass($request1,'forged') === null, 'forged parking signature rejected');
    checkWorkflow(verifyAccessScan($db,parkingPassUrl($request1))['valid'], 'parking QR verifies');
    $db->query("UPDATE parking_slots SET status = 'maintenance' WHERE id = 1");
    checkWorkflow(!verifyAccessScan($db,parkingPassUrl($request1))['valid'], 'maintenance slot cannot authorize parking');
    $db->query("UPDATE parking_slots SET status = 'available' WHERE id = 1");
    $db->query('UPDATE users SET is_active = 0 WHERE id = 1');
    checkWorkflow(!verifyAccessScan($db,parkingPassUrl($request1))['valid'], 'disabled resident cannot authorize parking');
    checkWorkflow(getResidentServicePass($db,$permitId,getResidentServiceRequests($db,'permit',1)[0]['access_token']) === null, 'disabled resident service pass rejected');
    $db->query('UPDATE users SET is_active = 1 WHERE id = 1');
    checkWorkflow(!createParkingRequest(1,'visitor','TOOLONG','Sedan',$today,date('Y-m-d', strtotime('+5 days'))), 'parking duration includes start date');
    workflowTestActor(1,'resident');
    checkWorkflow(createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'08:00',15), 'pool reservation created');
    expectWorkflowRejection(fn()=>createAmenityBooking($db,2,'Swimming Pool',$tomorrow,'08:00',6), 'pool capacity enforced');
    checkWorkflow(createAmenityBooking($db,2,'Swimming Pool',$tomorrow,'08:00',5), 'remaining pool capacity allowed');
    expectWorkflowRejection(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'08:00',1), 'duplicate pool booking rejected');
    expectWorkflowRejection(fn()=>createAmenityBooking($db,1,'Swimming Pool',$tomorrow,'22:00',1), 'amenity hours enforced');
    checkWorkflow(createAmenityBooking($db,1,'Function Hall',$tomorrow,'08:00',50), 'hall booking created');
    expectWorkflowRejection(fn()=>createAmenityBooking($db,2,'Function Hall',$tomorrow,'10:00',20), 'hall day is exclusive');
    checkWorkflow($db->query("SELECT entity_type FROM audit_logs WHERE entity_type = 'parking_sticker'")->num_rows > 0, 'audit entity types persist correctly');
    workflowTestActor(4,'superadmin');
    $legacyBill=createBill(1,[['category'=>'Parking Sticker','description'=>'Historical sticker','amount'=>1250.50]],null,null,$tomorrow);
    $db->query("UPDATE payments SET status='paid' WHERE id=".(int)$legacyBill);
    $legacyOrder=createParkingStickerOrderForBill(1,(int)$legacyBill,1);
    $db->query("UPDATE parking_sticker_orders SET claim_status='issued',issued_by=4,issued_at='2026-01-01 12:00:00' WHERE id=".(int)$legacyOrder);
    workflowTestActor(4,'superadmin');
    checkWorkflow(!markParkingStickerIssued((int)$legacyOrder,4),'historical order requires vehicle selection');
    checkWorkflow(markParkingStickerIssued((int)$legacyOrder,4,[4]),'historical issued order linked to approved vehicle');
    checkWorkflow($db->query('SELECT issued_at FROM parking_sticker_orders WHERE id='.(int)$legacyOrder)->fetch_assoc()['issued_at']==='2026-01-01 12:00:00','historical issuance timestamp preserved');
    checkWorkflow(!markParkingStickerIssued((int)$legacyOrder,4,[4]),'historical order cannot be linked twice');
    workflowTestActor(1,'resident');
    postWorkflowFixture('resident/parking.php',['form_action'=>'buy_sticker','vehicle_ids'=>[$pendingVehicleId]]);
    $newOrder=getLatestParkingStickerOrder(1);
    checkWorkflow(count(getStickerVehicles($db,(int)$newOrder['id']))===1 && (int)getStickerVehicles($db,(int)$newOrder['id'])[0]['id']===$pendingVehicleId,'resident sticker form creates vehicle-linked order');
    $combinedInput = ['visitor_name'=>'Guest With Car','visitor_contact'=>'123','details'=>'Family visit with vehicle','start_date'=>$today,'needs_parking'=>'1','vehicle_plate'=>'LINK123','vehicle_description'=>'Blue sedan'];
    $combinedId = registerVisitorWithParking($db,1,$combinedInput);
    $linked = $db->query('SELECT * FROM parking_requests WHERE visitor_registration_id = ' . $combinedId)->fetch_assoc();
    checkWorkflow($linked && (int)$linked['user_id'] === 1 && $linked['start_date'] === $today && $linked['end_date'] === $today, 'combined registration links same-day parking');
    checkWorkflow($linked['vehicle_plate'] === 'LINK123', 'linked vehicle data persisted');
    expectWorkflowRejection(fn()=>requestLinkedVisitorParking($db,1,$combinedId,['vehicle_plate'=>'SECOND','start_date'=>$today]), 'duplicate linked request refused');
    expectWorkflowRejection(fn()=>requestLinkedVisitorParking($db,2,$combinedId,['vehicle_plate'=>'FOREIGN','start_date'=>$today]), 'another resident cannot link visitor parking');
    $visitorCount = (int)$db->query('SELECT COUNT(*) AS n FROM resident_service_requests')->fetch_assoc()['n'];
    expectWorkflowRejection(fn()=>registerVisitorWithParking($db,1,array_merge($combinedInput,['vehicle_plate'=>''])), 'combined invalid vehicle rejected');
    checkWorkflow((int)$db->query('SELECT COUNT(*) AS n FROM resident_service_requests')->fetch_assoc()['n'] === $visitorCount, 'invalid vehicle rolls back registration');
    $db->query("CREATE TRIGGER reject_linked_parking BEFORE INSERT ON parking_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated parking failure'");
    try { registerVisitorWithParking($db,1,$combinedInput); throw new RuntimeException('FAILED: parking failure was not raised'); } catch (mysqli_sql_exception $e) { checkWorkflow(true,'combined storage failure surfaced'); }
    checkWorkflow((int)$db->query('SELECT COUNT(*) AS n FROM resident_service_requests')->fetch_assoc()['n'] === $visitorCount, 'parking storage failure rolls back registration');
    $db->query('DROP TRIGGER reject_linked_parking');
    workflowTestActor(3,'security');
    $db->query("INSERT INTO parking_slots (id,slot_code,slot_type) VALUES (3,'V-03','visitor')");
    checkWorkflow(!decideParkingRequest((int)$linked['id'],'approved',3,''), 'parking approval waits for visitor approval');
    checkWorkflow(decideResidentServiceRequest($db,$combinedId,'approved',''), 'combined visitor access approved');
    checkWorkflow(decideParkingRequest((int)$linked['id'],'approved',3,''), 'linked parking approved after visitor');
    checkWorkflow(getParkingPass((int)$linked['id'],parkingPassSignature((int)$linked['id']))['visitor_name'] === 'Guest With Car', 'parking pass includes registered visitor');
    workflowTestActor(1,'resident');
    checkWorkflow(!cancelResidentServiceRequest($db,2,$combinedId,'visitor'), 'other resident cannot cancel registration');
    checkWorkflow(cancelResidentServiceRequest($db,1,$combinedId,'visitor'), 'resident cancels combined registration');
    checkWorkflow($db->query('SELECT status FROM parking_requests WHERE id = ' . (int)$linked['id'])->fetch_assoc()['status'] === 'cancelled', 'registration cancellation cancels parking');
    checkWorkflow(getParkingPass((int)$linked['id'],parkingPassSignature((int)$linked['id'])) === null, 'cancelled linked parking pass refused');
    $rejectedId = registerVisitorWithParking($db,1,$combinedInput);
    workflowTestActor(3,'security');
    checkWorkflow(decideResidentServiceRequest($db,$rejectedId,'rejected','Visit declined'), 'visitor rejected');
    checkWorkflow($db->query('SELECT status FROM parking_requests WHERE visitor_registration_id = ' . $rejectedId)->fetch_assoc()['status'] === 'cancelled', 'rejection cancels linked parking');
    workflowTestActor(1,'resident');
    $eligibleId = registerVisitorWithParking($db,1,['visitor_name'=>'Eligible Guest','details'=>'Tomorrow visit','start_date'=>$tomorrow]);
    expectWorkflowRejection(fn()=>requestLinkedVisitorParking($db,1,$eligibleId,['vehicle_plate'=>'WRONGDAY','start_date'=>$today,'end_date'=>$today]), 'parking period must contain visit date');
    $existingLinkId = requestLinkedVisitorParking($db,1,$eligibleId,['vehicle_plate'=>'EXIST123','start_date'=>$tomorrow]);
    checkWorkflow($existingLinkId > 0, 'parking page links existing registration');
    checkWorkflow(!cancelVisitorParkingRequest($db,2,$existingLinkId), 'other resident cannot cancel parking');
    checkWorkflow(cancelVisitorParkingRequest($db,1,$existingLinkId), 'resident can cancel parking separately');
    checkWorkflow($db->query('SELECT status FROM resident_service_requests WHERE id = ' . $eligibleId)->fetch_assoc()['status'] === 'pending', 'parking cancellation preserves registration');
    $existingLinkId = requestLinkedVisitorParking($db,1,$eligibleId,['vehicle_plate'=>'EXIST123','start_date'=>$tomorrow]);
    checkWorkflow($existingLinkId > 0, 'resident can request parking again after cancellation');
    require_once __DIR__.'/test_vehicle_upload.php';
    testVehicleUploadWorkflow($db);
    registerVisitorWithParking($db,1,['visitor_name'=>'Dropdown Guest','details'=>'Tomorrow visit','start_date'=>$tomorrow]);
    $submittedVisitor = ['visitor_name'=>'Submitted Guest','details'=>'From resident form','start_date'=>$tomorrow,'needs_parking'=>'1','vehicle_plate'=>'FORM123'];
    postWorkflowFixture('resident/visitors.php',$submittedVisitor);
    $submitted = $db->query("SELECT r.id, p.id AS parking_id FROM resident_service_requests r JOIN parking_requests p ON p.visitor_registration_id = r.id WHERE r.visitor_name = 'Submitted Guest'")->fetch_assoc();
    checkWorkflow($submitted && $submitted['parking_id'] > 0, 'visitor page POST creates registration and linked parking');
    postWorkflowFixture('resident/visitors.php',['visitor_name'=>'Parking Page Guest','details'=>'Parking requested later','start_date'=>$tomorrow]);
    $parkingPageVisitorId = (int)$db->query("SELECT id FROM resident_service_requests WHERE visitor_name = 'Parking Page Guest'")->fetch_assoc()['id'];
    postWorkflowFixture('resident/parking.php',['form_action'=>'visitor_request','visitor_registration_id'=>$parkingPageVisitorId,'vehicle_plate'=>'PAGE123','start_date'=>$tomorrow,'end_date'=>$tomorrow]);
    $pageParking = $db->query('SELECT id FROM parking_requests WHERE visitor_registration_id = ' . $parkingPageVisitorId)->fetch_assoc();
    checkWorkflow($pageParking && $pageParking['id'] > 0, 'parking page POST links selected visitor');
    $csrfHtml = postWorkflowFixture('resident/visitors.php',array_merge($submittedVisitor,['visitor_name'=>'Rejected CSRF Guest']),false);
    checkWorkflow(str_contains($csrfHtml,'session token is invalid') && $db->query("SELECT id FROM resident_service_requests WHERE visitor_name = 'Rejected CSRF Guest'")->num_rows === 0, 'resident visitor POST rejects invalid CSRF');
    postWorkflowFixture('resident/parking.php',['form_action'=>'cancel_visitor_request','request_id'=>$pageParking['id']]);
    checkWorkflow($db->query('SELECT status FROM parking_requests WHERE id = ' . (int)$pageParking['id'])->fetch_assoc()['status'] === 'cancelled', 'parking cancellation form updates request');
    $errorHtml = postWorkflowFixture('resident/visitors.php',array_merge($submittedVisitor,['visitor_name'=>'Sticky Guest','vehicle_plate'=>'']));
    checkWorkflow(str_contains($errorHtml,'value="Sticky Guest"') && str_contains($errorHtml,'Enter a plate number'), 'validation preserves visitor input and shows error');
    foreach (['resident/visitors.php','resident/permits.php','resident/parking.php','resident/vehicles.php','resident/book_amenity.php','security/scanner.php','superadmin/service_requests.php','superadmin/registeredvehicles.php','superadmin/parking_configuration.php','superadmin/parking.php','superadmin/bookingrequest.php','resident_service_pass.php'] as $page) {
        $process = proc_open([PHP_BINARY,__DIR__ . '/render_workflow_page.php',$page], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $html = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        checkWorkflow($exit === 0 && str_contains($html,'<!') && $errors === '', 'render ' . $page . ($errors ? ': ' . $errors : ''));
        $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        if ($page === 'resident/visitors.php') {
            foreach (['visitor_name','visitor_contact','start_date','details'] as $field) {
                checkWorkflow($xpath->query('//*[@name="' . $field . '"]/ancestor::label[contains(concat(" ",normalize-space(@class)," ")," field-label ")]')->length === 1, 'visitor field remains visible under resident label CSS: ' . $field);
            }
            checkWorkflow($xpath->query('//input[@name="needs_parking"]')->length === 1 && $xpath->query('//fieldset[@id="visitorParkingFields" and @disabled and @hidden]')->length === 1, 'optional parking fields excluded until selected');
            checkWorkflow($xpath->query('//a[@href="visitors.php" and @aria-current="page"]')->length === 1, 'visitor sidebar active state');
            checkWorkflow(str_contains($html,'EXIST123'), 'visitor history displays linked vehicle');
        }
        if ($page === 'resident/parking.php') {
            checkWorkflow($xpath->query('//select[@name="visitor_registration_id"]')->length === 1, 'parking registration selector renders');
            checkWorkflow(str_contains($html,'Dropdown Guest') && str_contains($html,'Eligible Guest'), 'parking options and request history show visitors');
        }
        foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) {
            checkWorkflow($xpath->query('.//input[@name="csrf_token"]',$form)->length === 1, 'CSRF field in ' . $page);
        }
        $scripts = $xpath->query('//script[not(@src)]');
        foreach ($scripts as $script) {
            $js = tempnam(sys_get_temp_dir(),'condo_js_');
            file_put_contents($js . '.js',$script->textContent);
            $node = proc_open(['node','--check',$js . '.js'],[1=>['pipe','w'],2=>['pipe','w']],$jsPipes);
            $out = stream_get_contents($jsPipes[1]); $err = stream_get_contents($jsPipes[2]);
            fclose($jsPipes[1]); fclose($jsPipes[2]); $code = proc_close($node);
            unlink($js); unlink($js . '.js');
            checkWorkflow($code === 0, 'rendered JavaScript syntax in ' . $page . ': ' . $err);
        }
    }
    echo 'Passed ' . $checks . " integration checks in a disposable database.\n";
} finally {
    if (!preg_match('/^condo_workflow_test_[a-f0-9]{12}$/D',$testDb)) throw new RuntimeException('Unsafe test database cleanup.');
    $server->query('DROP DATABASE `' . $testDb . '`');
}
