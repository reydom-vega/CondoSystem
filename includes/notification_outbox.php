<?php
/** Durable delivery queue. Business pages enqueue; only the CLI worker sends. */
function ensureNotificationOutboxTable(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    return $db->query("CREATE TABLE IF NOT EXISTS notification_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deduplication_key CHAR(64) NOT NULL UNIQUE,
        user_id INT DEFAULT NULL, event_kind VARCHAR(40) NOT NULL DEFAULT 'notice', entity_id INT DEFAULT NULL,
        channel ENUM('email','sms') NOT NULL, recipient VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL DEFAULT '', body MEDIUMTEXT NOT NULL,
        status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at DATETIME DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, sent_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(status,next_attempt_at), INDEX(user_id), INDEX(event_kind,entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}

/**
 * Storage must be prepared before a caller opens its transaction.
 * Returns accepted for a new job or an existing identical event/channel job.
 */
function queueNotification(mysqli $db, string $eventKey, string $channel, string $recipient, string $subject, string $body, ?int $userId = null, string $eventKind = 'notice', ?int $entityId = null): bool {
    if (!in_array($channel,['email','sms'],true) || $eventKey==='' || strlen($eventKey)>300 || $body==='' || strlen($body)>1048576 || strlen($subject)>255 || strlen($eventKind)>40) return false;
    if ($channel==='email') {
        $recipient = trim($recipient);
        if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || strlen($recipient)>255) return false;
    } else {
        $recipient = normalizePhDigits($recipient);
        if (!preg_match('/^[0-9]{10,15}$/D',$recipient)) return false;
    }
    $key = hash('sha256',$eventKey . '|' . $channel);
    try {
        $insert = $db->prepare('INSERT INTO notification_outbox (deduplication_key,user_id,event_kind,entity_id,channel,recipient,subject,body) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE deduplication_key=VALUES(deduplication_key)');
        $insert->bind_param('sisissss',$key,$userId,$eventKind,$entityId,$channel,$recipient,$subject,$body);
        return $insert->execute();
    } catch (Throwable $e) {
        error_log('Notification enqueue failed for event kind ' . $eventKind . ': ' . $e->getMessage());
        return false;
    }
}

/** Obsolete reminders/addresses are cancelled before any provider request. */
function notificationStillRelevant(mysqli $db, array $job): bool {
    if ($job['user_id'] !== null) {
        $find = $db->prepare('SELECT is_active,is_verified,role,status,session_version,email,contact_number FROM users WHERE id=?');
        $id = (int)$job['user_id']; $find->bind_param('i',$id); $find->execute();
        $user = $find->get_result()->fetch_assoc();
        if (!$user) return false;
        if ($job['event_kind']==='payment_receipt') {
            $receiptId=(int)$job['entity_id'];
            $receipt=$db->prepare("SELECT user_id FROM payments WHERE id=? AND status='paid'");
            $receipt->bind_param('i',$receiptId); $receipt->execute(); $paidBill=$receipt->get_result()->fetch_assoc();
            if (!$paidBill || !residentCanPayBill($db,$id,(int)$paidBill['user_id'],$receiptId)) return false;
        }
        $accountStates=['account_approved'=>'approved','account_rejected'=>'rejected'];
        $accountNotice=isset($accountStates[$job['event_kind']]);
        if ($accountNotice && ($user['role']!=='resident' || (int)$user['is_active']!==1 || (int)$user['is_verified']!==1 || $user['status']!==$accountStates[$job['event_kind']] || $job['entity_id']===null || (int)$job['entity_id']!==(int)$user['session_version'])) return false;
        if (!$accountNotice && !in_array($job['event_kind'],['account','payment_receipt'],true) && ((int)$user['is_active']!==1 || ($user['role']==='resident' && ($user['status']!=='approved' || (int)$user['is_verified']!==1)))) return false;
        if (!$accountNotice && !in_array($job['event_kind'],['account','payment_receipt'],true) && $user['role']==='resident' && !(residentContext($db,$id)['approved'] ?? false)) return false;
        $current = $job['channel']==='email' ? trim($user['email']) : normalizePhDigits($user['contact_number']);
        if ($job['channel']==='email' ? strcasecmp($current,$job['recipient'])!==0 : $current!==$job['recipient']) return false;
    }
    if (in_array($job['event_kind'],['due_reminder','bill_notice'],true)) {
        $find = $db->prepare("SELECT user_id FROM payments WHERE id=? AND status IN ('pending','overdue') AND amount>0");
        $id=(int)$job['entity_id']; $userId=(int)$job['user_id']; $find->bind_param('i',$id); $find->execute();
        $bill=$find->get_result()->fetch_assoc();
        if (!$bill || !residentCanPayBill($db,$userId,(int)$bill['user_id'],$id)) return false;
    }
    if ($job['event_kind']==='announcement') {
        $find=$db->prepare('SELECT id FROM announcements WHERE id=? AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW())');
        $id=(int)$job['entity_id']; $find->bind_param('i',$id); $find->execute();
        if (!$find->get_result()->fetch_assoc()) return false;
    }
    return true;
}

/** Retry failed delivery only; the worker rechecks its recipient and relevance. */
function retryNotificationDelivery(mysqli $db, int $id): bool {
    if (!canAccess('notifications.manage') || $id < 1) return false;
    ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $retry=$db->prepare("UPDATE notification_outbox SET status='pending', attempts=0, next_attempt_at=NOW(), locked_at=NULL, last_error=NULL WHERE id=? AND status='failed'");
        $retry->bind_param('i',$id); $retry->execute();
        if ($retry->affected_rows!==1) { $db->rollback(); return false; }
        if (!logAudit('retry','notification',$id,'Failed delivery queued for another attempt.',$db)) throw new RuntimeException('Could not audit delivery retry.');
        $db->commit(); return true;
    } catch (Throwable $error) { $db->rollback(); error_log('Notification retry failed: '.get_class($error)); return false; }
}

