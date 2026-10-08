<?php
/** No provider calls: signed fixtures and financial state transitions in a disposable database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost',getenv('CONDO_DB_USER') ?: 'root',getenv('CONDO_DB_PASS') ?: '');
$testDb='condo_payment_test_'.bin2hex(random_bytes(6));
$server->query('CREATE DATABASE `'.$testDb.'` CHARACTER SET utf8mb4');
putenv('CONDO_DB_NAME='.$testDb); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=1');
putenv('CONDO_PAYMONGO_SECRET_KEY=sk_test_fixture'); putenv('CONDO_PAYMONGO_WEBHOOK_SECRET=whsk_fixture');
$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_payment_workflows.php';
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']; $_SERVER['SCRIPT_FILENAME']=__FILE__; $_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../config.php';
$checks=0;
function paymentCheck(bool $condition,string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: '.$label);
    $checks++;
}
function paymentActor(int $id,string $role): void {
    $_SESSION=['user_id'=>$id,'role'=>$role,'username'=>'Fixture','session_version'=>0,'last_activity'=>time()];
}
function fixturePaymentContext(int $id,string $checkout,int $amount,string $gateway='pay_fixture'): array {
    return ['internal_payment_id'=>$id,'checkout_id'=>$checkout,'paymongo_payment_id'=>$gateway,
        'payment_channel'=>'gcash','amount'=>$amount,'currency'=>'PHP','payment_status'=>'paid',
        'event_type'=>'checkout_session.payment.paid','livemode'=>false];
}
function fixtureBindCheckout(mysqli $db,int $id,string $checkout): void {
    $url='https://checkout.paymongo.com/'.$checkout;
    $stmt=$db->prepare("UPDATE payments SET paymongo_checkout_id=?,checkout_url=?,payment_method='online' WHERE id=?");
    $stmt->bind_param('ssi',$checkout,$url,$id); $stmt->execute();
}
try {
    $server->select_db($testDb);
    $server->query("CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT,username VARCHAR(100),full_name VARCHAR(100),email VARCHAR(100),contact_number VARCHAR(30),unit_number VARCHAR(30),role VARCHAR(30),is_verified TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $server->query("INSERT INTO users(id,username,full_name,email,unit_number,role) VALUES
        (1,'one','Resident One','one@example.invalid','101','resident'),
        (2,'two','Resident Two','two@example.invalid','102','resident'),
        (3,'guard','Security','guard@example.invalid',NULL,'security'),
        (4,'admin','Administrator','admin@example.invalid',NULL,'admin'),
        (5,'manager','Manager','manager@example.invalid',NULL,'superadmin'),
        (6,'finance','Treasurer','finance@example.invalid',NULL,'treasurer')");
    $db=connectDb(); ensurePaymentsTable($db); ensureBillingTables($db); ensurePaymongoColumns($db);
    ensureViolationsTable($db); ensureParkingStickerOrdersTable($db);
    $raw='{"fixture":"paid"}'; $now=(string)time();
    $signature=hash_hmac('sha256',$now.'.'.$raw,'whsk_fixture');
    paymentCheck(verifyPaymongoWebhookSignature($raw,'t='.$now.',te='.$signature.',li='),'test signature verifies');
    paymentCheck(!verifyPaymongoWebhookSignature($raw.' ','t='.$now.',te='.$signature),'changed payload fails');
    paymentCheck(!verifyPaymongoWebhookSignature($raw,'t='.$now.',li='.$signature),'live signature rejected in test mode');
    $stale=(string)(time()-301); $staleSignature=hash_hmac('sha256',$stale.'.'.$raw,'whsk_fixture');
    paymentCheck(!verifyPaymongoWebhookSignature($raw,'t='.$stale.',te='.$staleSignature),'old signed event rejected');
    putenv('CONDO_PAYMONGO_WEBHOOK_SECRET=');
    paymentCheck(!verifyPaymongoWebhookSignature($raw,'t='.$now.',te='.$signature),'empty webhook secret fails closed');
    putenv('CONDO_PAYMONGO_WEBHOOK_SECRET=whsk_fixture');
    paymentCheck(paymongoCheckoutUrlIsSafe('https://checkout.paymongo.com/cs_fixture'),'provider redirect accepted');
    paymentCheck(!paymongoCheckoutUrlIsSafe('https://checkout.paymongo.com.evil.invalid/x'),'lookalike redirect rejected');
    paymentCheck(!paymongoCheckoutUrlIsSafe('http://checkout.paymongo.com/x'),'plain HTTP redirect rejected');
    $resource=['id'=>'cs_multi','type'=>'checkout_session','attributes'=>['metadata'=>['payment_id'=>'42'],
        'payments'=>[['id'=>'failed','attributes'=>['status'=>'failed','amount'=>100]],
            ['id'=>'paid','attributes'=>['status'=>'paid','amount'=>100000,'currency'=>'PHP','source'=>['type'=>'brankas']]]]]];
    $context=extractPaymongoWebhookContext(['data'=>['attributes'=>['type'=>'checkout_session.payment.paid','livemode'=>false,'data'=>$resource]]]);
    paymentCheck($context['paymongo_payment_id']==='paid' && $context['amount']===100000 && $context['payment_channel']==='online_banking','selects paid attempt instead of first attempt');
    $v2=extractPaymongoWebhookContext(['event_type'=>'send.webhook','data'=>['type'=>'checkout_session.payment.paid','livemode'=>false,'data'=>$resource]]);
    paymentCheck($v2['checkout_id']==='cs_multi' && $v2['internal_payment_id']===42,'current hosted envelope extracts matching session');
    paymentActor(1,'resident');
    paymentCheck(createBill(1,[['category'=>'Water','amount'=>100]],null,null,date('Y-m-d'))===false,'resident cannot create bills');
    paymentActor(5,'superadmin');
    $due=date('Y-m-d',strtotime('+15 days'));
    $bill=createBill(1,[['category'=>'Parking Sticker','amount'=>1000]],null,null,$due);
    paymentCheck(is_int($bill),'billing manager creates itemized bill');
    $order=createParkingStickerOrderForBill(1,$bill,1);
    paymentCheck(is_int($order),'legacy sticker link verifies own bill');
    paymentCheck(createParkingStickerOrderForBill(2,$bill,1)===false,'sticker cannot link another resident bill');
    paymentCheck(createParkingStickerOrderForBill(1,$bill,1)===false,'bill cannot acquire duplicate sticker orders');
    fixtureBindCheckout($db,$bill,'cs_one');
    $valid=fixturePaymentContext($bill,'cs_one',100000,'pay_one');
    foreach (['amount'=>99999,'currency'=>'USD','livemode'=>true,'payment_status'=>'failed',
        'internal_payment_id'=>$bill+1,'event_type'=>'payment.paid','checkout_id'=>null,'paymongo_payment_id'=>null] as $field=>$value) {
        $bad=$valid; $bad[$field]=$value;
        paymentCheck(empty(confirmPaymongoCheckoutPayment($db,$bad)['confirmed']),'reject mismatched '.$field);
    }
    paymentCheck(empty(confirmPaymongoCheckoutPayment($db,$valid,2)['confirmed']),'reconciliation cannot settle another resident bill');
    $db->query("INSERT INTO violations(user_id,violation_type,penalty_type,fine_amount,status,payment_id) VALUES(1,'Fixture Fine','fine',1000,'unpaid',".$bill.")");
    $db->query("CREATE TRIGGER reject_paid_fine BEFORE UPDATE ON violations FOR EACH ROW BEGIN IF NEW.status='paid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected linked-state failure'; END IF; END");
    $failed=false;
    try { confirmPaymongoCheckoutPayment($db,$valid); } catch (Throwable $error) { $failed=true; }
    paymentCheck($failed,'database failure propagates for webhook retry');
    paymentCheck($db->query('SELECT status FROM payments WHERE id='.$bill)->fetch_assoc()['status']==='pending','linked failure rolls back payment');
    paymentCheck($db->query('SELECT status FROM parking_sticker_orders WHERE id='.$order)->fetch_assoc()['status']==='pending','linked failure leaves sticker unpaid');
    $db->query('DROP TRIGGER reject_paid_fine');
    paymentCheck(!empty(confirmPaymongoCheckoutPayment($db,$valid)['confirmed']),'valid event settles bill');
    paymentCheck($db->query('SELECT status FROM violations WHERE payment_id='.$bill)->fetch_assoc()['status']==='paid','fine settles with payment');
    paymentCheck($db->query('SELECT status FROM parking_sticker_orders WHERE id='.$order)->fetch_assoc()['status']==='paid','sticker settles with payment');
    paymentCheck(!empty(confirmPaymongoCheckoutPayment($db,$valid)['already_paid']),'repeat event is idempotent');
    $billTwo=createBill(2,[['category'=>'Water','amount'=>1000]],null,null,$due); fixtureBindCheckout($db,$billTwo,'cs_two');
    paymentCheck(empty(confirmPaymongoCheckoutPayment($db,fixturePaymentContext($billTwo,'cs_two',100000,'pay_one'))['confirmed']),'gateway payment cannot settle two bills');
    paymentActor(1,'resident');
    $resume=startResidentBillPayment($db,$bill,1,'online',['full_name'=>'Resident','email'=>'one@example.invalid']);
    paymentCheck(!$resume['success'],'paid bill has no new checkout');
    $wrongOwner=startResidentBillPayment($db,$billTwo,1,'online',['full_name'=>'Resident','email'=>'one@example.invalid']);
    paymentCheck(!$wrongOwner['success'],'resident cannot start someone else checkout');
    paymentActor(2,'resident');
    $resume=startResidentBillPayment($db,$billTwo,2,'online',['full_name'=>'Resident','email'=>'two@example.invalid']);
    paymentCheck(!empty($resume['resumed']) && $resume['checkout_url']==='https://checkout.paymongo.com/cs_two','repeat request resumes bound checkout without provider call');
    paymentCheck(!startResidentBillPayment($db,$billTwo,2,'cash',[])['success'],'cannot switch active checkout to cash');
    paymentActor(5,'superadmin');
    paymentCheck(!confirmCashBillPayment($db,$billTwo),'cash confirmation cannot settle online bill');
    $cash=createBill(1,[['category'=>'Parking Sticker','amount'=>1000]],null,null,$due); $cashOrder=createParkingStickerOrderForBill(1,$cash);
    paymentActor(1,'resident');
    paymentCheck(startResidentBillPayment($db,$cash,1,'cash',[])['success'],'resident can select office cash');
    paymentCheck(!confirmCashBillPayment($db,$cash),'resident cannot confirm own cash');
    paymentActor(4,'admin'); paymentCheck(!confirmCashBillPayment($db,$cash),'general admin cannot confirm cash');
    paymentActor(6,'treasurer'); paymentCheck(confirmCashBillPayment($db,$cash),'treasurer confirms cash');
    paymentCheck(!confirmCashBillPayment($db,$cash),'cash confirmation cannot replay');
    paymentCheck($db->query('SELECT status FROM parking_sticker_orders WHERE id='.$cashOrder)->fetch_assoc()['status']==='paid','cash and sticker status settle together');
    paymentActor(5,'superadmin');
    $month=date('Y-m-01'); $end=date('Y-m-t');
    $monthly=generateStandardMonthlyBills(['Condo Dues'=>2500],$month,$end,$due);
    paymentCheck($monthly['created']===2,'monthly dues still generated with unrelated outstanding bills');
    $db->query("UPDATE payments SET status='paid' WHERE billing_period_start='".$month."' AND user_id=1");
    $repeat=generateStandardMonthlyBills(['Condo Dues'=>2500],$month,$end,$due);
    paymentCheck($repeat['created']===0 && $repeat['skipped']===2,'same month skips paid and unpaid statements');
    paymentCheck(createBill(1,[['category'=>'Water','amount'=>-1]],null,null,$due)===false,'negative charge rejected');
    $before=(int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n'];
    $db->query("CREATE TRIGGER reject_bill_item BEFORE INSERT ON bill_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected item failure'");
    paymentCheck(createBill(1,[['category'=>'Water','amount'=>100]],null,null,$due)===false,'bill line failure handled');
    paymentCheck((int)$db->query('SELECT COUNT(*) n FROM payments')->fetch_assoc()['n']===$before,'bill line failure leaves no orphan invoice');
    $db->query('DROP TRIGGER reject_bill_item');
    paymentActor(3,'security');
    $fine=issueViolation(1,'Noise Complaint','Fixture citation','fine',500,$due,3);
    paymentCheck(is_int($fine),'security can issue citation');
    $citation=$db->query('SELECT * FROM violations WHERE id='.$fine)->fetch_assoc();
    paymentCheck(!resolveViolation($fine,'waive'),'security cannot waive citation');
    paymentActor(1,'resident');
    paymentCheck(!disputeViolation($fine,2,'Wrong owner'),'cannot dispute other resident fine');
    paymentCheck(disputeViolation($fine,1,'Please review'),'owner can dispute unpaid fine');
    paymentActor(4,'admin');
    paymentCheck(resolveViolation($fine,'reject_dispute'),'management can reject current dispute');
    paymentCheck(!resolveViolation($fine,'reject_dispute'),'dispute rejection cannot replay');
    fixtureBindCheckout($db,(int)$citation['payment_id'],'cs_fine');
    paymentCheck(!resolveViolation($fine,'waive'),'checkout amount remains immutable during waiver');
    paymentCheck(!empty(confirmPaymongoCheckoutPayment($db,fixturePaymentContext((int)$citation['payment_id'],'cs_fine',50000,'pay_fine'))['confirmed']),'fine checkout settles exact fine invoice');
    paymentCheck(!resolveViolation($fine,'waive') && !resolveViolation($fine,'reject_dispute'),'paid fine cannot be waived or reopened');
    $waiver=issueViolation(2,'Littering','Fixture citation','fine',300,$due,4);
    paymentCheck(resolveViolation($waiver,'waive'),'unsubmitted fine can be waived');
    $waiverBill=$db->query('SELECT payment_id FROM violations WHERE id='.$waiver)->fetch_assoc()['payment_id'];
    $closed=$db->query('SELECT amount,status FROM payments WHERE id='.(int)$waiverBill)->fetch_assoc();
    paymentCheck((float)$closed['amount']===0.0 && $closed['status']==='rejected','fully waived invoice closes without false payment');
    paymentCheck(!resolveViolation($waiver,'waive'),'waiver cannot replay');
    $warning=issueViolation(1,'Noise Complaint','Warning','warning',500,null,4);
    paymentCheck(is_int($warning) && $db->query('SELECT status FROM violations WHERE id='.$warning)->fetch_assoc()['status']==='warning_issued','warning never creates a monetary charge');
    foreach (['resident/payments.php','superadmin/unitpayments.php','superadmin/generate_bills.php'] as $page) {
        $process=proc_open([PHP_BINARY,__DIR__.'/render_payment_page.php',$page],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $html=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        paymentCheck($code===0 && $errors==='' && str_contains($html,'<!DOCTYPE html>'),'billing page renders '.$page.': '.$errors);
        $dom=new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors(); $xpath=new DOMXPath($dom);
        foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) {
            paymentCheck($xpath->query('.//input[@name="csrf_token"]',$form)->length===1,'billing form CSRF token '.$page);
        }
        $process=proc_open([PHP_BINARY,__DIR__.'/render_payment_page.php',$page,'{}','invalid'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $denied=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        paymentCheck($code===0 && $errors==='' && str_contains($denied,'session token is invalid'),'billing POST denies invalid CSRF '.$page);
    }
    echo 'Passed '.$checks." payment workflow checks in a disposable database; no provider calls.\n";
} finally {
    if (!preg_match('/^condo_payment_test_[a-f0-9]{12}$/D',$testDb)) throw new RuntimeException('Unsafe test cleanup.');
    $server->query('DROP DATABASE `'.$testDb.'`');
}
