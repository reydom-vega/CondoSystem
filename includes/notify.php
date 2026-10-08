<?php
/** Resident notifications are queued; provider delivery belongs to the worker. */
function getAllResidentContacts(): array {
    return connectDb()->query("SELECT id,full_name,email,contact_number FROM users WHERE role='resident' AND is_verified=1 AND is_active=1 AND status='approved'")->fetch_all(MYSQLI_ASSOC);
}

function emailLayout(string $eyebrow, string $heading, string $bodyHtml): string {
    return '<!doctype html><html><body style="margin:0;background:#0a0f1d;color:#fff;font-family:Arial,sans-serif;padding:24px;">'
        . '<div style="max-width:560px;margin:auto;background:#111827;border:1px solid #374151;border-radius:12px;padding:32px;">'
        . '<div style="color:#f59e0b;font-size:12px;font-weight:bold;">' . $eyebrow . '</div><h1 style="font-size:22px;">' . $heading . '</h1>'
        . $bodyHtml . '<p style="color:#9ca3af;font-size:11px;margin-top:28px;">The Celandine Residences &middot; Automated resident portal message.</p></div></body></html>';
}

/** Financial notices go to the unit owner or the tenant billed for personal parking. */
function billingNoticeContact(mysqli $db,int $billUserId,?int $paymentId=null): ?array {
    $context=residentContext($db,$billUserId);
    if (!$context || !$context['billing_user_id']) return null;
    if ($context['account_kind']!=='owner' && !$context['approved']) return null;
    $ownerId=(int)$context['billing_user_id'];
    if ($paymentId!==null && $context['account_kind']==='tenant' && residentCanPayBill($db,$billUserId,$billUserId,$paymentId)) $ownerId=$billUserId;
    $find=$db->prepare('SELECT id,full_name,email,contact_number FROM users WHERE id=?');
    $find->bind_param('i',$ownerId); $find->execute();
    return $find->get_result()->fetch_assoc() ?: null;
}

/** Caller prepares storage before opening a business transaction. */
function queueResidentChannels(mysqli $db, string $eventKey, array $resident, string $subject, string $html, string $sms, string $eventKind, int $entityId, bool $strict = false): array {
    $id=(int)$resident['id']; $email=(string)$resident['email']; $phone=(string)$resident['contact_number'];
    $emailValid=filter_var($email,FILTER_VALIDATE_EMAIL)!==false;
    $smsValid=preg_match('/^[0-9]{10,15}$/D',normalizePhDigits($phone))===1;
    $emailQueued=$emailValid && queueNotification($db,$eventKey,'email',$email,$subject,$html,$id,$eventKind,$entityId);
    $smsQueued=$smsValid && queueNotification($db,$eventKey,'sms',$phone,'',$sms,$id,$eventKind,$entityId);
    if ($strict && (($emailValid && !$emailQueued) || ($smsValid && !$smsQueued))) throw new RuntimeException('Could not queue the resident notification.');
    return ['email_queued'=>$emailQueued,'sms_queued'=>$smsQueued];
}

function notifyResidentsOfAnnouncement(int $announcementId): array {
    $counts=['sent'=>0,'failed'=>0,'emails_sent'=>0,'emails_failed'=>0,'sms_sent'=>0,'sms_failed'=>0,'emails_queued'=>0,'sms_queued'=>0];
    $db=connectDb(); if (!ensureNotificationOutboxTable($db)) return $counts;
    $find=$db->prepare('SELECT title,content,priority FROM announcements WHERE id=? AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW())');
    $find->bind_param('i',$announcementId); $find->execute(); $announcement=$find->get_result()->fetch_assoc();
    if (!$announcement) return $counts;
    $title=htmlspecialchars($announcement['title'],ENT_QUOTES,'UTF-8');
    $html=emailLayout('New Announcement',$title,'<p>' . nl2br(htmlspecialchars($announcement['content'],ENT_QUOTES,'UTF-8')) . '</p><p>Priority: ' . htmlspecialchars($announcement['priority'],ENT_QUOTES,'UTF-8') . '</p>');
    $revision=hash('sha256',$announcement['title'] . '|' . $announcement['content'] . '|' . $announcement['priority']);
    foreach (getAllResidentContacts() as $resident) {
        $queued=queueResidentChannels($db,'announcement:' . $announcementId . ':' . $revision . ':user:' . $resident['id'],$resident,'[Celandine Residences] ' . $announcement['title'],$html,'Celandine Residences: ' . $announcement['title'] . '. Check the resident portal for details.','announcement',$announcementId);
        $counts['emails_queued']+=(int)$queued['email_queued']; $counts['sms_queued']+=(int)$queued['sms_queued'];
        $counts['emails_failed']+=(int)!$queued['email_queued']; $counts['sms_failed']+=(int)!$queued['sms_queued'];
    }
    $counts['failed']=$counts['emails_failed'];
    logAudit('notify','announcement',$announcementId,'Accepted ' . $counts['emails_queued'] . ' email and ' . $counts['sms_queued'] . ' SMS queue job(s).');
    return $counts;
}

