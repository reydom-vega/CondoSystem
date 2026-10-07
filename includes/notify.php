<?php
/**
 * Resident-facing notifications.
 *
 * Two jobs live here:
 *   1. Email and text every resident when an admin posts an announcement.
 *   2. Email + text residents whose dues are coming up or overdue.
 *
 * Both build on the existing sendMail() (config.php, already wired to
 * PHPMailer/SMTP) and the new sendSms() (includes/sms.php).
 */

function getAllResidentContacts(): array {
    $connection = connectDb();
    $result = $connection->query("SELECT id, full_name, email, contact_number FROM users WHERE role = 'resident' AND is_verified = 1");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function emailLayout(string $eyebrow, string $heading, string $bodyHtml): string {
    return '<!doctype html><html><body style="margin:0;background:#0a0f1d;color:#ffffff;font-family:Arial,sans-serif;padding:24px;">'
        . '<div style="max-width:560px;margin:0 auto;background:#111827;border:1px solid #374151;border-radius:12px;padding:32px;">'
        . '<div style="color:#f59e0b;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">' . $eyebrow . '</div>'
        . '<h1 style="font-size:22px;margin:14px 0 16px;">' . $heading . '</h1>'
        . $bodyHtml
        . '<p style="color:#6b7280;font-size:11px;line-height:1.5;margin-top:28px;">The Celandine Residences &middot; This is an automated message from the resident portal.</p>'
        . '</div></body></html>';
}

/**
 * Emails and texts every verified resident about a newly created (or
 * resent) announcement. Returns channel-specific counts for the caller.
 */
function notifyResidentsOfAnnouncement(int $announcementId): array {
    $connection = connectDb();
    $stmt = $connection->prepare('SELECT title, content, priority FROM announcements WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $announcementId);
    $stmt->execute();
    $announcement = $stmt->get_result()->fetch_assoc();
    if (!$announcement) {
        return ['sent' => 0, 'failed' => 0];
    }

    $safeTitle = htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8');
    $safeContent = nl2br(htmlspecialchars($announcement['content'], ENT_QUOTES, 'UTF-8'));
    $priorityColor = match ($announcement['priority']) {
        'high' => '#ef4444',
        'low' => '#10b981',
        default => '#f59e0b',
    };

    $bodyHtml = '<p style="display:inline-block;background:' . $priorityColor . ';color:#fff;font-size:11px;font-weight:bold;padding:3px 10px;border-radius:10px;text-transform:uppercase;">' . htmlspecialchars(ucfirst($announcement['priority'])) . ' priority</p>'
        . '<div style="color:#cbd5e1;line-height:1.6;margin-top:16px;">' . $safeContent . '</div>';

    $subject = '[Celandine Residences] ' . $announcement['title'];
    $body = emailLayout('New Announcement', $safeTitle, $bodyHtml);

    $emailSent = 0;
    $emailFailed = 0;
    $smsSent = 0;
    $smsFailed = 0;
    foreach (getAllResidentContacts() as $resident) {
        if (sendMail($resident['email'], $subject, $body)) {
            $emailSent++;
        } else {
            $emailFailed++;
        }

        $smsResult = sendSms($resident['contact_number'], 'Celandine Residences: ' . $announcement['title'] . '. Check the resident portal for details.');
        if ($smsResult['success']) {
            $smsSent++;
        } else {
            $smsFailed++;
        }
    }

    logAudit('notify', 'announcement', $announcementId, "Emailed {$emailSent} and texted {$smsSent} resident(s)" . (($emailFailed + $smsFailed) > 0 ? ", " . ($emailFailed + $smsFailed) . ' failed' : ''));

    return [
        'sent' => $emailSent,
        'failed' => $emailFailed,
        'emails_sent' => $emailSent,
        'emails_failed' => $emailFailed,
        'sms_sent' => $smsSent,
        'sms_failed' => $smsFailed,
    ];
}

/** Notifies one resident after an administrator records a violation. */
function notifyResidentOfViolation(int $violationId): array {
    $connection = connectDb();
    $stmt = $connection->prepare(
        'SELECT v.violation_type, v.description, v.penalty_type, v.fine_amount, v.due_date,
                u.full_name, u.email, u.contact_number
         FROM violations v INNER JOIN users u ON u.id = v.user_id
         WHERE v.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $violationId);
    $stmt->execute();
    $violation = $stmt->get_result()->fetch_assoc();
    if (!$violation) {
        return ['email_sent' => false, 'sms_sent' => false];
    }

    $residentName = htmlspecialchars($violation['full_name'], ENT_QUOTES, 'UTF-8');
    $type = htmlspecialchars($violation['violation_type'], ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars($violation['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $fineText = $violation['penalty_type'] === 'fine'
        ? '<p style="color:#fbbf24;font-size:18px;font-weight:bold;">Fine: &#8369;' . number_format((float)$violation['fine_amount'], 2) . '</p>'
        : '<p style="color:#60a5fa;font-weight:bold;">Warning only</p>';
    $dueDateText = !empty($violation['due_date']) ? '<p style="color:#cbd5e1;">Due date: ' . htmlspecialchars(date('F j, Y', strtotime($violation['due_date'])), ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $descriptionText = $description !== '' ? '<p style="color:#cbd5e1;line-height:1.6;">' . nl2br($description) . '</p>' : '';

    $bodyHtml = '<p style="color:#cbd5e1;">Hi ' . $residentName . ', a violation has been recorded for your account.</p>'
        . '<p style="font-size:16px;font-weight:bold;color:#f8fafc;">' . $type . '</p>'
        . $fineText . $dueDateText . $descriptionText
        . '<p style="color:#9ca3af;font-size:13px;">Please review the details in the resident portal. Contact management if you have questions.</p>';
    $emailSent = sendMail($violation['email'], '[Celandine Residences] Violation Notice: ' . $violation['violation_type'], emailLayout('Violation Notice', $type, $bodyHtml));

    $smsMessage = 'Celandine Residences: Violation notice - ' . $violation['violation_type'] . '. ';
    if ($violation['penalty_type'] === 'fine') {
        $smsMessage .= 'Fine P' . number_format((float)$violation['fine_amount'], 2) . '. ';
    } else {
        $smsMessage .= 'Warning only. ';
    }
    $smsMessage .= 'Check the resident portal for details.';
    $smsResult = sendSms($violation['contact_number'], $smsMessage);

    logAudit('notify', 'violation', $violationId, 'Violation notification: email ' . ($emailSent ? 'sent' : 'failed') . ', SMS ' . ($smsResult['success'] ? 'sent' : 'failed'));
    return ['email_sent' => $emailSent, 'sms_sent' => $smsResult['success']];
}

function ensureReminderColumn(mysqli $connection): void {
    $columnCheck = $connection->query("SHOW COLUMNS FROM payments LIKE 'reminder_sent_at'");
    if (!$columnCheck || $columnCheck->num_rows === 0) {
        $connection->query('ALTER TABLE payments ADD COLUMN reminder_sent_at DATETIME DEFAULT NULL');
    }
}

/**
 * Emails + texts a resident that a new Statement of Account is ready,
 * with the itemized breakdown and total. Called right after a bill is
 * generated (superadmin/generate_bills.php).
 */
function notifyResidentOfNewBill(int $paymentId): bool {
    $connection = connectDb();
    $bill = getBillWithItems($connection, $paymentId);
    if (!$bill) {
        return false;
    }
    $userStmt = $connection->prepare('SELECT full_name, email, contact_number FROM users WHERE id = ? LIMIT 1');
    $userStmt->bind_param('i', $bill['user_id']);
    $userStmt->execute();
    $resident = $userStmt->get_result()->fetch_assoc();
    if (!$resident) {
        return false;
    }

    $amountFormatted = number_format((float)$bill['amount'], 2);
    $dueDateFormatted = date('F j, Y', strtotime($bill['due_date']));

    $rows = '';
    foreach ($bill['items'] as $item) {
        $rows .= '<tr><td style="padding:6px 0;color:#cbd5e1;">' . htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') . '</td><td style="padding:6px 0;text-align:right;color:#cbd5e1;">&#8369;' . number_format((float)$item['amount'], 2) . '</td></tr>';
    }

    $bodyHtml = '<p style="color:#cbd5e1;">Hi ' . htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') . ', your billing statement is now available.</p>'
        . '<table style="width:100%; border-collapse:collapse; margin:16px 0;">' . $rows . '</table>'
        . '<div style="background:#1f293d;border:1px solid #4b5563;border-radius:8px;padding:16px 20px;">'
        . '<div style="color:#9ca3af;font-size:12px;">Total Due</div><div style="font-size:24px;font-weight:bold;color:#fbbf24;">&#8369;' . $amountFormatted . '</div>'
        . '<div style="color:#9ca3af;font-size:12px;margin-top:10px;">Due Date</div><div style="font-size:15px;">' . $dueDateFormatted . '</div>'
        . '</div><p style="color:#9ca3af;font-size:13px;">View the full breakdown and pay online from Billing &amp; Payments in the resident portal.</p>';

    $emailSent = sendMail($resident['email'], '[Celandine Residences] Your Billing Statement is Ready', emailLayout('Monthly Bill Available', 'Total: ₱' . $amountFormatted, $bodyHtml));
    sendSms($resident['contact_number'], "Celandine Residences: Your billing statement is ready. Total: P{$amountFormatted}, due {$dueDateFormatted}. Check the resident portal for details.");

    return $emailSent;
}

/**
 * Emails + texts residents whose dues are due within $daysAhead days or
 * already overdue. Safe to call repeatedly (e.g. an external cron pinger
 * hitting cron/send_due_reminders.php, since this project's free-tier
 * host has no built-in cron) — a resident is only reminded once every
 * 24 hours no matter how often this runs.
 */
function sendDueDateReminders(int $daysAhead = 3): array {
    $connection = connectDb();
    ensurePaymentsTable($connection);
    ensureReminderColumn($connection);

    $daysAhead = max(0, $daysAhead);
    $result = $connection->query(
        "SELECT p.id, p.amount, p.due_date, p.status, u.full_name, u.email, u.contact_number
         FROM payments p INNER JOIN users u ON u.id = p.user_id
         WHERE p.status IN ('pending', 'overdue')
         AND p.due_date <= DATE_ADD(CURDATE(), INTERVAL {$daysAhead} DAY)
         AND (p.reminder_sent_at IS NULL OR p.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))"
    );
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $emailSent = 0;
    $smsSent = 0;

    foreach ($rows as $row) {
        $isOverdue = strtotime($row['due_date']) < strtotime(date('Y-m-d'));
        $dueDateFormatted = date('F j, Y', strtotime($row['due_date']));
        $amountFormatted = number_format((float)$row['amount'], 2);
        $statusLabel = $isOverdue ? 'overdue' : 'due soon';

        $bodyHtml = '<p style="color:#cbd5e1;line-height:1.6;">Hi ' . htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') . ', this is a reminder about your association dues.</p>'
            . '<div style="background:#1f293d;border:1px solid #4b5563;border-radius:8px;padding:16px 20px;margin:20px 0;">'
            . '<div style="color:#9ca3af;font-size:12px;">Amount Due</div><div style="font-size:24px;font-weight:bold;color:#fbbf24;">&#8369;' . $amountFormatted . '</div>'
            . '<div style="color:#9ca3af;font-size:12px;margin-top:10px;">Due Date</div><div style="font-size:15px;">' . $dueDateFormatted . ($isOverdue ? ' <span style="color:#ef4444;font-weight:bold;">(overdue)</span>' : '') . '</div>'
            . '</div><p style="color:#9ca3af;font-size:13px;">Please settle this through the resident portal\'s Billing &amp; Payments page.</p>';

        $subject = $isOverdue ? '[Celandine Residences] Overdue Payment Reminder' : '[Celandine Residences] Upcoming Payment Due';
        if (sendMail($row['email'], $subject, emailLayout('Payment Reminder', 'Your dues are ' . $statusLabel, $bodyHtml))) {
            $emailSent++;
        }

        $smsMessage = 'Celandine Residences: Your due of P' . $amountFormatted . ' is ' . ($isOverdue ? 'OVERDUE' : ('due on ' . $dueDateFormatted)) . '. Please settle via the resident portal.';
        $smsResult = sendSms($row['contact_number'], $smsMessage);
        if ($smsResult['success']) {
            $smsSent++;
        }

        $update = $connection->prepare('UPDATE payments SET reminder_sent_at = NOW() WHERE id = ?');
        $update->bind_param('i', $row['id']);
        $update->execute();
    }

    trackEvent('due_reminders_sent', "{$emailSent} emails, {$smsSent} sms of " . count($rows) . ' due');

    return ['due_count' => count($rows), 'emails_sent' => $emailSent, 'sms_sent' => $smsSent];
}
