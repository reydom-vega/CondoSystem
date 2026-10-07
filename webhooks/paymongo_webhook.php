<?php
/**
 * PayMongo webhook receiver.
 *
 * Configure this URL in the PayMongo Dashboard → Developers → Webhooks,
 * subscribed to at least `checkout_session.payment.paid`:
 *   https:thecelandinehomes.com/webhooks/paymongo_webhook.php
 *
 * This is the ONLY place an online payment gets marked 'paid'. The
 * resident's browser redirect (resident/payment_return.php) never marks
 * anything paid by itself — a signed, server-to-server webhook is the
 * only thing trusted, because a redirect URL can be visited by hand
 * without ever paying.
 */

require_once __DIR__ . '/../config.php';

// PayMongo needs the exact, unparsed request body to check the
// signature — read it before anything else touches the request.
$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? null;

if (!verifyPaymongoWebhookSignature($rawBody, $signatureHeader)) {
    error_log('PayMongo webhook: signature verification failed.');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Signature is verified from here on, so PayMongo should get a 200 for
// any outcome below (including "couldn't match a payment") — that's our
// bug to fix server-side, not something a retry will solve.
http_response_code(200);
header('Content-Type: application/json');

$context = extractPaymongoWebhookContext($event);
error_log('PayMongo webhook received: type=' . ($context['event_type'] ?? 'unknown') . ' payload=' . $rawBody);

$isPaidEvent = is_string($context['event_type']) && str_contains($context['event_type'], 'payment.paid');
if (!$isPaidEvent) {
    // Other event types (e.g. payment.failed) are logged above for
    // visibility but don't change payment status.
    echo json_encode(['received' => true]);
    exit;
}

$connection = connectDb();
ensurePaymongoColumns($connection);

$payment = null;
if ($context['internal_payment_id']) {
    $stmt = $connection->prepare('SELECT p.*, u.full_name, u.email, u.contact_number FROM payments p INNER JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1');
    $stmt->bind_param('i', $context['internal_payment_id']);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
}
if (!$payment && $context['checkout_id']) {
    $stmt = $connection->prepare('SELECT p.*, u.full_name, u.email, u.contact_number FROM payments p INNER JOIN users u ON u.id = p.user_id WHERE p.paymongo_checkout_id = ? LIMIT 1');
    $stmt->bind_param('s', $context['checkout_id']);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
}

if (!$payment) {
    error_log('PayMongo webhook: could not match any payment row. context=' . json_encode($context));
    echo json_encode(['received' => true, 'matched' => false]);
    exit;
}

if ($payment['gateway_status'] === 'rolled_forward' && empty($payment['paymongo_checkout_id'])) {
    error_log('PayMongo webhook ignored: payment #' . $payment['id'] . ' was rolled forward and has no current checkout.');
    echo json_encode(['received' => true, 'ignored' => 'stale_checkout']);
    exit;
}
if ($context['checkout_id'] && $context['checkout_id'] !== $payment['paymongo_checkout_id']) {
    error_log('PayMongo webhook ignored: checkout ID does not match payment #' . $payment['id'] . '.');
    echo json_encode(['received' => true, 'ignored' => 'checkout_mismatch']);
    exit;
}
if ($context['amount'] !== null && $context['amount'] !== (int)round((float)$payment['amount'] * 100)) {
    error_log('PayMongo webhook ignored: amount does not match payment #' . $payment['id'] . '.');
    echo json_encode(['received' => true, 'ignored' => 'amount_mismatch']);
    exit;
}

if ($payment['status'] === 'paid') {
    // Already processed (webhooks can be delivered more than once) — no-op.
    echo json_encode(['received' => true, 'already_paid' => true]);
    exit;
}

$paymongoPaymentId = $context['paymongo_payment_id'];
$paymentChannel = $context['payment_channel'];
$eventType = $context['event_type'];

$update = $connection->prepare("UPDATE payments SET status = 'paid', paid_at = NOW(), paymongo_payment_id = ?, gateway_status = ?, payment_channel = COALESCE(?, payment_channel) WHERE id = ?");
$update->bind_param('sssi', $paymongoPaymentId, $eventType, $paymentChannel, $payment['id']);
$update->execute();
markViolationsPaidForBill((int)$payment['id']);

logAudit('webhook_confirm', 'payment', (int)$payment['id'], 'PayMongo confirmed payment via ' . $eventType);

$amountFormatted = number_format((float)$payment['amount'], 2);
$emailBody = '<!doctype html><html><body style="margin:0;background:#0a0f1d;color:#fff;font-family:Arial,sans-serif;padding:24px;">'
    . '<div style="max-width:520px;margin:0 auto;background:#111827;border:1px solid #374151;border-radius:12px;padding:32px;">'
    . '<div style="color:#10b981;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Payment Received</div>'
    . '<h1 style="font-size:22px;margin:14px 0 16px;">Thank you, ' . htmlspecialchars($payment['full_name'], ENT_QUOTES, 'UTF-8') . '!</h1>'
    . '<p style="color:#cbd5e1;">We\'ve received your payment of <strong>&#8369;' . $amountFormatted . '</strong> for your association dues.</p>'
    . '<p style="color:#6b7280;font-size:11px;margin-top:28px;">The Celandine Residences &middot; This is an automated message from the resident portal.</p>'
    . '</div></body></html>';
sendMail($payment['email'], '[Celandine Residences] Payment Received', $emailBody);
sendSms($payment['contact_number'], 'Celandine Residences: We received your payment of P' . $amountFormatted . '. Thank you!');

echo json_encode(['received' => true, 'matched' => true, 'payment_id' => $payment['id']]);