function notifyResidentOfViolation(int $violationId): array {
    $empty=['email_sent'=>false,'sms_sent'=>false,'email_queued'=>false,'sms_queued'=>false];
    $db=connectDb(); if (!ensureNotificationOutboxTable($db)) return $empty;
    $find=$db->prepare('SELECT v.violation_type,v.description,v.penalty_type,v.fine_amount,v.due_date,u.id,u.full_name,u.email,u.contact_number FROM violations v JOIN users u ON u.id=v.user_id WHERE v.id=?');
    $find->bind_param('i',$violationId); $find->execute(); $row=$find->get_result()->fetch_assoc();
    if (!$row) return $empty;
    $fine=$row['penalty_type']==='fine' ? 'Fine: PHP ' . number_format((float)$row['fine_amount'],2) : 'Warning only';
    $html=emailLayout('Violation Notice',htmlspecialchars($row['violation_type'],ENT_QUOTES,'UTF-8'),'<p>Hi ' . htmlspecialchars($row['full_name'],ENT_QUOTES,'UTF-8') . ', a violation has been recorded.</p><p>' . $fine . '</p><p>' . nl2br(htmlspecialchars($row['description'] ?? '',ENT_QUOTES,'UTF-8')) . '</p><p>Review details or contact management through the resident portal.</p>');
    $queued=queueResidentChannels($db,'violation:' . $violationId,$row,'[Celandine Residences] Violation Notice: ' . $row['violation_type'],$html,'Celandine Residences: Violation notice - ' . $row['violation_type'] . '. ' . $fine . '. Check the resident portal for details.','violation',$violationId);
    logAudit('notify','violation',$violationId,'Violation notifications accepted for queue delivery.');
    return array_merge($empty,$queued);
}

function ensureReminderColumn(mysqli $db): void {
    if (!schemaMutationAllowed()) return;
    $column=$db->query("SHOW COLUMNS FROM payments LIKE 'reminder_sent_at'");
    if (!$column || !$column->num_rows) $db->query('ALTER TABLE payments ADD reminder_sent_at DATETIME DEFAULT NULL');
}

/** Historical bool means email accepted for queueing, not already delivered. */
function notifyResidentOfNewBill(int $paymentId): bool {
    $db=connectDb(); if (!ensureNotificationOutboxTable($db)) return false;
    $bill=getBillWithItems($db,$paymentId); if (!$bill) return false;
    $resident=billingNoticeContact($db,(int)$bill['user_id'],$paymentId);
    if (!$resident) return false;
    $amount=number_format((float)$bill['amount'],2); $due=date('F j, Y',strtotime($bill['due_date']));
    $rows=''; foreach ($bill['items'] as $item) $rows.='<tr><td>' . htmlspecialchars($item['category'],ENT_QUOTES,'UTF-8') . '</td><td>PHP ' . number_format((float)$item['amount'],2) . '</td></tr>';
    $html=emailLayout('Billing Statement Available','Total: PHP ' . $amount,'<p>Hi ' . htmlspecialchars($resident['full_name'],ENT_QUOTES,'UTF-8') . ', your statement is ready.</p><table style="width:100%;">' . $rows . '</table><p>Due: ' . $due . '</p><p>View details and pay through Billing &amp; Payments in the resident portal.</p>');
    $queued=queueResidentChannels($db,'new_bill:' . $paymentId . ':owner:' . $resident['id'],$resident,'[Celandine Residences] Your Billing Statement is Ready',$html,'Celandine Residences: Bill PHP ' . $amount . ' is ready, due ' . $due . '. Check the resident portal.','bill_notice',$paymentId);
    return $queued['email_queued'];
}

