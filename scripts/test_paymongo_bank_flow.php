<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';

$expectedChannels = [
    'dob' => 'online_banking',
    'brankas' => 'online_banking',
    'gcash' => 'gcash',
    'paymaya' => 'maya',
];
foreach ($expectedChannels as $input => $expected) {
    if (normalizePaymentChannel($input) !== $expected) {
        fwrite(STDERR, "Channel mapping failed for {$input}.\n");
        exit(1);
    }
}

$bankMethods = paymongoPaymentMethodsForSelection('bank');
$onlineMethods = paymongoPaymentMethodsForSelection('online');
if ($bankMethods !== ['dob'] || in_array('dob', $onlineMethods, true) || in_array('brankas', $onlineMethods, true)) {
    fwrite(STDERR, "PayMongo payment method selections are not separated correctly.\n");
    exit(1);
}

$event = [
    'data' => ['attributes' => [
        'type' => 'payment.paid',
        'data' => [
            'id' => 'pay_test',
            'type' => 'payment',
            'attributes' => [
                'metadata' => ['payment_id' => '42'],
                'payment_intent_id' => 'pi_test',
                'amount' => 60000,
                'source' => ['type' => 'brankas'],
            ],
        ],
    ]],
];
$context = extractPaymongoWebhookContext($event);
if ($context['internal_payment_id'] !== 42 || $context['payment_channel'] !== 'online_banking' || $context['amount'] !== 60000) {
    fwrite(STDERR, "Bank payment webhook extraction failed.\n");
    exit(1);
}

echo "PayMongo hosted bank checkout tests passed.\n";
