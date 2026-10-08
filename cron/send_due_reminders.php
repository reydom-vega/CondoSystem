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
 *     using HTTPS POST and Authorization: Bearer <CONDO_CRON_SECRET>.
 *     This queues notices; the CLI worker performs delivery.
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST'); http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $providedSecret = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
    $expectedSecret = appSetting('CONDO_CRON_SECRET');

    if (strlen($expectedSecret) < 32 || !hash_equals($expectedSecret, $providedSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$daysAhead = isset($_POST['days_ahead']) ? min(30, max(0, (int)$_POST['days_ahead'])) : 3;
$result = sendDueDateReminders($daysAhead);

if ($isCli) {
    echo "Due reminders run at " . date('Y-m-d H:i:s') . PHP_EOL;
    echo "  Dues matched:  {$result['due_count']}" . PHP_EOL;
    echo "  Emails queued: {$result['emails_queued']}" . PHP_EOL;
    echo "  SMS queued:    {$result['sms_queued']}" . PHP_EOL;
} else {
    echo json_encode([
        'ran_at'      => date('c'),
        'due_count'   => $result['due_count'],
        'emails_queued' => $result['emails_queued'],
        'sms_queued'    => $result['sms_queued'],
    ]);
}