/** Supplied connection lets paid-state changes and receipt enqueue commit together. */
function notifyResidentOfPayment(int $paymentId, ?mysqli $db = null): bool {
    $strict=$db!==null;
    if ($db===null) { $db=connectDb(); if (!ensureNotificationOutboxTable($db)) return false; }
    $find=$db->prepare("SELECT p.amount,u.id,u.full_name,u.email,u.contact_number FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status='paid'");
    $find->bind_param('i',$paymentId); $find->execute(); $row=$find->get_result()->fetch_assoc();
    if (!$row) return false;
    $contact=billingNoticeContact($db,(int)$row['id'],$paymentId);
    if (!$contact) return false;
    $row=array_merge($row,$contact);
    $amount=number_format((float)$row['amount'],2);
    $html=emailLayout('Payment Confirmed','Payment received','<p>Hi ' . htmlspecialchars($row['full_name'],ENT_QUOTES,'UTF-8') . ', payment of PHP ' . $amount . ' for bill #' . $paymentId . ' has been confirmed. View the receipt in the resident portal.</p>');
    $queued=queueResidentChannels($db,'payment_receipt:' . $paymentId . ':owner:' . $row['id'],$row,'[Celandine Residences] Payment Confirmed',$html,'Celandine Residences: Payment PHP ' . $amount . ' for bill #' . $paymentId . ' confirmed. View your receipt in the resident portal.','payment_receipt',$paymentId,$strict);
    return $queued['email_queued'];
}

/** Queue up to 500 due bills per run, with a per-bill 24-hour transactional guard. */
function sendDueDateReminders(int $daysAhead = 3): array {
    $counts=['due_count'=>0,'emails_sent'=>0,'sms_sent'=>0,'emails_queued'=>0,'sms_queued'=>0];
    $db=connectDb(); ensurePaymentsTable($db); ensureReminderColumn($db);
    if (!ensureNotificationOutboxTable($db)) return $counts;
    $daysAhead=min(30,max(0,$daysAhead));
    $ids=$db->query("SELECT p.id FROM payments p JOIN users u ON u.id=p.user_id WHERE p.status IN ('pending','overdue') AND p.amount>0 AND p.due_date<=DATE_ADD(CURDATE(),INTERVAL {$daysAhead} DAY) AND (p.reminder_sent_at IS NULL OR p.reminder_sent_at<DATE_SUB(NOW(),INTERVAL 24 HOUR)) AND u.role='resident' AND u.is_active=1 AND u.is_verified=1 AND u.status='approved' ORDER BY p.due_date,p.id LIMIT 500")->fetch_all(MYSQLI_ASSOC);
    foreach ($ids as $entry) {
        $db->begin_transaction();
        try {
            $find=$db->prepare("SELECT p.amount,p.due_date,u.id,u.full_name,u.email,u.contact_number FROM payments p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status IN ('pending','overdue') AND p.amount>0 AND (p.reminder_sent_at IS NULL OR p.reminder_sent_at<DATE_SUB(NOW(),INTERVAL 24 HOUR)) AND u.is_active=1 AND u.is_verified=1 AND u.status='approved' FOR UPDATE");
            $id=(int)$entry['id']; $find->bind_param('i',$id); $find->execute(); $row=$find->get_result()->fetch_assoc();
            if (!$row) { $db->rollback(); continue; }
            $contact=billingNoticeContact($db,(int)$row['id'],$id);
            if (!$contact || !residentCanPayBill($db,(int)$contact['id'],(int)$row['id'],$id)) { $db->rollback(); continue; }
            $row=array_merge($row,$contact);
            $amount=number_format((float)$row['amount'],2); $due=date('F j, Y',strtotime($row['due_date']));
            $overdue=$row['due_date']<date('Y-m-d'); $label=$overdue ? 'overdue' : 'due soon';
            $html=emailLayout('Payment Reminder','Your dues are ' . $label,'<p>Hi ' . htmlspecialchars($row['full_name'],ENT_QUOTES,'UTF-8') . ', bill #' . $id . ' for PHP ' . $amount . ' is ' . $label . '. Due: ' . $due . '.</p><p>Settle through Billing &amp; Payments in the resident portal.</p>');
            $queued=queueResidentChannels($db,'due:' . $id . ':' . date('Y-m-d') . ':owner:' . $row['id'],$row,$overdue ? '[Celandine Residences] Overdue Payment Reminder' : '[Celandine Residences] Upcoming Payment Due',$html,'Celandine Residences: Bill #' . $id . ' PHP ' . $amount . ' is ' . $label . ', due ' . $due . '. Please settle via the resident portal.','due_reminder',$id,true);
            if (!$queued['email_queued'] && !$queued['sms_queued']) { $db->rollback(); continue; }
            $update=$db->prepare('UPDATE payments SET reminder_sent_at=NOW() WHERE id=?');
            $update->bind_param('i',$id); $update->execute(); $db->commit();
            $counts['due_count']++; $counts['emails_queued']+=(int)$queued['email_queued']; $counts['sms_queued']+=(int)$queued['sms_queued'];
        } catch (Throwable $e) { $db->rollback(); error_log('Reminder enqueue failed: ' . get_class($e)); }
    }
    trackEvent('due_reminders_queued',$counts['emails_queued'] . ' email, ' . $counts['sms_queued'] . ' SMS accepted');
    return $counts;
}
