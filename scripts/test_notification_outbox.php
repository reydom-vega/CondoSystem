<?php
/** Delivery uses an injected sender; no email, SMS or payment providers are called. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database='condo_notify_test_'.bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
putenv('CONDO_DB_NAME='.$database); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=0');
putenv('CONDO_APP_URL=http://localhost/CondoSystem3');
$checks=0;
function outboxCheck(bool $condition,string $label): void { global $checks; if(!$condition) throw new RuntimeException('FAILED: '.$label); $checks++; }
function fixtureJob(mysqli $db,string $key,string $kind='notice',?int $entity=null): int {
    outboxCheck(queueNotification($db,$key,'email','one@example.invalid','Fixture notice','Fixture body',1,$kind,$entity),'enqueue '.$key);
    return (int)$db->query('SELECT MAX(id) AS id FROM notification_outbox')->fetch_assoc()['id'];
}
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database'));
    do { $result=$server->store_result(); if($result) $result->free(); } while($server->more_results() && $server->next_result());
    $process=proc_open([PHP_BINARY,__DIR__.'/migrate.php','--apply'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    outboxCheck(proc_close($process)===0,'notification schema migration: '.$error);
    require_once dirname(__DIR__).'/config.php'; $db=connectDb();
    $hash=password_hash('Fixture@123',PASSWORD_DEFAULT);
    $add=$db->prepare("INSERT INTO users(id,full_name,username,email,contact_number,unit_number,password_hash,role,is_verified,status,is_active) VALUES(1,'One','one','one@example.invalid','09171234567','0101',?,'resident',1,'approved',1),(2,'Two','two','two@example.invalid','09171234568','0102',?,'resident',1,'approved',0),(3,'Finance','finance','finance@example.invalid','09171234569',NULL,?,'superadmin',1,'approved',1)");
    $add->bind_param('sss',$hash,$hash,$hash); $add->execute();
    $_SESSION=['user_id'=>3,'role'=>'superadmin','session_version'=>0,'last_activity'=>time()];
    $calls=0; $sender=static function(array $job) use (&$calls): bool { $calls++; return true; };
    $id=fixtureJob($db,'single');
    outboxCheck(queueNotification($db,'single','email','one@example.invalid','Fixture notice','Fixture body',1),'same event accepted');
    outboxCheck((int)$db->query('SELECT COUNT(*) AS n FROM notification_outbox')->fetch_assoc()['n']===1,'duplicate event stored once');
    outboxCheck(!queueNotification($db,'bad','push','one@example.invalid','Test','Test',1),'unsupported channel denied');
    outboxCheck(!queueNotification($db,'bad','email','invalid','Test','Test',1),'invalid recipient denied');
    $result=processNotificationOutbox($db,10,$sender);
    outboxCheck($result['sent']===1 && $calls===1,'worker acknowledges delivery');
    outboxCheck(processNotificationOutbox($db,10,$sender)['claimed']===0 && $calls===1,'sent notification not resent');
    $retryId=fixtureJob($db,'retry');
    $result=processNotificationOutbox($db,10,static fn(array $job):bool=>false);
    $retry=$db->query('SELECT * FROM notification_outbox WHERE id='.$retryId)->fetch_assoc();
    outboxCheck($result['retry']===1 && $retry['status']==='pending' && (int)$retry['attempts']===1 && strtotime($retry['next_attempt_at'])>time(),'failed delivery backs off');
    for($i=2;$i<=5;$i++) { $db->query('UPDATE notification_outbox SET next_attempt_at=NOW() WHERE id='.$retryId); processNotificationOutbox($db,10,static fn(array $job):bool=>false); }
    $retry=$db->query('SELECT * FROM notification_outbox WHERE id='.$retryId)->fetch_assoc();
    outboxCheck($retry['status']==='failed' && (int)$retry['attempts']===5,'delivery stops after five failures');
    $interrupted=fixtureJob($db,'interrupted');
    $db->query("UPDATE notification_outbox SET status='processing',attempts=1,locked_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE id=".$interrupted);
    outboxCheck(processNotificationOutbox($db,10,$sender)['sent']===1,'interrupted worker claim recovered');
    $db->query("INSERT INTO payments(id,user_id,amount,payment_method,status,due_date) VALUES(1,1,100,'cash','paid',CURDATE()),(2,1,100,'cash','pending',CURDATE())");
    $obsolete=fixtureJob($db,'paid-reminder','due_reminder',1);
    $before=$calls; outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===1 && $calls===$before,'paid bill reminder cancelled before provider call');
    $changed=fixtureJob($db,'changed-email'); $db->query("UPDATE users SET email='new@example.invalid' WHERE id=1");
    outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===1 && $calls===$before,'old address notification cancelled');
    $db->query("UPDATE users SET email='one@example.invalid',is_active=0 WHERE id=1"); fixtureJob($db,'inactive');
    outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===1 && $calls===$before,'inactive resident notice cancelled');
    $db->query('UPDATE users SET is_active=1 WHERE id=1');
    fixtureJob($db,'obsolete-approval','account_approved',0);
    $db->query("UPDATE users SET status='pending' WHERE id=1");
    outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===1 && $calls===$before,'approval notice cancelled after account revocation');
    $db->query("UPDATE users SET status='approved',session_version=1 WHERE id=1");
    fixtureJob($db,'obsolete-account-version','account_approved',0);
    outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===1 && $calls===$before,'earlier account version notice cancelled after new assignment');
    $db->query("UPDATE users SET status='rejected',session_version=0 WHERE id=1");
    fixtureJob($db,'current-rejection','account_rejected',0);
    outboxCheck(processNotificationOutbox($db,10,$sender)['sent']===1 && $calls===$before+1,'current rejection notice can reach rejected applicant');
    $before=$calls;
    fixtureJob($db,'obsolete-rejection','account_rejected',0);
    $db->query("UPDATE users SET status='approved' WHERE id=1");
    outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===1 && $calls===$before,'rejection notice cancelled after approval');
    $db->query("INSERT INTO announcements(id,admin_id,title,content,is_active) VALUES(1,3,'Fixture','Announcement content',1)");
    $queued=notifyResidentsOfAnnouncement(1);
    outboxCheck($queued['emails_queued']===1 && $queued['sms_queued']===1 && $queued['emails_sent']===0,'announcement queues active resident channels');
    $count=(int)$db->query('SELECT COUNT(*) AS n FROM notification_outbox')->fetch_assoc()['n']; notifyResidentsOfAnnouncement(1);
    outboxCheck((int)$db->query('SELECT COUNT(*) AS n FROM notification_outbox')->fetch_assoc()['n']===$count,'repeat announcement is idempotent');
    $db->query('UPDATE announcements SET is_active=0 WHERE id=1');
    outboxCheck(processNotificationOutbox($db,10,$sender)['cancelled']===2,'withdrawn announcement cancelled');
    $reminders=sendDueDateReminders();
    outboxCheck($reminders['due_count']===1 && $reminders['emails_queued']===1 && $reminders['sms_queued']===1,'due reminders queued atomically');
    outboxCheck(sendDueDateReminders()['due_count']===0,'repeat reminder enqueue blocked for 24 hours');
    $db->query("UPDATE notification_outbox SET status='cancelled' WHERE status='pending'");
    $lockName='condo-notify:'.sha1($database); $other=new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'),$database);
    $lock=$other->prepare('SELECT GET_LOCK(?,0)'); $lock->bind_param('s',$lockName); $lock->execute();
    outboxCheck(processNotificationOutbox($db,10,$sender)['busy']===true,'concurrent worker refused');
    $other->close();
    $cash=createBill(1,[['category'=>'Fixture','amount'=>150]],null,null,date('Y-m-d'),'cash');
    outboxCheck($cash!==false && confirmCashBillPayment($db,$cash),'cash confirmation queues receipt');
    $receiptCount=(int)$db->query("SELECT COUNT(*) AS n FROM notification_outbox WHERE event_kind='payment_receipt' AND entity_id=".$cash)->fetch_assoc()['n'];
    outboxCheck($receiptCount===2,'receipt channels stored with settlement');
    outboxCheck(!confirmCashBillPayment($db,$cash) && (int)$db->query("SELECT COUNT(*) AS n FROM notification_outbox WHERE event_kind='payment_receipt' AND entity_id=".$cash)->fetch_assoc()['n']===2,'repeated settlement creates no extra receipts');
    $failedBill=createBill(1,[['category'=>'Fixture','amount'=>200]],null,null,date('Y-m-d'),'cash');
    $db->query("CREATE TRIGGER reject_receipt BEFORE INSERT ON notification_outbox FOR EACH ROW BEGIN IF NEW.event_kind='payment_receipt' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected queue failure'; END IF; END");
    outboxCheck(!confirmCashBillPayment($db,$failedBill),'queue failure rejects settlement');
    outboxCheck($db->query('SELECT status FROM payments WHERE id='.$failedBill)->fetch_assoc()['status']==='pending','queue failure rolls back paid state');
    $db->query('DROP TRIGGER reject_receipt');
    $db->query("UPDATE notification_outbox SET status='cancelled' WHERE status='pending'");
    fixtureJob($db,'bounded-1'); fixtureJob($db,'bounded-2');
    outboxCheck(processNotificationOutbox($db,1,$sender)['claimed']===1,'worker obeys bounded batch');
    $_SESSION=['user_id'=>1,'role'=>'resident','session_version'=>0,'last_activity'=>time()];
    outboxCheck(!retryNotificationDelivery($db,$retryId),'resident cannot retry staff delivery jobs');
    $_SESSION=['user_id'=>3,'role'=>'superadmin','session_version'=>0,'last_activity'=>time()];
    outboxCheck(retryNotificationDelivery($db,$retryId),'superadmin can retry failed delivery');
    outboxCheck(!retryNotificationDelivery($db,$retryId),'already pending delivery cannot be retried twice');
    echo "Passed {$checks} notification checks in a disposable database; mocked delivery only.\n";
} finally {
    if(!preg_match('/\Acondo_notify_test_[a-f0-9]{12}\z/',$database)) throw new RuntimeException('Unsafe cleanup target.');
    $server->query("DROP DATABASE `{$database}`");
}
