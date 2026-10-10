<?php
/** Real role guards and account lifecycle checks in a disposable database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost', getenv('CONDO_DB_USER') ?: 'root', getenv('CONDO_DB_PASS') ?: '');
$testDb = 'condo_role_test_' . bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
putenv('CONDO_DB_NAME=' . $testDb);
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/scripts/test_authorization.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/resident_accounts.php';
$checks = 0;
function checkAuthorization(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
}
function authorizationActor(mysqli $db, int $id, ?string $spoofedRole = null): void {
    $actor = $db->query('SELECT role, session_version FROM users WHERE id = ' . $id)->fetch_assoc();
    $_SESSION = ['user_id' => $id, 'role' => $spoofedRole ?? $actor['role'], 'last_activity' => time(), 'session_version' => $actor['session_version']];
}
function authorizationRequest(string $page, int $actor, ?array $post = null, array $extra = []): array {
    $input = array_merge(['actor' => $actor], $extra);
    if ($post !== null) $input['post'] = $post;
    $process = proc_open([PHP_BINARY, __DIR__ . '/render_authorization_page.php', $page, json_encode($input, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    preg_match('/FIXTURE_RESPONSE_STATUS=(\d+)$/', $errors, $status);
    $errors = preg_replace('/FIXTURE_RESPONSE_STATUS=\d+$/', '', $errors);
    $expectedError = isset($extra['expected_error']) && trim($errors) === $extra['expected_error'];
    checkAuthorization($exit === 0 && ($errors === '' || $expectedError), 'request executes ' . $page . ': ' . $errors);
    return ['status' => (int)($status[1] ?? 0), 'body' => $body];
}
function authorizationRejects(callable $action, string $label): void {
    try { $action(); } catch (InvalidArgumentException $error) { checkAuthorization(true, $label); return; }
    throw new RuntimeException('FAILED: ' . $label);
}
try {
    $server->select_db($testDb);
    $server->query("CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(100), full_name VARCHAR(100), email VARCHAR(100), contact_number VARCHAR(30), unit_number VARCHAR(30), role VARCHAR(30), password_hash VARCHAR(255), reset_token VARCHAR(255), reset_expires DATETIME, failed_login_attempts INT DEFAULT 0, locked_until DATETIME, is_verified TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $server->query("INSERT INTO users (id,username,full_name,email,unit_number,role) VALUES (1,'resident','Resident','resident@example.invalid','0101','resident'),(2,'applicant','Applicant','applicant@example.invalid','0102','resident'),(3,'security','Security','security@example.invalid',NULL,'security'),(4,'superadmin','SuperAdmin','superadmin@example.invalid',NULL,'superadmin'),(5,'admin','Admin','admin@example.invalid',NULL,'admin'),(6,'treasurer','Treasurer','treasurer@example.invalid',NULL,'treasurer'),(7,'maintenance','Maintenance','maintenance@example.invalid',NULL,'maintenance'),(8,'unverified','Unverified','unverified@example.invalid','0103','resident'),(9,'duplicate','Duplicate','duplicate@example.invalid','0102','resident')");
    $db = connectDb();
    $db->query("UPDATE users SET contact_number = '123'");
    $db->query("UPDATE users SET status = 'pending' WHERE id IN (2,8,9)");
    $db->query('UPDATE users SET is_verified = 0 WHERE id = 8');
    ensurePaymentsTable($db); ensurePaymongoColumns($db); ensureResidentServicesTables($db); ensureParkingTables($db); ensureVisitorLogsTable($db); ensureVisitorLogColumns($db); ensureVehiclesTable($db); ensureStickerVehicleLinks($db); ensureBillingTables($db); ensureMessagesTable($db); ensureBookingsTable($db); ensureMaintenanceTable($db); ensureViolationsTable($db); ensureAnnouncementsTable($db); ensureRememberTokensTable($db);
    ensureNotificationOutboxTable($db);
    foreach ([[3,'superadmin/admin_messages.php'],[6,'superadmin/units.php'],[7,'superadmin/announcements.php'],[5,'superadmin/pending_accounts.php'],[1,'api/admin_messages.php'],[6,'api/admin_dashboard.php'],[7,'api/admin_messages.php'],[3,'superadmin/residents.php'],[1,'superadmin/notification_delivery.php'],[3,'superadmin/notification_delivery.php'],[5,'superadmin/notification_delivery.php'],[6,'superadmin/notification_delivery.php'],[7,'superadmin/notification_delivery.php']] as [$actor, $page]) {
        $result = authorizationRequest($page, $actor, null, ['get' => ['user_id' => 1]]);
        checkAuthorization($result['status'] === 403, 'specialty role cannot access ' . $page);
    }
    foreach ([[5,'superadmin/residents.php'],[5,'superadmin/announcements.php'],[4,'superadmin/pending_accounts.php'],[3,'superadmin/registeredvehicles.php'],[4,'superadmin/notification_delivery.php'],[5,'api/admin_dashboard.php'],[1,'api/dashboard.php']] as [$actor, $page]) {
        $result = authorizationRequest($page, $actor);
        checkAuthorization($result['status'] === 200, 'authorized route responds ' . $page);
    }
    foreach ([3, 4, 5] as $scannerActor) {
        $scannerPath = $scannerActor === 4 ? 'superadmin/scanner.php' : 'security/scanner.php';
        $scannerPage = authorizationRequest($scannerPath, $scannerActor);
        checkAuthorization($scannerPage['status'] === 200 && str_contains($scannerPage['body'], 'id="startScanner"') && str_contains($scannerPage['body'], 'id="manualScanForm"'), 'authorized scanner supports camera and manual verification');
        checkAuthorization(str_contains($scannerPage['body'], '/' . $scannerPath . '" class="sidebar-link active"'), 'scanner sidebar uses the role route and marks it active');
        $scanHistory = authorizationRequest('api/scan_history.php', $scannerActor);
        checkAuthorization($scanHistory['status'] === 200 && (json_decode($scanHistory['body'], true)['success'] ?? false), 'authorized scanner can read history');
    }
    foreach ([1, 6, 7] as $scannerActor) {
        checkAuthorization(authorizationRequest('superadmin/scanner.php', $scannerActor)['status'] === 403, 'other roles cannot access scanner');
        checkAuthorization(authorizationRequest('api/scan_history.php', $scannerActor)['status'] === 403, 'other roles cannot read scan history');
    }
    checkAuthorization(authorizationRequest('api/dashboard.php',2)['status'] === 403, 'pending resident API denied');
    checkAuthorization(authorizationRequest('api/dashboard.php',8)['status'] === 401, 'unverified account cannot authenticate');
    checkAuthorization(authorizationRequest('api/admin_dashboard.php',3,null,['role'=>'superadmin'])['status'] === 403, 'stored session role cannot elevate DB security role');
    checkAuthorization(queueNotification($db,'role-delivery-test','email','resident@example.invalid','Private subject','Private message body',1),'delivery fixture queued');
    $deliveryId=(int)$db->insert_id;
    $db->query("UPDATE notification_outbox SET status='failed',attempts=5 WHERE id=".$deliveryId);
    $deliveryHtml=authorizationRequest('superadmin/notification_delivery.php',4)['body'];
    checkAuthorization(str_contains($deliveryHtml,'Retry delivery') && !str_contains($deliveryHtml,'Private message body'), 'delivery management exposes retry without message contents');
    $deliveryDom=new DOMDocument(); libxml_use_internal_errors(true); $deliveryDom->loadHTML($deliveryHtml); libxml_clear_errors();
    $deliveryXpath=new DOMXPath($deliveryDom);
    checkAuthorization($deliveryXpath->query('//form//input[@name="csrf_token"]')->length===1,'delivery retry form has CSRF');
    checkAuthorization(authorizationRequest('superadmin/notification_delivery.php',4,['notification_id'=>$deliveryId],['invalid_csrf'=>true])['status']===403,'delivery retry rejects forged CSRF');
    checkAuthorization($db->query('SELECT status FROM notification_outbox WHERE id='.$deliveryId)->fetch_assoc()['status']==='failed','forged delivery retry does not alter job');
    authorizationRequest('superadmin/notification_delivery.php',4,['notification_id'=>$deliveryId]);
    checkAuthorization($db->query('SELECT status FROM notification_outbox WHERE id='.$deliveryId)->fetch_assoc()['status']==='pending','delivery retry queues failed notice');
    checkAuthorization((int)$db->query("SELECT COUNT(*) AS n FROM audit_logs WHERE entity_type='notification' AND entity_id=".$deliveryId." AND action='retry'")->fetch_assoc()['n']===1,'delivery retry is audited once');
    authorizationRequest('superadmin/notification_delivery.php',4,['notification_id'=>$deliveryId]);
    checkAuthorization((int)$db->query("SELECT COUNT(*) AS n FROM audit_logs WHERE entity_type='notification' AND entity_id=".$deliveryId." AND action='retry'")->fetch_assoc()['n']===1,'repeated retry does not duplicate audit or reset job');
    authorizationActor($db, 3, 'superadmin');
    checkAuthorization(!canAccess('billing.manage') && $_SESSION['role'] === 'security', 'authorization refreshes role from database');
    foreach ([3,5,6,7] as $actor) {
        authorizationActor($db,$actor); isLoggedIn();
        foreach (array_slice(staffSidebarLinks(),1) as $link) checkAuthorization(roleHasCapability($_SESSION['role'],$link[3]), 'sidebar link permitted for actor ' . $actor);
    }
    $db->query("UPDATE users SET role = 'maintenance' WHERE id = 3");
    checkAuthorization(canAccess('maintenance.work') && !canAccess('security.gate'), 'database role change takes effect in existing session');
    $db->query("UPDATE users SET role = 'security' WHERE id = 3");
    authorizationActor($db,4);
    $db->query("UPDATE users SET unit_number='101' WHERE id=1");
    authorizationRejects(fn()=>approvePendingResident($db,2,'0101'),'legacy unpadded unit cannot be double assigned');
    $db->query("UPDATE users SET unit_number='0101' WHERE id=1");
    authorizationRejects(fn()=>approvePendingResident($db,8,'0103'),'unverified application approval rejected');
    authorizationRejects(fn()=>approvePendingResident($db,2,'9999'),'out of inventory approval rejected');
    authorizationRejects(fn()=>approvePendingResident($db,2,'0101'),'occupied unit approval rejected');
    $applicant = approvePendingResident($db,2,'102');
    checkAuthorization($applicant['unit_number'] === '0102', 'approval normalizes unit');
    checkAuthorization($applicant['session_version'] === 1,'approval returns its committed account version for notification relevance');
    $approved = $db->query('SELECT status, resident_id, session_version FROM users WHERE id=2')->fetch_assoc();
    checkAuthorization($approved['status'] === 'approved' && $approved['resident_id'] === '0102' && (int)$approved['session_version'] === 1, 'approval activates resident assignment and invalidates prior session');
    authorizationRejects(fn()=>approvePendingResident($db,9,'0102'),'second account cannot claim approved unit');
    authorizationActor($db,5);
    authorizationRejects(fn()=>approvePendingResident($db,9,'0103'),'ordinary admin cannot approve resident accounts');
    $db->query("INSERT INTO maintenance_requests(user_id,issue_type,description,status) VALUES(1,'Plumbing','Fixture leak','pending')");
    $requestId = (int)$db->insert_id;
    $attempt = authorizationRequest('superadmin/maintenancerequests.php',7,['request_id'=>$requestId,'action'=>'approve'],['get'=>['view'=>$requestId]]);
    checkAuthorization(str_contains($attempt['body'],'Only management') && $db->query('SELECT status FROM maintenance_requests WHERE id='.$requestId)->fetch_assoc()['status'] === 'pending', 'technician cannot approve pending work');
    checkAuthorization(!str_contains($attempt['body'],'value="approve"'), 'technician has no approval button');
    authorizationRequest('superadmin/maintenancerequests.php',5,['request_id'=>$requestId,'action'=>'approve']);
    checkAuthorization($db->query('SELECT status FROM maintenance_requests WHERE id='.$requestId)->fetch_assoc()['status'] === 'approved', 'management approves work');
    authorizationRequest('superadmin/maintenancerequests.php',7,['request_id'=>$requestId,'action'=>'start'],['invalid_csrf'=>true]);
    checkAuthorization($db->query('SELECT status FROM maintenance_requests WHERE id='.$requestId)->fetch_assoc()['status'] === 'approved', 'CSRF failure prevents starting work');
    authorizationRequest('superadmin/maintenancerequests.php',7,['request_id'=>$requestId,'action'=>'start']);
    checkAuthorization($db->query('SELECT status FROM maintenance_requests WHERE id='.$requestId)->fetch_assoc()['status'] === 'in_progress', 'technician starts approved work');
    authorizationRequest('superadmin/maintenancerequests.php',7,['request_id'=>$requestId,'action'=>'complete','completion_note'=>'Leak repaired']);
    checkAuthorization($db->query('SELECT status FROM maintenance_requests WHERE id='.$requestId)->fetch_assoc()['status'] === 'completed', 'technician completes work without closing resident confirmation');
    checkAuthorization(authorizationRequest('api/notifications.php',1,['action'=>'dismiss_all'],['invalid_csrf'=>true])['status'] === 403, 'notification dismiss requires CSRF');
    checkAuthorization(authorizationRequest('superadmin/violations.php',3,['action'=>'resolve','violation_id'=>1,'decision'=>'waive'])['status'] === 403, 'security cannot forge a fine waiver request');
    checkAuthorization(authorizationRequest('superadmin/violations.php',5)['status'] === 200, 'management can review violations');
    authorizationActor($db,7); $notifications=getNotifications();
    foreach ($notifications as $notification) checkAuthorization(!str_contains($notification['link'],'admin_messages.php') && !str_contains($notification['link'],'unitpayments.php'), 'maintenance notifications respect permissions');
    $db->query("INSERT INTO remember_tokens(user_id,selector,validator_hash,expires_at) VALUES(2,'rolefixture','fixture',DATE_ADD(NOW(),INTERVAL 1 DAY))");
    $today = date('Y-m-d');
    $service = createResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Fixture guest','details'=>'Visit','start_date'=>$today]);
    $db->query("UPDATE resident_service_requests SET status='approved' WHERE id=".$service);
    $db->query("INSERT INTO parking_slots(slot_code,slot_type,status,assigned_user_id,assigned_unit) VALUES('ROLE-R1','resident','occupied',2,'0102')");
    $slotId=(int)$db->insert_id;
    $db->query("INSERT INTO parking_requests(user_id,request_type,vehicle_plate,start_date,end_date,status,slot_id,visitor_registration_id) VALUES(2,'visitor','ROLE123',CURRENT_DATE(),CURRENT_DATE(),'approved',$slotId,$service)");
    $db->query("INSERT INTO bookings(user_id,amenity,booking_date,booking_time,status) VALUES(2,'Swimming Pool',DATE_ADD(CURRENT_DATE(),INTERVAL 1 DAY),'10:00','confirmed')");
    $bookingId=(int)$db->insert_id;
    authorizationActor($db,4);
    checkAuthorization(!setParkingSlotStatus($slotId,'available'), 'inventory cannot free a standing resident assignment');
    checkAuthorization(!setParkingSlotStatus($slotId,'maintenance'), 'inventory cannot disable a slot reserved today');
    $slotCount = (int)$db->query('SELECT COUNT(*) AS n FROM parking_slots')->fetch_assoc()['n'];
    $inventoryHtml = authorizationRequest('superadmin/parkinginventory.php',4)['body'];
    checkAuthorization((int)$db->query('SELECT COUNT(*) AS n FROM parking_slots')->fetch_assoc()['n'] === $slotCount, 'viewing parking inventory does not import slots');
    checkAuthorization(str_contains($inventoryHtml,'Import missing inventory slots'), 'parking import has explicit submit button');
    $dom=new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($inventoryHtml); libxml_clear_errors();
    $xpath=new DOMXPath($dom);
    foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) checkAuthorization($xpath->query('.//input[@name="csrf_token"]',$form)->length === 1,'parking inventory mutation form has CSRF');
    authorizationActor($db,5);
    checkAuthorization(!unassignResidentUnit($db,2,'0103'), 'vacancy requires current unit');
    $db->query("CREATE TRIGGER reject_unit_vacancy BEFORE UPDATE ON resident_service_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated residency revocation failure'");
    checkAuthorization(!unassignResidentUnit($db,2,'0102'), 'vacancy handles linked revocation failure');
    $unchanged = $db->query('SELECT status,unit_number,session_version FROM users WHERE id=2')->fetch_assoc();
    checkAuthorization($unchanged['status']==='approved' && $unchanged['unit_number']==='0102' && (int)$unchanged['session_version']===1, 'vacancy failure rolls back resident assignment and session invalidation');
    checkAuthorization((int)$db->query('SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id=2')->fetch_assoc()['n']===1, 'vacancy failure rolls back remembered login removal');
    $db->query('DROP TRIGGER reject_unit_vacancy');
    checkAuthorization(unassignResidentUnit($db,2,'0102'), 'management can vacate assigned unit');
    $resident=$db->query('SELECT status,unit_number,session_version FROM users WHERE id=2')->fetch_assoc();
    checkAuthorization($resident['status']==='pending' && $resident['unit_number']===null && (int)$resident['session_version']===2, 'vacancy revokes approval and session');
    checkAuthorization((int)$db->query('SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id=2')->fetch_assoc()['n']===0, 'vacancy removes remembered login');
    checkAuthorization($db->query('SELECT status FROM resident_service_requests WHERE id='.$service)->fetch_assoc()['status']==='cancelled', 'vacancy cancels unopened visitor access');
    checkAuthorization($db->query('SELECT status FROM parking_requests WHERE user_id=2')->fetch_assoc()['status']==='cancelled', 'vacancy cancels active parking access');
    checkAuthorization($db->query('SELECT status FROM parking_slots WHERE id='.$slotId)->fetch_assoc()['status']==='available', 'vacancy releases standing slot');
    checkAuthorization($db->query('SELECT status FROM bookings WHERE id='.$bookingId)->fetch_assoc()['status']==='cancelled', 'vacancy cancels future confirmed amenity booking');
    authorizationActor($db,3);
    checkAuthorization(!unassignResidentUnit($db,1,'0101'), 'security cannot clear resident assignment');
    $db->query("UPDATE users SET reset_token='old-recovery', reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=6");
    authorizationRequest('superadmin/staff.php',4,['action'=>'reset_password','staff_id'=>6,'new_password'=>'NewFixture9!']);
    $updatedStaff=$db->query('SELECT reset_token,reset_expires,password_hash,session_version FROM users WHERE id=6')->fetch_assoc();
    checkAuthorization($updatedStaff['reset_token']===null && $updatedStaff['reset_expires']===null && password_verify('NewFixture9!',$updatedStaff['password_hash']), 'management password reset revokes previous recovery links');
    $db->query("UPDATE users SET reset_token='another-recovery', reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=6");
    authorizationRequest('superadmin/staff.php',4,['action'=>'update_role','staff_id'=>6,'role'=>'admin']);
    checkAuthorization($db->query('SELECT reset_token FROM users WHERE id=6')->fetch_assoc()['reset_token']===null, 'role change revokes recovery links');
    $staffCount=(int)$db->query('SELECT COUNT(*) AS n FROM users')->fetch_assoc()['n'];
    $db->query("CREATE TRIGGER reject_staff_label BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.username='rollbackstaff' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated staff label failure'; END IF; END");
    authorizationRequest('superadmin/staff.php',4,['action'=>'add_staff','full_name'=>'Rollback Staff','username'=>'rollbackstaff','email'=>'rollback@example.invalid','contact_number'=>'123','password'=>'FixtureStaff9!','role'=>'security'],['expected_error'=>'Staff account creation failed: Simulated staff label failure']);
    checkAuthorization((int)$db->query('SELECT COUNT(*) AS n FROM users')->fetch_assoc()['n']===$staffCount, 'staff label failure rolls back user creation');
    $db->query('DROP TRIGGER reject_staff_label');
    authorizationRequest('superadmin/staff.php',4,['action'=>'add_staff','full_name'=>'Created Staff','username'=>'createdstaff','email'=>'created@example.invalid','contact_number'=>'123','password'=>'FixtureStaff9!','role'=>'security']);
    $created=$db->query("SELECT id,staff_id FROM users WHERE username='createdstaff'")->fetch_assoc();
    checkAuthorization($created && $created['staff_id']==='STAFF-'.str_pad((string)$created['id'],5,'0',STR_PAD_LEFT), 'staff creation assigns canonical staff ID');
    checkAuthorization((int)$db->query("SELECT COUNT(*) AS n FROM audit_logs WHERE entity_type='staff' AND entity_id=".(int)$created['id']." AND action='create'")->fetch_assoc()['n']===1, 'staff creation saves its audit atomically');
    authorizationActor($db,4);
    $db->query("INSERT INTO parking_slots(slot_code,slot_type,status) VALUES('ROLE-S1','resident','available')");
    $standingSlot=(int)$db->insert_id;
    $vehicleId=createVehicle($db,1,'Toyota','Vios','Silver',2025,'ROLE CAR','private_uploads/vehicle_documents/fixture.pdf','application/pdf');
    checkAuthorization(!assignParkingSlot($standingSlot,1,'0101','ROLE CAR'), 'standing slot cannot accept unapproved vehicle');
    checkAuthorization(decideVehicle($db,$vehicleId,'approved',''), 'fixture standing vehicle approved');
    checkAuthorization(assignParkingSlot($standingSlot,1,'0101','ROLE CAR'), 'standing assignment links approved vehicle');
    checkAuthorization(!assignParkingSlot($standingSlot,2,'0102','ROLE CAR'), 'standing assignment cannot overwrite occupant');
    checkAuthorization((int)$db->query('SELECT parking_slot_id FROM vehicles WHERE id='.$vehicleId)->fetch_assoc()['parking_slot_id']===$standingSlot, 'standing vehicle links to assigned slot');
    checkAuthorization(releaseParkingSlot($standingSlot), 'management releases standing slot');
    checkAuthorization($db->query('SELECT parking_slot_id FROM vehicles WHERE id='.$vehicleId)->fetch_assoc()['parking_slot_id']===null, 'release clears vehicle slot association');
    echo 'Passed ' . $checks . " authorization and lifecycle checks in a disposable database.\n";
} finally {
    if (!preg_match('/^condo_role_test_[a-f0-9]{12}$/D',$testDb)) throw new RuntimeException('Unsafe authorization database cleanup.');
    $server->query('DROP DATABASE `' . $testDb . '`');
}
