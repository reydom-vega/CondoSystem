<?php
/**
 * Sends payment due-date reminders (email + SMS) for dues that are
 * upcoming or overdue.
 *
 * InfinityFree's free tier has no reliable built-in cron (see
 * INFINITYFREE_SETUP.md), so this script is written to run either way:
 *
 *   - CLI, if you ever move to a host that supports cron:
 *       php cron/send_due_reminders.php
 *
 *   - HTTP, pinged by a free external scheduler such as cron-job.org,
 *     pointed at (once a day is plenty):
 *       https://yourdomain.com/cron/send_due_reminders.php?secret=YOUR_CRON_SECRET
 *
 * Set CONDO_CRON_SECRET to a long random string before relying on the
 * HTTP path — anyone with the right secret can trigger this on demand,
 * so treat it like a password. It's also exposed as a "Send Reminders
 * Now" button in superadmin/unitpayments.php for on-demand use.
 *
 * Safe to run more than once a day: sendDueDateReminders() only
 * re-notifies a given payment after 24 hours have passed.
 */

require_once __DIR__ . '/../config.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
    $providedSecret = $_GET['secret'] ?? '';
    $expectedSecret = appSetting('CONDO_CRON_SECRET', 'change-me-to-a-random-string');

    if ($expectedSecret === 'change-me-to-a-random-string' || !hash_equals($expectedSecret, $providedSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden. Set CONDO_CRON_SECRET and pass it as ?secret=']);
        exit;
    }
}

$daysAhead = isset($_GET['days_ahead']) ? max(0, (int)$_GET['days_ahead']) : 3;
$result = sendDueDateReminders($daysAhead);

if ($isCli) {
    echo "Due reminders run at " . date('Y-m-d H:i:s') . PHP_EOL;
    echo "  Dues matched:  {$result['due_count']}" . PHP_EOL;
    echo "  Emails sent:   {$result['emails_sent']}" . PHP_EOL;
    echo "  SMS sent:      {$result['sms_sent']}" . PHP_EOL;
} else {
    echo json_encode([
        'ran_at'      => date('c'),
        'due_count'   => $result['due_count'],
        'emails_sent' => $result['emails_sent'],
        'sms_sent'    => $result['sms_sent'],
    ]);
}
