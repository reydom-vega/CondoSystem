<?php
/** Owner-only payment and read-only tenant statements, without provider calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// The renderer can only use this suite's disposable database.
if (($argv[1] ?? '') === '--render') {
    if (!preg_match('/^condo_tenant_bill_test_[a-f0-9]{12}$/D',getenv('CONDO_DB_NAME') ?: '')) exit(1);
    $page=$argv[2] ?? '';
    if (!in_array($page,['resident/payments.php','resident/payment_return.php','resident/payment_receipt.php','api/dashboard.php'],true)) exit(1);
    $request=json_decode($argv[3] ?? '{}',true,512,JSON_THROW_ON_ERROR);
    $_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/CondoSystem3/'.$page;
    $_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/'.$page;
    $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']=isset($request['post']) ? 'POST' : 'GET';
    require_once dirname(__DIR__).'/config.php';
    $db=connectDb(); $actor=(int)($request['actor'] ?? 2);
    $user=$db->query('SELECT role,session_version FROM users WHERE id='.$actor)->fetch_assoc();
    $_SESSION=['user_id'=>$actor,'role'=>$user['role'],'username'=>'Fixture','session_version'=>$user['session_version'],'last_activity'=>time()];
    $_GET=$request['get'] ?? []; $_POST=$request['post'] ?? [];
    if (isset($request['post'])) $_POST['csrf_token']=empty($request['invalid_csrf']) ? workflowCsrfToken() : 'invalid';
    register_shutdown_function(static function():void { fwrite(STDERR,'TENANT_RESPONSE_STATUS='.(http_response_code() ?: 200)); });
    chdir(dirname(__DIR__).'/'.dirname($page)); require dirname(__DIR__).'/'.$page; exit;
}

require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database='condo_tenant_bill_test_'.bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
putenv('CONDO_DB_NAME='.$database); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=0');
putenv('CONDO_APP_URL=http://localhost/CondoSystem3');
putenv('CONDO_PAYMONGO_SECRET_KEY=sk_test_fixture'); putenv('CONDO_PAYMONGO_WEBHOOK_SECRET=whsk_fixture');
$_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/CondoSystem3/scripts/test_tenant_billing.php';
$_SERVER['SCRIPT_FILENAME']=__FILE__; $_SERVER['HTTP_HOST']='localhost';
$checks=0;
function tenantBillCheck(bool $condition,string $label):void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$label); $checks++; }
function tenantBillActor(mysqli $db,int $actor):void {
    $user=$db->query('SELECT role,session_version FROM users WHERE id='.$actor)->fetch_assoc();
    $_SESSION=['user_id'=>$actor,'role'=>$user['role'],'username'=>'Fixture','session_version'=>$user['session_version'],'last_activity'=>time()];
}
function tenantBillRequest(string $page,array $request):array {
    $process=proc_open([PHP_BINARY,__FILE__,'--render',$page,json_encode($request,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
    preg_match('/TENANT_RESPONSE_STATUS=(\d+)/',$errors,$match);
    $errors=preg_replace('/TENANT_RESPONSE_STATUS=\d+/','',$errors);
    tenantBillCheck($code===0 && trim($errors)==='' && isset($match[1]),'request executes '.$page.': '.$errors);
    return ['status'=>(int)$match[1],'body'=>$body];
}
function tenantBillFixture(mysqli $db,int $userId,float $amount,string $status='pending'):int {
    $stmt=$db->prepare("INSERT INTO payments(user_id,amount,payment_method,status,due_date,paid_at) VALUES(?,?,'unbilled',?,CURDATE(),IF(?='paid',NOW(),NULL))");
    $stmt->bind_param('idss',$userId,$amount,$status,$status); $stmt->execute(); $id=(int)$db->insert_id;
    $line=$db->prepare("INSERT INTO bill_items(payment_id,category,description,amount) VALUES(?,'Water','Fixture statement',?)");
    $line->bind_param('id',$id,$amount); $line->execute(); return $id;
}
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database'));
    do { $result=$server->store_result(); if ($result) $result->free(); } while ($server->more_results() && $server->next_result());
    $process=proc_open([PHP_BINARY,__DIR__.'/migrate.php','--apply'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    tenantBillCheck(proc_close($process)===0,'tenant billing schema migration: '.$error);
    require_once dirname(__DIR__).'/config.php'; $db=connectDb();
    $hash=password_hash('Fixture@123',PASSWORD_DEFAULT);
    $users=[
        [1,'0101','Resident Owner',null,'resident','approved'],
        [2,'0101','Tenant',1,'resident','approved'],
        [3,'0101','Family/Relative of the Owner',1,'resident','approved'],
        [4,'0102','Resident Owner',null,'resident','approved'],
        [5,'0103','Tenant',null,'resident','approved'],
        [6,null,null,null,'superadmin','approved'],
        [7,'0101','Tenant',1,'resident','pending'],
        [8,'0104',null,null,'resident','approved'],
        [9,'0102','Tenant',1,'resident','approved'],
    ];
    $insert=$db->prepare('INSERT INTO users(id,full_name,username,email,contact_number,unit_number,account_type,unit_owner_id,password_hash,role,is_verified,status,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,1,?,1)');
    foreach ($users as [$id,$unit,$type,$owner,$role,$status]) {
        $name='Fixture '.$id; $username='fixture'.$id; $email='fixture'.$id.'@example.invalid'; $phone='091700000'.str_pad((string)$id,2,'0',STR_PAD_LEFT);
        $insert->bind_param('issssssisss',$id,$name,$username,$email,$phone,$unit,$type,$owner,$hash,$role,$status); $insert->execute();
    }
    $ownerBill=tenantBillFixture($db,1,125.50); $tenantBill=tenantBillFixture($db,2,40);
    $familyBill=tenantBillFixture($db,3,20); $otherBill=tenantBillFixture($db,4,999);
    $paidOwner=tenantBillFixture($db,1,300,'paid'); $paidTenant=tenantBillFixture($db,2,30,'paid');
    $db->query("UPDATE payments SET paymongo_checkout_id='cs_private_fixture',checkout_url='https://checkout.paymongo.com/cs_private_fixture',paymongo_payment_id='pay_private_fixture',payment_channel='gcash',gateway_status='checkout_session.payment.paid' WHERE id=".$paidOwner);
    tenantBillActor($db,2);
    $visible=getResidentVisibleBills($db,2);
    tenantBillCheck(array_map('intval',array_column($visible,'id'))===[$ownerBill,$tenantBill],'tenant sees owner and own legacy invoices only');
    $history=getResidentVisibleBills($db,2,true);
    tenantBillCheck(count($history)===2,'tenant sees recorded unit payment status');
    foreach (array_merge($visible,$history) as $bill) {
        tenantBillCheck(isset($bill['items'][0]['category']),'tenant statement is itemized');
        foreach (['paymongo_checkout_id','checkout_url','paymongo_payment_id','gateway_status','payment_channel','payment_method'] as $private) tenantBillCheck(!array_key_exists($private,$bill),'tenant projection excludes '.$private);
    }
    tenantBillCheck(getResidentVisibleBills($db,5)===[] && getResidentVisibleBills($db,7)===[] && getResidentVisibleBills($db,9)===[],'unlinked pending and mismatched tenants have no unit statements');
    $summary=getResidentBillingSummary($db,2);
    tenantBillCheck($summary['count']===2 && $summary['amount']===165.50,'tenant summary uses scoped outstanding invoices');
    foreach (['cash','online','bank'] as $method) {
        tenantBillCheck(!startResidentBillPayment($db,$ownerBill,2,$method,[])['success'],'tenant cannot initiate '.$method.' for owner');
        tenantBillCheck(!startResidentBillPayment($db,$tenantBill,2,$method,[])['success'],'tenant cannot initiate '.$method.' for legacy invoice');
    }
    tenantBillCheck(!startResidentBillPayment($db,$ownerBill,1,'cash',[])['success'],'tenant cannot pass owner id to payer helper');
    tenantBillCheck(!reconcilePaymongoCheckoutPayment($db,$ownerBill,2),'tenant cannot initiate checkout reconciliation');
    $adapter=createPaymongoCheckoutSession($ownerBill,[['category'=>'Water','amount'=>125.50]],'Fixture','Fixture','fixture2@example.invalid','http://localhost/success','http://localhost/cancel');
    tenantBillCheck(!$adapter['success'] && str_contains($adapter['error'],'unit owner'),'direct provider adapter denies tenant before network');
    tenantBillCheck($db->query('SELECT payment_method FROM payments WHERE id='.$tenantBill)->fetch_assoc()['payment_method']==='unbilled','denied actions do not change payment method');
    $page=tenantBillRequest('resident/payments.php',['actor'=>2]);
    tenantBillCheck($page['status']===200 && str_contains($page['body'],'Fixture statement') && str_contains($page['body'],'125.50'),'tenant billing page displays statements');
    tenantBillCheck(!str_contains($page['body'],'name="payment_method"') && !str_contains($page['body'],'name="form_action"') && !str_contains($page['body'],'history-receipt-link'),'tenant page has no payment forms or receipt buttons');
    tenantBillCheck(!str_contains($page['body'],'pay_private_fixture') && !str_contains($page['body'],'cs_private_fixture') && !str_contains($page['body'],'fixture1@example.invalid'),'tenant HTML excludes gateway data and owner email');
    $forged=tenantBillRequest('resident/payments.php',['actor'=>2,'post'=>['form_action'=>'pay_bill','bill_id'=>$ownerBill,'payment_method'=>'cash']]);
    tenantBillCheck($forged['status']===403,'forged tenant payment POST denied');
    $csrf=tenantBillRequest('resident/payments.php',['actor'=>1,'post'=>['form_action'=>'pay_bill','bill_id'=>$ownerBill,'payment_method'=>'cash'],'invalid_csrf'=>true]);
    tenantBillCheck($csrf['status']===403,'owner payment POST requires CSRF');
    foreach (['resident/payment_return.php'=>['payment_id'=>$ownerBill,'status'=>'success'],'resident/payment_receipt.php'=>['id'=>$paidOwner]] as $route=>$get) {
        tenantBillCheck(tenantBillRequest($route,['actor'=>2,'get'=>$get])['status']===403,'tenant forbidden route '.$route);
    }
    $api=tenantBillRequest('api/dashboard.php',['actor'=>2]);
    tenantBillCheck($api['status']===200 && json_decode($api['body'],true)['due_payments']===2,'dashboard API counts unit statements without unrelated bills');
    tenantBillActor($db,1);
    tenantBillCheck(count(getResidentVisibleBills($db,1))===3,'owner can review linked occupant legacy bills');
    tenantBillCheck(startResidentBillPayment($db,$tenantBill,1,'cash',[])['success'],'owner can select cash for linked legacy tenant bill');
    tenantBillCheck(!startResidentBillPayment($db,$otherBill,1,'cash',[])['success'],'owner cannot pay other unit bill');
    $ownerPage=tenantBillRequest('resident/payments.php',['actor'=>1]);
    tenantBillCheck(str_contains($ownerPage['body'],'name="payment_method"') && str_contains($ownerPage['body'],'history-receipt-link'),'owner retains payment and receipt controls');
    $db->query("UPDATE payments SET paymongo_checkout_id='cs_legacy_fixture',checkout_url='https://checkout.paymongo.com/cs_legacy_fixture',payment_method='online' WHERE id=".$tenantBill);
    tenantBillActor($db,2);
    tenantBillCheck(!startResidentBillPayment($db,$tenantBill,2,'online',[])['success'],'tenant cannot resume saved checkout');
    tenantBillActor($db,1);
    tenantBillCheck(!empty(startResidentBillPayment($db,$tenantBill,1,'online',[])['resumed']),'owner can resume linked legacy checkout');
    $context=['internal_payment_id'=>$tenantBill,'checkout_id'=>'cs_legacy_fixture','paymongo_payment_id'=>'pay_legacy_fixture','payment_channel'=>'gcash','amount'=>4000,'currency'=>'PHP','payment_status'=>'paid','event_type'=>'checkout_session.payment.paid','livemode'=>false];
    $_SESSION=[];
    tenantBillCheck(!empty(confirmPaymongoCheckoutPayment($db,$context)['confirmed']),'provider settlement of pre-existing tenant checkout remains valid');
    tenantBillCheck(!empty(confirmPaymongoCheckoutPayment($db,$context)['already_paid']),'provider legacy settlement remains idempotent');
    tenantBillActor($db,6);
    $new=createBill(2,[['category'=>'Water','amount'=>55]],null,null,date('Y-m-d'));
    tenantBillCheck(is_int($new) && (int)$db->query('SELECT user_id FROM payments WHERE id='.$new)->fetch_assoc()['user_id']===1,'new tenant charges resolve liability to approved owner');
    tenantBillCheck(createBill(5,[['category'=>'Water','amount'=>55]],null,null,date('Y-m-d'))===false,'unlinked tenant cannot acquire new invoice');
    $month=date('Y-m-01'); $monthly=generateStandardMonthlyBills(['Condo Dues'=>100],$month,date('Y-m-t'),date('Y-m-d'));
    tenantBillCheck($monthly['created']===3,'monthly generation bills owners once and includes blank-type legacy owner');
    tenantBillCheck((int)$db->query("SELECT COUNT(*) n FROM payments p JOIN users u ON u.id=p.user_id WHERE p.billing_period_start='".$month."' AND u.account_type='Tenant'")->fetch_assoc()['n']===0,'monthly statements are not billed to tenants');
    $repeat=generateStandardMonthlyBills(['Condo Dues'=>100],$month,date('Y-m-t'),date('Y-m-d'));
    tenantBillCheck($repeat['created']===0 && $repeat['skipped']===3,'owner monthly deduplication is preserved');
    $citation=issueViolation(2,'Fixture Tenant Fine','Tenant citation','fine',75,date('Y-m-d'),6);
    tenantBillCheck(is_int($citation),'management can cite an approved linked tenant');
    $fine=$db->query('SELECT v.user_id,v.payment_id,v.status,p.user_id AS bill_user_id,p.amount FROM violations v JOIN payments p ON p.id=v.payment_id WHERE v.id='.(int)$citation)->fetch_assoc();
    tenantBillCheck((int)$fine['user_id']===2 && (int)$fine['bill_user_id']===1 && (float)$fine['amount']===75.0,'tenant citation remains personal while invoice belongs to unit owner');
    tenantBillActor($db,2);
    tenantBillCheck(!startResidentBillPayment($db,(int)$fine['payment_id'],2,'cash',[])['success'],'cited tenant cannot pay owner fine invoice');
    tenantBillCheck(disputeViolation((int)$citation,2,'Please review the cited tenant record'),'tenant may dispute own owner-billed citation');
    tenantBillActor($db,6);
    tenantBillCheck(resolveViolation((int)$citation,'reject_dispute'),'management reviews tenant citation independently of bill owner');
    tenantBillActor($db,1);
    tenantBillCheck(startResidentBillPayment($db,(int)$fine['payment_id'],1,'cash',[])['success'],'unit owner may select payment for tenant fine');
    tenantBillActor($db,6);
    tenantBillCheck(confirmCashBillPayment($db,(int)$fine['payment_id']),'billing staff settle tenant fine on owner invoice');
    tenantBillCheck($db->query('SELECT status FROM violations WHERE id='.(int)$citation)->fetch_assoc()['status']==='paid','owner payment settles the linked tenant citation');
    $legacyReceiptJobs=$db->query("SELECT user_id,channel,recipient,body FROM notification_outbox WHERE event_kind='payment_receipt' AND entity_id=".$tenantBill)->fetch_all(MYSQLI_ASSOC);
    tenantBillCheck(count($legacyReceiptJobs)===2,'legacy tenant payment confirmation queues both owner channels');
    foreach ($legacyReceiptJobs as $job) tenantBillCheck((int)$job['user_id']===1 && ($job['channel']==='email' ? $job['recipient']==='fixture1@example.invalid' : $job['recipient']===normalizePhDigits('09170000001')) && str_contains($job['body'],'40.00'),'legacy receipt targets current linked owner with preserved invoice amount');
    tenantBillCheck(notifyResidentOfNewBill((int)$new),'new owner-liable tenant charge queues financial notice');
    $legacyReminderBill=tenantBillFixture($db,2,60);
    tenantBillCheck(notifyResidentOfNewBill($legacyReminderBill),'legacy tenant invoice queues statement to vetted owner');
    foreach ([(int)$new,$legacyReminderBill] as $billId) {
        $jobs=$db->query("SELECT user_id,channel,recipient,body FROM notification_outbox WHERE event_kind='bill_notice' AND entity_id=".$billId)->fetch_all(MYSQLI_ASSOC);
        tenantBillCheck(count($jobs)===2,'statement notification has owner email and SMS channels');
        foreach ($jobs as $job) tenantBillCheck((int)$job['user_id']===1 && ($job['channel']==='email' ? $job['recipient']==='fixture1@example.invalid' : $job['recipient']===normalizePhDigits('09170000001')),'new and legacy financial notice targets owner');
    }
    $unlinkedInvoice=tenantBillFixture($db,5,88);
    $reminders=sendDueDateReminders(0);
    tenantBillCheck($reminders['emails_queued']>0 && $reminders['sms_queued']>0,'due reminders enqueue usable owner financial channels');
    $legacyReminderJobs=$db->query("SELECT * FROM notification_outbox WHERE event_kind='due_reminder' AND entity_id=".$legacyReminderBill)->fetch_all(MYSQLI_ASSOC);
    tenantBillCheck(count($legacyReminderJobs)===2,'linked legacy tenant reminder queues owner channels');
    foreach ($legacyReminderJobs as $job) tenantBillCheck((int)$job['user_id']===1 && ($job['channel']==='email' ? $job['recipient']==='fixture1@example.invalid' : $job['recipient']===normalizePhDigits('09170000001')) && notificationStillRelevant($db,$job),'legacy tenant reminder remains relevant for owner recipient');
    tenantBillCheck((int)$db->query("SELECT COUNT(*) n FROM notification_outbox WHERE event_kind='due_reminder' AND entity_id=".$unlinkedInvoice)->fetch_assoc()['n']===0,'unlinked tenant invoice is withheld for management review');
    tenantBillCheck($db->query('SELECT reminder_sent_at FROM payments WHERE id='.$unlinkedInvoice)->fetch_assoc()['reminder_sent_at']===null,'withheld reminder leaves unlinked legacy invoice eligible for later review');
    $delivered=[];
    $worker=processNotificationOutbox($db,100,static function(array $job) use (&$delivered):bool { $delivered[]=$job; return true; });
    $deliveredLegacy=array_values(array_filter($delivered,static fn(array $job):bool=>$job['event_kind']==='due_reminder' && (int)$job['entity_id']===$legacyReminderBill));
    tenantBillCheck($worker['sent']>0 && count($deliveredLegacy)===2,'mocked worker accepts owner-targeted legacy tenant reminders without providers');
    $legacySnapshot=$db->query('SELECT user_id,amount,status FROM payments WHERE id='.$legacyReminderBill)->fetch_assoc();
    tenantBillCheck(queueNotification($db,'legacy_link_terminated','email','fixture1@example.invalid','Fixture stale reminder','Fixture reminder',1,'due_reminder',$legacyReminderBill),'enqueue stale legacy reminder fixture');
    $db->query('UPDATE users SET unit_owner_id=NULL WHERE id=2');
    $beforeDeliveries=count($delivered);
    $stale=processNotificationOutbox($db,100,static function(array $job) use (&$delivered):bool { $delivered[]=$job; return true; });
    tenantBillCheck($stale['cancelled']===1 && count($delivered)===$beforeDeliveries,'worker cancels financial reminder after approved owner link is removed');
    // Management must review unresolved legacy invoices before terminating an
    // occupancy link; ending access never transfers or rewrites financial rows.
    tenantBillCheck($db->query('SELECT user_id,amount,status FROM payments WHERE id='.$legacyReminderBill)->fetch_assoc()===$legacySnapshot,'ending a tenant link preserves original legacy invoice liability and amount');
    $db->query('UPDATE users SET unit_owner_id=1 WHERE id=2');
    $pendingInvoice=tenantBillFixture($db,7,33);
    $pendingPaidInvoice=tenantBillFixture($db,7,44,'paid');
    tenantBillCheck(billingNoticeContact($db,7)===null,'pending tenant cannot resolve a financial notification contact');
    tenantBillCheck(!notifyResidentOfNewBill($pendingInvoice),'pending tenant statement notification is denied');
    tenantBillCheck(!notifyResidentOfPayment($pendingPaidInvoice),'pending tenant payment receipt notification is denied');
    tenantBillCheck((int)$db->query('SELECT COUNT(*) n FROM notification_outbox WHERE entity_id IN ('.$pendingInvoice.','.$pendingPaidInvoice.") AND event_kind IN ('bill_notice','payment_receipt')")->fetch_assoc()['n']===0,'pending tenant financial notifications create no owner or tenant jobs');
    $receiptDedupInvoice=tenantBillFixture($db,2,44,'paid');
    $noticeDedupInvoice=tenantBillFixture($db,2,77);
    $dedupInvoiceSnapshot=$db->query('SELECT user_id,amount,status FROM payments WHERE id='.$noticeDedupInvoice)->fetch_assoc();
    $oldFinancialEvents=[
        ['payment_receipt:'.$receiptDedupInvoice,'payment_receipt',$receiptDedupInvoice],
        ['new_bill:'.$noticeDedupInvoice,'bill_notice',$noticeDedupInvoice],
        ['due:'.$noticeDedupInvoice.':'.date('Y-m-d'),'due_reminder',$noticeDedupInvoice],
    ];
    foreach ($oldFinancialEvents as [$key,$kind,$entity]) tenantBillCheck(queueNotification($db,$key,'email','fixture2@example.invalid','Old tenant financial notice','Fixture legacy job',2,$kind,$entity),'seed historical tenant queue key '.$kind);
    tenantBillCheck(notifyResidentOfPayment($receiptDedupInvoice),'old tenant receipt key does not block current owner enqueue');
    tenantBillCheck(notifyResidentOfNewBill($noticeDedupInvoice),'old tenant statement key does not block current owner enqueue');
    sendDueDateReminders(0);
    foreach ([$receiptDedupInvoice=>'payment_receipt',$noticeDedupInvoice=>'bill_notice'] as $entity=>$kind) {
        $jobs=$db->query("SELECT user_id,channel FROM notification_outbox WHERE entity_id=".$entity." AND event_kind='".$kind."'")->fetch_all(MYSQLI_ASSOC);
        tenantBillCheck(count($jobs)===3 && count(array_filter($jobs,static fn(array $job):bool=>(int)$job['user_id']===1))===2,'historical tenant key coexists with distinct owner email and SMS '.$kind);
    }
    $reminderJobs=$db->query("SELECT user_id,channel FROM notification_outbox WHERE entity_id=".$noticeDedupInvoice." AND event_kind='due_reminder'")->fetch_all(MYSQLI_ASSOC);
    tenantBillCheck(count($reminderJobs)===3 && count(array_filter($reminderJobs,static fn(array $job):bool=>(int)$job['user_id']===1))===2,'historical tenant reminder key does not block distinct owner enqueue');
    $beforeDedup=(int)$db->query('SELECT COUNT(*) n FROM notification_outbox')->fetch_assoc()['n'];
    notifyResidentOfPayment($receiptDedupInvoice); notifyResidentOfNewBill($noticeDedupInvoice); sendDueDateReminders(0);
    tenantBillCheck((int)$db->query('SELECT COUNT(*) n FROM notification_outbox')->fetch_assoc()['n']===$beforeDedup,'repeating current owner financial enqueue does not duplicate jobs');
    $oldReceipt=$db->query("SELECT * FROM notification_outbox WHERE event_kind='payment_receipt' AND entity_id=".$receiptDedupInvoice.' AND user_id=2')->fetch_assoc();
    tenantBillCheck(!notificationStillRelevant($db,$oldReceipt),'historical receipt job addressed to tenant is obsolete');
    $deliveredFinancial=[];
    $migrationWorker=processNotificationOutbox($db,100,static function(array $job) use (&$deliveredFinancial):bool { $deliveredFinancial[]=$job; return true; });
    tenantBillCheck($migrationWorker['cancelled']===3 && $migrationWorker['sent']===6,'mocked worker cancels old tenant financial jobs while sending fresh owner jobs');
    tenantBillCheck(count($deliveredFinancial)===6 && count(array_filter($deliveredFinancial,static fn(array $job):bool=>(int)$job['user_id']===1))===6,'only current owner financial jobs reach the injected sender');
    tenantBillCheck($db->query('SELECT user_id,amount,status FROM payments WHERE id='.$noticeDedupInvoice)->fetch_assoc()===$dedupInvoiceSnapshot,'notification migration preserves legacy tenant invoice record');
    $db->query("INSERT INTO vehicles(user_id,make,model,color,year,plate_number,normalized_plate,or_cr_path,or_cr_mime,status) VALUES(2,'Fixture','Tenant Car','Blue',2020,'TEN100','TEN100','fixture-only.pdf','application/pdf','approved'),(4,'Fixture','Other Car','Gray',2020,'OTH100','OTH100','fixture-only.pdf','application/pdf','approved'),(2,'Fixture','Legacy Car','Red',2020,'TEN200','TEN200','fixture-only.pdf','application/pdf','approved')");
    $vehicleIds=[]; foreach ($db->query("SELECT id,plate_number FROM vehicles") as $vehicle) $vehicleIds[$vehicle['plate_number']]=(int)$vehicle['id'];
    tenantBillActor($db,2);
    tenantBillCheck(purchaseParkingStickers($db,2,1,[$vehicleIds['TEN100']])===false,'tenant cannot create a sticker bill');
    tenantBillActor($db,1);
    $before=(int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n'];
    tenantBillCheck(purchaseParkingStickers($db,1,1,[$vehicleIds['OTH100']])===false,'owner cannot sponsor unrelated unit vehicle');
    tenantBillCheck((int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n']===$before,'invalid sponsorship rolls back sticker bill');
    $stickerBill=purchaseParkingStickers($db,1,1,[$vehicleIds['TEN100']]);
    tenantBillCheck(is_int($stickerBill),'owner may sponsor approved linked tenant car');
    $stickerOrder=$db->query('SELECT id,user_id FROM parking_sticker_orders WHERE bill_payment_id='.(int)$stickerBill)->fetch_assoc();
    tenantBillCheck((int)$stickerOrder['user_id']===1 && (int)$db->query('SELECT user_id FROM payments WHERE id='.(int)$stickerBill)->fetch_assoc()['user_id']===1,'sponsored sticker bill and order remain owner liable');
    tenantBillCheck(startResidentBillPayment($db,(int)$stickerBill,1,'cash',[])['success'],'owner selects payment for sponsored vehicle sticker');
    tenantBillActor($db,6);
    tenantBillCheck(confirmCashBillPayment($db,(int)$stickerBill),'billing staff settle sponsored sticker charge');
    $db->query('UPDATE users SET unit_owner_id=NULL WHERE id=2');
    tenantBillCheck(!markParkingStickerIssued((int)$stickerOrder['id'],6),'sticker issuance rejects a revoked sponsorship relationship');
    tenantBillCheck($db->query('SELECT claim_status FROM parking_sticker_orders WHERE id='.(int)$stickerOrder['id'])->fetch_assoc()['claim_status']==='not_submitted','failed sponsorship check preserves paid unissued claim');
    $db->query('UPDATE users SET unit_owner_id=1 WHERE id=2');
    tenantBillCheck(markParkingStickerIssued((int)$stickerOrder['id'],6),'management issues sponsored sticker after validating linked tenant vehicle');
    $legacyStickerBill=tenantBillFixture($db,2,1000,'paid');
    $db->query("INSERT INTO parking_sticker_orders(user_id,quantity,amount,bill_payment_id,status) VALUES(2,1,1000,".$legacyStickerBill.",'paid')");
    $legacyOrder=(int)$db->insert_id;
    tenantBillCheck(markParkingStickerIssued($legacyOrder,6,[$vehicleIds['TEN200']]),'management can bind and issue pre-existing paid tenant sticker order');
    tenantBillCheck((int)$db->query('SELECT user_id FROM parking_sticker_orders WHERE id='.$legacyOrder)->fetch_assoc()['user_id']===2 && (int)$db->query('SELECT user_id FROM payments WHERE id='.$legacyStickerBill)->fetch_assoc()['user_id']===2,'legacy sticker issuance preserves original financial account');
    tenantBillCheck(!markParkingStickerIssued($legacyOrder,6,[$vehicleIds['TEN200']]),'legacy issuance cannot replay');
    $db->query('UPDATE users SET is_active=0 WHERE id=1');
    tenantBillCheck(getResidentVisibleBills($db,2)===[] && !residentCanPayBill($db,1,2),'revoked unit owner removes tenant billing scope and payer rights');
    echo 'Passed '.$checks." tenant billing checks in a disposable database; no provider calls.\n";
} finally {
    if (!preg_match('/^condo_tenant_bill_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture cleanup.');
    $server->query('DROP DATABASE `'.$database.'`');
}