/**
 * Claim and commit before provider I/O. A database advisory lock prevents
 * concurrent workers; failed jobs back off and stop after five attempts.
 * Delivery is at least once if a worker crashes after provider acceptance.
 * An injectable sender permits provider-free integration testing.
 */
function processNotificationOutbox(mysqli $db, int $limit = 25, ?callable $sender = null, int $maxSeconds = 45): array {
    $counts=['claimed'=>0,'sent'=>0,'retry'=>0,'failed'=>0,'cancelled'=>0,'busy'=>false];
    $limit=max(1,min(100,$limit)); $deadline=microtime(true)+max(1,min(300,$maxSeconds));
    if (!ensureNotificationOutboxTable($db)) throw new RuntimeException('Notification queue is unavailable.');
    $name='condo-notify:' . sha1((string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name']);
    $lock=$db->prepare('SELECT GET_LOCK(?,0) AS acquired'); $lock->bind_param('s',$name); $lock->execute();
    if ((int)$lock->get_result()->fetch_assoc()['acquired']!==1) { $counts['busy']=true; return $counts; }
    $sender ??= static function(array $job): bool {
        return $job['channel']==='email' ? sendMail($job['recipient'],$job['subject'],$job['body']) : (bool)(sendSms($job['recipient'],$job['body'])['success'] ?? false);
    };
    try {
        $db->query("UPDATE notification_outbox SET status=IF(attempts>=5,'failed','pending'),locked_at=NULL,next_attempt_at=NOW(),last_error='Worker interrupted before delivery acknowledgement' WHERE status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
        while ($counts['claimed']<$limit && microtime(true)<$deadline) {
            $db->begin_transaction();
            try {
                $job=$db->query("SELECT * FROM notification_outbox WHERE status='pending' AND next_attempt_at<=NOW() AND attempts<5 ORDER BY id LIMIT 1 FOR UPDATE")->fetch_assoc();
                if (!$job) { $db->commit(); break; }
                $id=(int)$job['id'];
                $claim=$db->prepare("UPDATE notification_outbox SET status='processing',attempts=attempts+1,locked_at=NOW() WHERE id=? AND status='pending'");
                $claim->bind_param('i',$id); $claim->execute(); $db->commit();
            } catch (Throwable $e) { $db->rollback(); throw $e; }
            $counts['claimed']++; $attempt=(int)$job['attempts']+1;
            $success=false; $relevant=notificationStillRelevant($db,$job);
            if ($relevant) {
                try { $success=(bool)$sender($job); } catch (Throwable $e) { error_log('Notification delivery exception: ' . get_class($e)); }
            }
            $status = !$relevant ? 'cancelled' : ($success ? 'sent' : ($attempt>=5 ? 'failed' : 'pending'));
            $delay = min(3600,60 * (2 ** max(0,$attempt-1)));
            $next=date('Y-m-d H:i:s',time()+$delay);
            $error = $success ? null : (!$relevant ? 'Notification no longer relevant' : 'Provider did not acknowledge delivery');
            $finish=$db->prepare("UPDATE notification_outbox SET status=?,locked_at=NULL,next_attempt_at=?,last_error=?,sent_at=IF(?='sent',NOW(),sent_at) WHERE id=? AND status='processing'");
            $finish->bind_param('ssssi',$status,$next,$error,$status,$id); $finish->execute();
            $counts[$status==='pending' ? 'retry' : $status]++;
        }
        return $counts;
    } finally {
        $release=$db->prepare('SELECT RELEASE_LOCK(?)'); $release->bind_param('s',$name); $release->execute();
    }
}
