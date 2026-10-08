<?php
/** Tenant authorization and owner billing checks in a disposable database; no provider calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Keep the HTTP-like request harness in this file and allow only this suite's database.
if (($argv[1] ?? '') === '--render') {
    if (!preg_match('/^condo_tenant_test_[a-f0-9]{12}$/D', getenv('CONDO_DB_NAME') ?: '')) exit(1);
    $page = $argv[2] ?? '';
    $allowed = ['signup.php', 'signuppending.php', 'parking_pass.php', 'parking_sticker_proof.php', 'resident/dashboard.php', 'resident/payments.php', 'resident/parking.php', 'resident/permits.php', 'resident/visitors.php', 'resident/book_amenity.php', 'resident/vehicles.php', 'resident/maintenance.php', 'resident/messages.php', 'resident/announcements.php', 'resident/residentviolation.php', 'resident/payment_return.php', 'superadmin/residents.php', 'superadmin/units.php', 'superadmin/pending_accounts.php', 'api/dashboard.php', 'api/messages.php'];
    if (!in_array($page, $allowed, true)) exit(1);
    $request = json_decode($argv[3] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/' . $page;
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/' . $page;
    $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = isset($request['post']) ? 'POST' : 'GET';
    require_once __DIR__ . '/../config.php';
    $db = connectDb();
    $id = (int)($request['actor'] ?? 2);
    $actor = $db->query('SELECT role, session_version FROM users WHERE id=' . $id)->fetch_assoc();
    $_SESSION = $actor ? ['user_id' => $id, 'role' => $actor['role'], 'username' => 'Fixture Tenant', 'session_version' => (int)$actor['session_version'], 'last_activity' => time()] : [];
    $_GET = $request['get'] ?? [];
    $_POST = $request['post'] ?? [];
    if (isset($request['post'])) $_POST['csrf_token'] = empty($request['invalid_csrf']) ? workflowCsrfToken() : 'invalid';
    register_shutdown_function(static function () use ($request): void {
        if (!empty($request['capture_session'])) fwrite(STDERR, 'TENANT_RESPONSE_SESSION_VERSION=' . (int)($_SESSION['session_version'] ?? -1) . PHP_EOL);
        fwrite(STDERR, 'TENANT_RESPONSE_STATUS=' . (http_response_code() ?: 200));
    });
    chdir(dirname(__DIR__) . '/' . dirname($page));
    require dirname(__DIR__) . '/' . $page;
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost', getenv('CONDO_DB_USER') ?: 'root', getenv('CONDO_DB_PASS') ?: '');
$testDb = 'condo_tenant_test_' . bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
putenv('CONDO_DB_NAME=' . $testDb); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=1'); putenv('CONDO_MIGRATION_MODE=0');
putenv('CONDO_PAYMONGO_SECRET_KEY='); putenv('CONDO_PAYMONGO_WEBHOOK_SECRET=');
foreach (['AMENITIES', 'VISITORS', 'PARKING', 'VEHICLES', 'MAINTENANCE', 'MESSAGES', 'PERMITS'] as $setting) putenv('CONDO_TENANT_' . $setting . '=' . ($setting === 'PERMITS' ? '0' : '1'));
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/scripts/test_tenant_policy.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__; $_SERVER['HTTP_HOST'] = 'localhost';
$checks = 0;
$exitCode = 0;
function tenantCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
}
function tenantActor(mysqli $db, int $id): void {
    $actor = $db->query('SELECT role, session_version FROM users WHERE id=' . $id)->fetch_assoc();
    $_SESSION = ['user_id' => $id, 'role' => $actor['role'], 'username' => 'Fixture User', 'session_version' => (int)$actor['session_version'], 'last_activity' => time()];
}
function tenantRejects(callable $action, string $label): void {
    try { $action(); } catch (InvalidArgumentException $error) { tenantCheck(true, $label); return; }
    throw new RuntimeException('FAILED: ' . $label);
}
function tenantRequest(string $page, int $actor, ?array $post = null, array $extra = []): array {
    $request = array_merge(['actor' => $actor], $extra);
    if ($post !== null) $request['post'] = $post;
    $process = proc_open([PHP_BINARY, __FILE__, '--render', $page, json_encode($request, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start tenant fixture request.');
    $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    preg_match('/TENANT_RESPONSE_STATUS=(\d+)$/', $errors, $status);
    $errors = preg_replace('/TENANT_RESPONSE_STATUS=\d+$/', '', $errors);
    preg_match('/TENANT_RESPONSE_SESSION_VERSION=(-?\d+)\r?\n/', $errors, $sessionVersion);
    $errors = preg_replace('/TENANT_RESPONSE_SESSION_VERSION=-?\d+\r?\n/', '', $errors);
    tenantCheck($exit === 0 && $errors === '', 'fixture request executes ' . $page . ': ' . $errors);
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'session_version' => isset($sessionVersion[1]) ? (int)$sessionVersion[1] : null];
}
function tenantIdsEqual(array $actual, array $expected): bool {
    $actual = array_map('intval', $actual); sort($actual); sort($expected);
    return $actual === $expected;
}
function tenantXPath(string $html): DOMXPath {
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors();
    return new DOMXPath($dom);
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/resident_accounts.php';
    $server->select_db($testDb);
    $server->query("CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(100), full_name VARCHAR(100), email VARCHAR(100), contact_number VARCHAR(30), unit_number VARCHAR(30), role VARCHAR(30), password_hash VARCHAR(255), is_verified TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $server->query("INSERT INTO users(id,username,full_name,email,unit_number,role) VALUES
        (1,'owner','Unit Owner','owner@example.invalid','101','resident'),
        (2,'tenant','Unit Tenant','tenant@example.invalid','0101','resident'),
        (3,'otherowner','Other Owner','otherowner@example.invalid','0102','resident'),
        (4,'othertenant','Other Tenant','othertenant@example.invalid','0102','resident'),
        (5,'legacy','Legacy Owner','legacy@example.invalid','0103','resident'),
        (6,'orphan','Unlinked Tenant','orphan@example.invalid','0104','resident'),
        (7,'manager','Manager','manager@example.invalid',NULL,'superadmin'),
        (8,'applicant','Applicant Tenant','applicant@example.invalid','0101','resident'),
        (9,'secondtenant','Second Tenant','secondtenant@example.invalid','0101','resident'),
        (10,'duplicateowner','Duplicate Owner','duplicateowner@example.invalid','0101','resident'),
        (11,'relative','Family Occupant','relative@example.invalid','0101','resident'),
        (12,'missingowner','Missing Owner Applicant','missingowner@example.invalid','0105','resident'),
        (13,'unverified','Unverified Applicant','unverified@example.invalid','0101','resident'),
        (14,'unknownkind','Unknown Account Kind','unknownkind@example.invalid','0101','resident'),
        (15,'wrongunit','Cross Unit Tenant','wrongunit@example.invalid','0102','resident'),
        (16,'sibling','Sibling Tenant','sibling@example.invalid','0101','resident'),
        (30,'administrator','Administrator','administrator@example.invalid',NULL,'admin')");
    $db = connectDb();
    $db->query("UPDATE users SET contact_number='09171234567'");
    $db->query("UPDATE users SET account_type='Resident Owner' WHERE id IN(1,3,10)");
    $db->query("UPDATE users SET account_type='Tenant' WHERE id IN(2,4,6,8,9,12,13,15,16)");
    $db->query("UPDATE users SET account_type='Family/Relative of the Owner' WHERE id=11");
    $db->query("UPDATE users SET account_type='Unrecognized' WHERE id=14");
    $db->query("UPDATE users SET unit_owner_id=1 WHERE id IN(2,15,16)");
    $db->query("UPDATE users SET unit_owner_id=3 WHERE id=4");
    $db->query("UPDATE users SET status='pending' WHERE id IN(8,9,10,11,12,13)");
    $db->query('UPDATE users SET is_verified=0 WHERE id=13');
    ensurePaymentsTable($db); ensureBillingTables($db); ensurePaymongoColumns($db); ensureNotificationOutboxTable($db);
    ensureResidentServicesTables($db); ensureParkingTables($db); ensureVisitorLogsTable($db); ensureVisitorLogColumns($db);
    ensureVehiclesTable($db); ensureStickerVehicleLinks($db); ensureAmenityBookingSchema($db); ensureMaintenanceTable($db);
    ensureMessagesTable($db); ensureAnnouncementsTable($db); ensureViolationsTable($db); ensureRememberTokensTable($db);

    $owner = residentContext($db, 1); $tenant = residentContext($db, 2); $legacy = residentContext($db, 5);
    tenantCheck($owner['account_kind'] === 'owner' && $owner['approved'], 'verified unit owner receives owner context');
    tenantCheck($tenant['account_kind'] === 'tenant' && $tenant['approved'] && (int)$tenant['billing_user_id'] === 1, 'tenant receives explicit owner-linked billing context');
    tenantCheck($legacy['account_kind'] === 'owner' && residentCanPayBill($db, 5, 5), 'legacy blank account kind preserves existing unit owner access');
    tenantCheck(!residentContext($db, 6)['approved'], 'tenant without an owner link has no resident access');
    tenantCheck(!residentContext($db, 15)['approved'], 'owner link to a different unit grants no resident access');
    tenantCheck(!residentCanPayBill($db, 14, 14), 'unknown account kind cannot become a bill-paying owner');
    tenantCheck(tenantIdsEqual(residentBillingUserIds($db, 1), [1,2,16]), 'owner can read own and explicitly linked occupant statements');
    tenantCheck(tenantIdsEqual(residentBillingUserIds($db, 2), [1,2]), 'tenant can read own and linked unit owner statements');
    tenantCheck(!in_array(16, residentBillingUserIds($db, 2), true), 'tenant cannot inspect sibling tenant invoices');
    tenantCheck(residentBillingUserIds($db, 6) === [] && residentBillingUserIds($db, 15) === [], 'invalid ownership links reveal no bill scopes');
    tenantCheck(residentCanPayBill($db, 1, 1) && residentCanPayBill($db, 1, 2), 'unit owner can pay unit bills and linked tenant legacy bills');
    tenantCheck(!residentCanPayBill($db, 1, 3) && !residentCanPayBill($db, 1, 4), 'owner cannot pay another unit invoices');
    tenantCheck(!residentCanPayBill($db, 2, 1) && !residentCanPayBill($db, 2, 2) && !residentCanPayBill($db, 7, 1), 'tenant and staff never qualify as resident bill payer');

    tenantActor($db, 2);
    foreach (['resident.billing.view', 'resident.amenities.book', 'resident.visitors.register', 'resident.parking.request', 'resident.vehicles.register', 'resident.maintenance.request', 'resident.messages.use', 'resident.announcements.view', 'resident.violations.view', 'resident.profile.edit'] as $permission) {
        tenantCheck(residentHasPermission($permission), 'default tenant has daily resident permission ' . $permission);
    }
    foreach (['resident.billing.pay', 'resident.stickers.order', 'resident.permits.request', 'resident.unknown'] as $permission) tenantCheck(!residentHasPermission($permission), 'default tenant denied ' . $permission);
    $_SESSION['account_type'] = 'Resident Owner'; $_SESSION['unit_owner_id'] = 3;
    tenantCheck(!residentHasPermission('resident.billing.pay') && (int)residentContext($db,2)['billing_user_id'] === 1, 'session metadata cannot elevate tenant or switch their unit statement');
    foreach (['AMENITIES'=>'resident.amenities.book', 'VISITORS'=>'resident.visitors.register', 'PARKING'=>'resident.parking.request', 'VEHICLES'=>'resident.vehicles.register', 'MAINTENANCE'=>'resident.maintenance.request', 'MESSAGES'=>'resident.messages.use'] as $setting => $permission) {
        putenv('CONDO_TENANT_' . $setting . '=0');
        tenantCheck(!residentHasPermission($permission), 'tenant configuration disables ' . $permission);
        tenantActor($db,1); tenantCheck(residentHasPermission($permission), 'tenant configuration preserves owner ' . $permission);
        tenantActor($db,2); putenv('CONDO_TENANT_' . $setting . '=1');
    }
    putenv('CONDO_TENANT_PERMITS=1');
    tenantCheck(residentHasPermission('resident.permits.request'), 'management may enable tenant permit requests');
    tenantCheck(!residentHasPermission('resident.billing.pay') && !residentHasPermission('resident.stickers.order'), 'tenant permit configuration cannot enable payments or stickers');
    putenv('CONDO_TENANT_PERMITS=0');
    tenantActor($db,7); tenantCheck(!residentHasPermission('resident.billing.view'), 'staff cannot assume tenant resident permissions');

    tenantActor($db,7);
    tenantRejects(fn()=>approvePendingResident($db,10,'0101'), 'second unit owner is rejected while first owner remains assigned');
    tenantRejects(fn()=>approvePendingResident($db,12,'0105'), 'tenant approval requires an existing verified owner');
    tenantRejects(fn()=>approvePendingResident($db,13,'0101'), 'unverified tenant cannot be approved');
    foreach ([8,9,11] as $applicant) {
        approvePendingResident($db,$applicant,'101');
        $approved = $db->query('SELECT status,unit_number,unit_owner_id,session_version FROM users WHERE id=' . $applicant)->fetch_assoc();
        tenantCheck($approved['status']==='approved' && $approved['unit_number']==='0101' && (int)$approved['unit_owner_id']===1 && (int)$approved['session_version']===1, 'approval links occupant to unique unit owner and revokes old session ' . $applicant);
    }
    tenantCheck(residentContext($db,11)['account_kind']==='occupant' && !residentCanPayBill($db,11,1), 'family account remains an occupant without owner payment rights');
    tenantCheck(tenantRequest('parking_sticker_proof.php',2,null,['get'=>['order_id'=>999999]])['status']===403, 'tenant cannot access legacy sticker receipt endpoint with a positive order ID');
    tenantCheck(tenantRequest('parking_sticker_proof.php',11,null,['get'=>['order_id'=>999999]])['status']===403, 'authorized family occupant cannot access legacy sticker receipt endpoint');
    tenantCheck(tenantRequest('parking_sticker_proof.php',1,null,['get'=>['order_id'=>999999]])['status']===404, 'unit owner receives no receipt contents for a nonexistent sticker order');
    tenantActor($db,1);
    tenantRejects(fn()=>approvePendingResident($db,12,'0101'), 'resident owner cannot approve another resident');
    tenantActor($db,7); $db->query('UPDATE users SET is_active=0 WHERE id=1');
    tenantRejects(fn()=>approvePendingResident($db,12,'0101'), 'inactive owner cannot sponsor tenant approval');
    tenantCheck(!residentContext($db,2)['approved'] && !residentCanPayBill($db,1,2), 'inactive owner link immediately revokes tenant and owner payment access');
    $db->query('UPDATE users SET is_active=1,is_verified=0 WHERE id=1');
    tenantCheck(!residentContext($db,2)['approved'], 'unverified owner link immediately revokes tenant access');
    $db->query('UPDATE users SET is_verified=1 WHERE id=1');

    // Build financial fixtures directly: provider credentials remain empty and online uses a saved URL.
    $db->query("INSERT INTO payments(user_id,amount,payment_method,status,due_date) VALUES(1,100,'unbilled','pending',DATE_ADD(CURRENT_DATE(),INTERVAL 15 DAY)),(2,75,'unbilled','pending',DATE_ADD(CURRENT_DATE(),INTERVAL 15 DAY)),(3,50,'unbilled','pending',DATE_ADD(CURRENT_DATE(),INTERVAL 15 DAY))");
    $ownerBill = (int)$db->insert_id; $tenantBill=$ownerBill+1; $otherBill=$ownerBill+2;
    $db->query("INSERT INTO bill_items(payment_id,category,description,amount) VALUES(".$ownerBill.",'Condo Dues','Unit statement',100),(".$tenantBill.",'Water','Legacy tenant statement',75),(".$otherBill.",'Water','Other unit statement',50)");
    $db->query("UPDATE payments SET payment_method='online',paymongo_checkout_id='cs_tenant_fixture',checkout_url='https://checkout.paymongo.com/cs_tenant_fixture' WHERE id=".$ownerBill);
    tenantActor($db,2);
    foreach (['online','bank','cash'] as $method) {
        $attempt=startResidentBillPayment($db,$ownerBill,2,$method,[]);
        tenantCheck(!$attempt['success'] && empty($attempt['checkout_url']), 'tenant cannot initiate or resume owner bill payment using ' . $method);
        tenantCheck(!startResidentBillPayment($db,$tenantBill,2,$method,[])['success'], 'tenant cannot pay even a legacy invoice in their own name using ' . $method);
    }
    $unchanged=$db->query('SELECT payment_method,status FROM payments WHERE id='.$tenantBill)->fetch_assoc();
    tenantCheck($unchanged['payment_method']==='unbilled' && $unchanged['status']==='pending', 'denied tenant payment does not select cash or alter invoice');
    tenantActor($db,1);
    $resume=startResidentBillPayment($db,$ownerBill,1,'online',[]);
    tenantCheck(!empty($resume['resumed']) && $resume['checkout_url']==='https://checkout.paymongo.com/cs_tenant_fixture', 'unit owner can resume their existing checkout without a provider call');
    tenantCheck(startResidentBillPayment($db,$tenantBill,1,'cash',[])['success'], 'unit owner can select cash for their linked tenant legacy bill');
    tenantCheck(!startResidentBillPayment($db,$otherBill,1,'cash',[])['success'], 'unit owner cannot select payment for a different unit');

    $billing = tenantRequest('resident/payments.php',2);
    tenantCheck($billing['status']===200 && str_contains($billing['body'],'Unit statement') && str_contains($billing['body'],'Legacy tenant statement') && !str_contains($billing['body'],'Other unit statement'), 'tenant billing page exposes their unit and own statements only');
    $billingXPath=tenantXPath($billing['body']);
    tenantCheck($billingXPath->query('//input[@name="form_action" and @value="pay_bill"]')->length===0 && !str_contains($billing['body'],'https://checkout.paymongo.com/cs_tenant_fixture'), 'tenant billing page has no payment form or hosted checkout URL');
    tenantCheck(tenantRequest('resident/payments.php',2,['form_action'=>'pay_bill','bill_id'=>$ownerBill,'payment_method'=>'cash'])['status']===403, 'forged tenant payment POST is forbidden');
    $parking=tenantRequest('resident/parking.php',2);
    tenantCheck($parking['status']===200 && tenantXPath($parking['body'])->query('//input[@name="form_action" and @value="buy_sticker"]')->length===0, 'tenant parking page exposes no sticker order form');
    tenantCheck(tenantRequest('resident/parking.php',2,['form_action'=>'buy_sticker','vehicle_ids'=>[1]])['status']===403, 'forged tenant sticker POST is forbidden');
    tenantCheck(tenantRequest('resident/permits.php',2)['status']===403, 'tenant cannot open owner-only permit request page');
    $dashboard=tenantRequest('resident/dashboard.php',2);
    tenantCheck($dashboard['status']===200 && !str_contains($dashboard['body'],'href="permits.php"') && str_contains($dashboard['body'],'href="payments.php"'), 'tenant dashboard retains bills and hides permit navigation');
    putenv('CONDO_TENANT_PARKING=0');
    $visitorWithoutParking=tenantRequest('resident/visitors.php',2);
    $visitorXPath=tenantXPath($visitorWithoutParking['body']);
    tenantCheck($visitorWithoutParking['status']===200 && $visitorXPath->query('//input[@name="visitor_name"]')->length===1 && $visitorXPath->query('//input[@name="needs_parking"]')->length===0 && $visitorXPath->query('//a[starts-with(@href,"parking.php")]')->length===0, 'disabled tenant parking removes integrated visitor parking controls and navigation while keeping visitor registration');
    putenv('CONDO_TENANT_PARKING=1');
    tenantActor($db,2);
    $tenantVehicle=createVehicle($db,2,'Toyota','Vios','Silver',2025,'TENANT CAR','private_uploads/vehicle_documents/fixture.pdf','application/pdf');
    tenantActor($db,7); tenantCheck(decideVehicle($db,$tenantVehicle,'approved',''), 'management can approve tenant vehicle registration');
    $vehiclePage=tenantRequest('resident/vehicles.php',2);
    tenantCheck($vehiclePage['status']===200 && str_contains($vehiclePage['body'],'TENANT CAR') && tenantXPath($vehiclePage['body'])->query('//a[contains(normalize-space(.),"Request sticker")]')->length===0, 'approved tenant vehicle shows registration without sticker request controls');
    foreach (['AMENITIES'=>'resident/book_amenity.php', 'VISITORS'=>'resident/visitors.php', 'VEHICLES'=>'resident/vehicles.php', 'MAINTENANCE'=>'resident/maintenance.php', 'MESSAGES'=>'resident/messages.php'] as $setting=>$page) {
        putenv('CONDO_TENANT_'.$setting.'=0');
        tenantCheck(tenantRequest($page,2)['status']===403, 'disabled tenant feature rejects direct route '.$page);
        putenv('CONDO_TENANT_'.$setting.'=1');
    }

    tenantActor($db,2); $today=date('Y-m-d');
    $tenantService=createResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Tenant guest','details'=>'Tenant visit','start_date'=>$today]);
    tenantCheck(is_int($tenantService), 'tenant can create their own visitor registration');
    tenantRejects(fn()=>createResidentServiceRequest($db,2,'permit',['permit_type'=>'Renovation','details'=>'Kitchen work','start_date'=>$today]), 'tenant cannot bypass permit page through service helper');
    putenv('CONDO_TENANT_VISITORS=0');
    tenantRejects(fn()=>createResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Disabled guest','details'=>'Visit','start_date'=>$today]), 'disabled tenant visitor workflow rejects service helper call');
    putenv('CONDO_TENANT_VISITORS=1');
    tenantActor($db,7); tenantCheck(decideResidentServiceRequest($db,$tenantService,'approved',''), 'management approves tenant visitor registration');
    $tenantPass=$db->query('SELECT access_token FROM resident_service_requests WHERE id='.$tenantService)->fetch_assoc()['access_token'];
    tenantCheck(getResidentServicePass($db,$tenantService,$tenantPass)!==null, 'tenant visitor pass resolves with active verified owner relationship');
    $db->query("INSERT INTO parking_slots(slot_code,slot_type,status) VALUES('TENANT-PASS-V1','visitor','available')");
    $visitorPassSlot=(int)$db->insert_id;
    $db->query("INSERT INTO parking_requests(user_id,request_type,vehicle_plate,start_date,end_date,status,slot_id,visitor_registration_id) VALUES(2,'visitor','TENANTPASS',CURRENT_DATE(),CURRENT_DATE(),'approved',".$visitorPassSlot.",".$tenantService.")");
    $tenantParkingPassId=(int)$db->insert_id;
    $tenantParkingPassQuery=['request_id'=>$tenantParkingPassId,'signature'=>parkingPassSignature($tenantParkingPassId)];
    tenantCheck(tenantRequest('parking_pass.php',2,null,['get'=>$tenantParkingPassQuery])['status']===200, 'tenant can view their approved signed visitor parking pass');
    putenv('CONDO_TENANT_PARKING=0');
    tenantCheck(tenantRequest('parking_pass.php',2,null,['get'=>$tenantParkingPassQuery])['status']===403 && $db->query('SELECT status FROM parking_requests WHERE id='.$tenantParkingPassId)->fetch_assoc()['status']==='approved', 'disabled tenant parking rejects direct signed pass viewing without altering existing request');
    putenv('CONDO_TENANT_PARKING=1');
    tenantCheck(tenantRequest('parking_pass.php',3,null,['get'=>$tenantParkingPassQuery])['status']===404, 'signed tenant parking pass cannot be viewed by a different unit owner');
    $db->query('UPDATE users SET is_active=0 WHERE id=1');
    tenantCheck(getResidentServicePass($db,$tenantService,$tenantPass)===null, 'tenant visitor pass becomes unusable immediately when owner access ends');
    $db->query('UPDATE users SET is_active=1 WHERE id=1');
    tenantActor($db,1);
    $ownerService=createResidentServiceRequest($db,1,'visitor',['visitor_name'=>'Owner guest','details'=>'Owner visit','start_date'=>$today]);
    $db->query("INSERT INTO parking_slots(slot_code,slot_type,status,assigned_user_id,assigned_unit) VALUES('TENANT-OWNER-1','resident','occupied',1,'0101'),('TENANT-OWNER-3','resident','occupied',3,'0102')");
    $ownerSlot=(int)$db->insert_id; $otherSlot=$ownerSlot+1;
    $db->query("INSERT INTO bookings(user_id,amenity,booking_date,booking_time,status) VALUES(1,'Swimming Pool',DATE_ADD(CURRENT_DATE(),INTERVAL 1 DAY),'10:00','confirmed'),(2,'Swimming Pool',DATE_ADD(CURRENT_DATE(),INTERVAL 1 DAY),'11:00','confirmed')");
    $ownerBooking=(int)$db->insert_id; $tenantBooking=$ownerBooking+1;

    // Management screens preserve the owner assignment and end only the selected occupancy.
    $unitsPage=tenantRequest('superadmin/units.php',7);
    $unitsXPath=tenantXPath($unitsPage['body']);
    $ownerUnitRow=$unitsXPath->query('//table[contains(@class,"unit-table")]/tbody/tr[td[1]/strong[normalize-space(.)="0101"]]');
    tenantCheck($unitsPage['status']===200 && $ownerUnitRow->length===1 && $unitsXPath->query('./td[4]/strong[normalize-space(.)="Unit Owner"]',$ownerUnitRow->item(0))->length===1, 'Units shows the real unit owner instead of a linked tenant or family account');
    tenantCheck(str_contains($ownerUnitRow->item(0)->textContent,'5 linked occupant(s)') && $unitsXPath->query('.//input[@name="resident_id" and @value="1"]',$ownerUnitRow->item(0))->length===1, 'Units shows linked occupant count while vacancy action targets the owner account');
    $residentsPage=tenantRequest('superadmin/residents.php',7);
    $residentsXPath=tenantXPath($residentsPage['body']);
    $tenantEndForms=$residentsXPath->query('//form[input[@name="action" and @value="end_tenancy"] and input[@name="resident_id" and @value="9"]]');
    tenantCheck($residentsPage['status']===200 && $tenantEndForms->length===1 && $residentsXPath->query('.//input[@name="csrf_token"]',$tenantEndForms->item(0))->length===1, 'Resident management exposes a CSRF-protected End occupancy button for tenant accounts');
    tenantCheck($residentsXPath->query('//form[input[@name="action" and @value="end_tenancy"] and input[@name="resident_id" and @value="1"]]')->length===0, 'Resident management has no End occupancy button for unit owners');
    tenantCheck(tenantRequest('superadmin/residents.php',2)['status']===403 && tenantRequest('superadmin/units.php',2)['status']===403, 'tenant cannot inspect management resident or unit inventories');
    $endInput=['action'=>'end_tenancy','resident_id'=>9,'unit_number'=>'0101'];
    tenantCheck(tenantRequest('superadmin/residents.php',2,$endInput)['status']===403, 'tenant cannot forge management End occupancy POST');
    tenantCheck(tenantRequest('superadmin/residents.php',7,$endInput,['invalid_csrf'=>true])['status']===403 && residentContext($db,9)['approved'], 'invalid CSRF cannot revoke tenant occupancy');
    tenantRequest('superadmin/residents.php',7,['action'=>'end_tenancy','resident_id'=>9,'unit_number'=>'0102']);
    tenantCheck(residentContext($db,9)['approved'] && (int)residentContext($db,9)['session_version']===1, 'stale management unit input cannot end a different occupancy');
    tenantRequest('superadmin/residents.php',7,['action'=>'end_tenancy','resident_id'=>1,'unit_number'=>'0101']);
    tenantCheck(residentContext($db,1)['approved'] && residentContext($db,9)['approved'] && (int)$db->query('SELECT assigned_user_id FROM parking_slots WHERE id='.$ownerSlot)->fetch_assoc()['assigned_user_id']===1, 'forged End occupancy owner target leaves owner, linked tenant and standing slot unchanged');
    tenantActor($db,9);
    $endingVisitor=createResidentServiceRequest($db,9,'visitor',['visitor_name'=>'Ending tenant guest','details'=>'Tenant visit','start_date'=>$today]);
    $db->query("INSERT INTO parking_requests(user_id,request_type,vehicle_plate,start_date,end_date,status,visitor_registration_id) VALUES(9,'visitor','TENANT9',CURRENT_DATE(),CURRENT_DATE(),'pending',".$endingVisitor.")");
    $endingParking=(int)$db->insert_id;
    $db->query("INSERT INTO remember_tokens(user_id,selector,validator_hash,expires_at) VALUES(9,'tenant-ending-fixture','fixture',DATE_ADD(NOW(),INTERVAL 1 DAY))");
    tenantRequest('superadmin/residents.php',7,$endInput);
    $ended=$db->query('SELECT status,unit_number,unit_owner_id,session_version FROM users WHERE id=9')->fetch_assoc();
    tenantCheck($ended['status']==='pending' && $ended['unit_number']===null && $ended['unit_owner_id']===null && (int)$ended['session_version']===2, 'valid management End occupancy revokes tenant approval, unit link and existing session version');
    tenantCheck($db->query('SELECT status FROM resident_service_requests WHERE id='.$endingVisitor)->fetch_assoc()['status']==='cancelled' && $db->query('SELECT status FROM parking_requests WHERE id='.$endingParking)->fetch_assoc()['status']==='cancelled' && (int)$db->query('SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id=9')->fetch_assoc()['n']===0, 'End occupancy revokes selected tenant visitor access, parking and remembered login');
    tenantCheck(residentContext($db,1)['approved'] && residentContext($db,2)['approved'] && $db->query('SELECT status FROM resident_service_requests WHERE id='.$ownerService)->fetch_assoc()['status']==='pending' && (int)$db->query('SELECT assigned_user_id FROM parking_slots WHERE id='.$ownerSlot)->fetch_assoc()['assigned_user_id']===1, 'End occupancy leaves owner, sibling tenant, owner visitors and standing parking active');
    tenantCheck((int)$db->query("SELECT COUNT(*) AS n FROM audit_logs WHERE action='unassign' AND entity_type='user' AND entity_id=9")->fetch_assoc()['n']===1, 'End occupancy records one management audit');
    tenantRequest('superadmin/residents.php',30,['action'=>'end_tenancy','resident_id'=>11,'unit_number'=>'0101']);
    tenantCheck(!residentContext($db,11)['approved'] && residentContext($db,1)['approved'], 'ordinary management admin can end authorized family occupant access without removing owner');
    $pendingPage=tenantRequest('superadmin/pending_accounts.php',7);
    $pendingXPath=tenantXPath($pendingPage['body']);
    tenantCheck($pendingPage['status']===200 && $pendingXPath->query('//dialog[@id="approveDialog"]//select[@name="assigned_unit" and @required]/option[@value="0101"]')->length===1 && $pendingXPath->query('//dialog[@id="approveDialog"]//input[@name="csrf_token"]')->length===1, 'account approval dialog provides a verified inventory unit selector and CSRF token');
    tenantRequest('superadmin/pending_accounts.php',7,['approve_user_id'=>12,'assigned_unit'=>'0101']);
    tenantCheck(residentContext($db,12)['approved'] && (int)residentContext($db,12)['billing_user_id']===1 && !residentCanPayBill($db,12,1), 'actual account approval POST links pending tenant to selected verified unit owner without payment rights');

    tenantActor($db,7);
    tenantCheck(unassignResidentUnit($db,2,'0101'), 'management can end one tenant residency');
    $vacated=$db->query('SELECT status,unit_number,unit_owner_id,session_version FROM users WHERE id=2')->fetch_assoc();
    tenantCheck($vacated['status']==='pending' && $vacated['unit_number']===null && $vacated['unit_owner_id']===null && (int)$vacated['session_version']===1, 'tenant vacancy removes owner link and revokes sessions');
    tenantCheck($db->query('SELECT status FROM resident_service_requests WHERE id='.$tenantService)->fetch_assoc()['status']==='cancelled' && $db->query('SELECT status FROM bookings WHERE id='.$tenantBooking)->fetch_assoc()['status']==='cancelled', 'tenant vacancy cancels their future access requests');
    tenantCheck($db->query('SELECT status FROM resident_service_requests WHERE id='.$ownerService)->fetch_assoc()['status']==='pending' && $db->query('SELECT status FROM bookings WHERE id='.$ownerBooking)->fetch_assoc()['status']==='confirmed', 'tenant vacancy preserves owner visitors and amenities');
    tenantCheck((int)$db->query('SELECT assigned_user_id FROM parking_slots WHERE id='.$ownerSlot)->fetch_assoc()['assigned_user_id']===1 && residentContext($db,1)['approved'], 'tenant vacancy preserves owner unit and standing parking assignment');
    $db->query("CREATE TRIGGER reject_linked_tenant_vacancy BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.id=4 AND NEW.unit_number IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected tenant cascade failure'; END IF; END");
    tenantCheck(!unassignResidentUnit($db,3,'0102'), 'linked tenant revocation failure prevents partial owner vacancy');
    tenantCheck(residentContext($db,3)['approved'] && residentContext($db,4)['approved'] && (int)$db->query('SELECT assigned_user_id FROM parking_slots WHERE id='.$otherSlot)->fetch_assoc()['assigned_user_id']===3, 'cascade failure preserves owner, tenant and parking together');
    $db->query('DROP TRIGGER reject_linked_tenant_vacancy');
    tenantCheck(unassignResidentUnit($db,3,'0102'), 'management can vacate an owner and their linked occupants');
    foreach ([3,4] as $id) {
        $vacated=$db->query('SELECT status,unit_number,unit_owner_id,session_version FROM users WHERE id='.$id)->fetch_assoc();
        tenantCheck($vacated['status']==='pending' && $vacated['unit_number']===null && $vacated['unit_owner_id']===null && (int)$vacated['session_version']===1, 'owner vacancy revokes account and unit association '.$id);
    }
    tenantCheck($db->query('SELECT status FROM parking_slots WHERE id='.$otherSlot)->fetch_assoc()['status']==='available', 'owner vacancy releases standing slot after linked access revocation');
    tenantCheck(residentContext($db,1)['approved'] && residentContext($db,16)['approved'], 'owner vacancy cannot revoke a different unit household');
    tenantCheck((int)$db->query('SELECT COUNT(*) AS n FROM payments')->fetch_assoc()['n']===3 && $db->query('SELECT status FROM payments WHERE id='.$tenantBill)->fetch_assoc()['status']==='pending', 'residency revocation preserves historical unpaid financial rows');

    // A read never guesses a relationship; an explicit migration may backfill one unique owner.
    $db->query("INSERT INTO users(id,username,full_name,email,unit_number,role,account_type) VALUES
        (17,'backfillowner','Backfill Owner','backfillowner@example.invalid','104','resident','Resident Owner'),
        (18,'ambiguousone','Ambiguous Owner One','ambiguousone@example.invalid','106','resident','Resident Owner'),
        (19,'ambiguoustwo','Ambiguous Owner Two','ambiguoustwo@example.invalid','0106','resident',NULL),
        (20,'ambiguoustenant','Ambiguous Tenant','ambiguoustenant@example.invalid','0106','resident','Tenant')");
    tenantCheck(!residentContext($db,6)['approved'] && $db->query('SELECT unit_owner_id FROM users WHERE id=6')->fetch_assoc()['unit_owner_id']===null, 'matching-unit owner appearance cannot grant an unlinked tenant access on reads');
    putenv('CONDO_MIGRATION_MODE=1');
    tenantCheck(ensureResidentAccountSchema($db), 'explicit tenant relationship migration succeeds');
    $migrated=$db->query('SELECT unit_owner_id,session_version FROM users WHERE id=6')->fetch_assoc();
    tenantCheck((int)$migrated['unit_owner_id']===17 && (int)$migrated['session_version']===1 && residentContext($db,6)['approved'], 'explicit migration links only the unique normalized-unit owner and revokes old session');
    tenantCheck($db->query('SELECT unit_owner_id FROM users WHERE id=20')->fetch_assoc()['unit_owner_id']===null && !residentContext($db,20)['approved'], 'ambiguous existing owner assignments remain unlinked for manual review');
    tenantCheck((int)$db->query('SELECT unit_owner_id FROM users WHERE id=15')->fetch_assoc()['unit_owner_id']===1 && !residentContext($db,15)['approved'], 'migration cannot overwrite an existing cross-unit owner link');
    ensureResidentAccountSchema($db);
    tenantCheck((int)$db->query('SELECT session_version FROM users WHERE id=6')->fetch_assoc()['session_version']===1, 'repeated migration does not relink accounts or revoke sessions again');
    putenv('CONDO_MIGRATION_MODE=0');

    // Signup retains the selected tenant relationship but cannot accept privileged role/link fields.
    $signup=['account_type'=>'Tenant','full_name'=>'Signup Tenant','username'=>'signup_tenant','email'=>'signup_tenant@example.invalid','contact_number'=>'09171234567','unit_number'=>'101','password'=>'FixtureTenant9!','confirm_password'=>'FixtureTenant9!','role'=>'superadmin','unit_owner_id'=>3,'status'=>'approved'];
    tenantRequest('signup.php',0,$signup);
    $signedUp=$db->query("SELECT id,role,account_type,status,unit_number,unit_owner_id FROM users WHERE username='signup_tenant'")->fetch_assoc();
    tenantCheck($signedUp && $signedUp['role']==='resident' && $signedUp['account_type']==='Tenant' && $signedUp['status']==='pending' && $signedUp['unit_number']==='0101' && $signedUp['unit_owner_id']===null, 'signup preserves tenant selection and ignores forged staff role, approval and owner link');
    $signupId=(int)$signedUp['id'];
    tenantCheck(!residentContext($db,$signupId)['approved'] && !residentCanPayBill($db,$signupId,1), 'new tenant receives no service or payment access before management approval');
    tenantActor($db,7); approvePendingResident($db,$signupId,'0101');
    tenantCheck(residentContext($db,$signupId)['approved'] && (int)residentContext($db,$signupId)['billing_user_id']===1 && !residentCanPayBill($db,$signupId,1), 'management approval activates signup tenant with unit owner bill visibility and no payment authority');
    tenantActor($db,$signupId); tenantCheck(residentAccountLabel()==='Tenant', 'approved signup tenant displays tenant account identity');

    // Rejected signup resubmission preserves the selected tenant relationship and revokes old challenges.
    ensurePhoneVerificationColumns($db);
    $db->query("UPDATE users SET status='rejected',rejection_reason='Fixture rejection',reset_token='fixture-recovery',reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR),phone_verified=1,phone_otp='fixture-otp',phone_otp_expires=DATE_ADD(NOW(),INTERVAL 15 MINUTE),phone_otp_attempts=2,phone_otp_sent_at=NOW() WHERE id=".$signupId);
    $resubmitColumns='full_name,username,email,contact_number,role,account_type,status,rejection_reason,unit_number,unit_owner_id,session_version,reset_token,reset_expires,phone_verified,phone_otp,phone_otp_expires,phone_otp_attempts,phone_otp_sent_at';
    $beforeResubmit=$db->query('SELECT '.$resubmitColumns.' FROM users WHERE id='.$signupId)->fetch_assoc();
    $resubmit=['resubmit_application'=>'1','full_name'=>'Resubmitted Tenant','username'=>'resubmit_tenant','email'=>'resubmit_tenant@example.invalid','contact_number'=>'09171234568','unit_number'=>' 102 ','role'=>'superadmin','account_type'=>'Resident Owner','unit_owner_id'=>3,'status'=>'approved'];
    $rejectedPage=tenantRequest('signuppending.php',$signupId);
    tenantCheck($rejectedPage['status']===200 && tenantXPath($rejectedPage['body'])->query('//form//input[@name="csrf_token"]')->length===1, 'rejected tenant resubmission form has CSRF protection');
    tenantCheck(tenantRequest('signuppending.php',$signupId,$resubmit,['invalid_csrf'=>true])['status']===403 && $db->query('SELECT '.$resubmitColumns.' FROM users WHERE id='.$signupId)->fetch_assoc()===$beforeResubmit, 'forged rejected tenant resubmission cannot change identity, unit link or challenges');
    tenantRequest('signuppending.php',$signupId,array_merge($resubmit,['unit_number'=>'9999']));
    tenantCheck($db->query('SELECT '.$resubmitColumns.' FROM users WHERE id='.$signupId)->fetch_assoc()===$beforeResubmit, 'rejected tenant cannot resubmit an out-of-inventory unit');
    tenantRequest('signuppending.php',$signupId,array_merge($resubmit,['full_name'=>str_repeat('A',101)]));
    tenantCheck($db->query('SELECT '.$resubmitColumns.' FROM users WHERE id='.$signupId)->fetch_assoc()===$beforeResubmit, 'oversized resubmitted resident name cannot change the account');
    $resubmittedResponse=tenantRequest('signuppending.php',$signupId,$resubmit,['capture_session'=>true]);
    $resubmitted=$db->query('SELECT '.$resubmitColumns.' FROM users WHERE id='.$signupId)->fetch_assoc();
    tenantCheck($resubmitted['role']==='resident' && $resubmitted['account_type']==='Tenant' && $resubmitted['status']==='pending' && $resubmitted['unit_number']==='0102' && $resubmitted['unit_owner_id']===null && $resubmitted['rejection_reason']===null, 'valid resubmission preserves tenant and resident role, normalizes unit and clears owner link despite forged privilege fields');
    tenantCheck($resubmitted['full_name']==='Resubmitted Tenant' && $resubmitted['username']==='resubmit_tenant' && $resubmitted['email']==='resubmit_tenant@example.invalid' && $resubmitted['contact_number']==='09171234568', 'valid resubmission saves updated resident application details');
    tenantCheck((int)$resubmitted['session_version']===(int)$beforeResubmit['session_version']+1 && $resubmittedResponse['session_version']===(int)$resubmitted['session_version'], 'resubmission invalidates earlier sessions while updating the current session version');
    tenantCheck($resubmitted['reset_token']===null && $resubmitted['reset_expires']===null && (int)$resubmitted['phone_verified']===0 && $resubmitted['phone_otp']===null && $resubmitted['phone_otp_expires']===null && (int)$resubmitted['phone_otp_attempts']===0 && $resubmitted['phone_otp_sent_at']===null, 'resubmission clears recovery and previous phone verification challenges');
    tenantCheck(!residentContext($db,$signupId)['approved'] && !residentCanPayBill($db,$signupId,1), 'resubmitted tenant has no unit services or payment authority before fresh approval');
    $resubmittedPendingPage=tenantRequest('signuppending.php',$signupId);
    tenantCheck($resubmittedPendingPage['status']===200 && str_contains($resubmittedPendingPage['body'],'the unit owner handles payment'), 'pending tenant page explains verified owner linking and owner-only payment');

    echo 'Passed ' . $checks . " tenant policy, account lifecycle and owner billing checks in a disposable database.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (!preg_match('/^condo_tenant_test_[a-f0-9]{12}$/D',$testDb)) throw new RuntimeException('Unsafe tenant fixture database cleanup.');
    $server->query('DROP DATABASE `' . $testDb . '`');
}
exit($exitCode);
