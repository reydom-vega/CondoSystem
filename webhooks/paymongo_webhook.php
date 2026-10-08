<?php
/** Signed checkout confirmation. Register checkout_session.payment.paid in PayMongo. */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); echo json_encode(['error'=>'POST required']); exit;
}
if (paymongoWebhookSecret() === '' || paymongoSecretKey() === '') {
    http_response_code(503); echo json_encode(['error'=>'Payment verification is not configured']); exit;
}
$rawBody = file_get_contents('php://input', false, null, 0, 1048577);
if ($rawBody === false || strlen($rawBody) > 1048576
    || !verifyPaymongoWebhookSignature($rawBody, $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? null)) {
    http_response_code(400); echo json_encode(['error'=>'Invalid signature']); exit;
}
$event = json_decode($rawBody, true);
if (!is_array($event)) { http_response_code(400); echo json_encode(['error'=>'Invalid payload']); exit; }
try {
    $result = confirmPaymongoCheckoutPayment(connectDb(), extractPaymongoWebhookContext($event));
    // A webhook can race checkout creation. Retry unknown sessions instead of dropping paid events.
    if (!empty($result['unmatched'])) {
        http_response_code(503); echo json_encode(['error'=>'Checkout not yet matched']); exit;
    }
    // Log identifiers and verdicts only; raw payloads contain resident billing information.
    error_log('PayMongo webhook: event=' . preg_replace('/[^A-Za-z0-9_-]/','',(string)($event['data']['id'] ?? 'unknown'))
        . ' verdict=' . (!empty($result['confirmed']) ? 'confirmed' : ($result['ignored'] ?? 'already_paid')));
    echo json_encode(['received'=>true,'confirmed'=>!empty($result['confirmed'])]);
} catch (Throwable $error) {
    error_log('PayMongo webhook confirmation could not complete.');
    http_response_code(503); echo json_encode(['error'=>'Confirmation unavailable; retry']);
}
