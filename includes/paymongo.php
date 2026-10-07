<?php
/**
 * PayMongo integration (sandbox/test mode).
 *
 * Uses the Checkout API: we create a Checkout Session from the server and
 * redirect the resident to PayMongo's hosted payment page, then trust the
 * `checkout_session.payment.paid` webhook (see webhooks/paymongo_webhook.php)
 * to confirm the payment actually went through. The success/cancel
 * redirect alone is never treated as proof of payment — a resident could
 * hit "back" on the success URL without paying, and the webhook is the
 * only signed, server-to-server confirmation.
 *
 * Reference: https://developers.paymongo.com/docs/checkout-api
 *
 * Configure these values with your own PayMongo credentials. Keep this file
 * private and never commit real keys to a public repository. Use test keys for
 * sandbox payments and live keys only when ready to accept real payments.
 */

const PAYMONGO_SECRET_KEY = 'apikeydito';
const PAYMONGO_WEBHOOK_SECRET = 'apikeydito';
const PAYMONGO_PAYMENT_METHODS_DEFAULT = 'card,gcash,paymaya,dob';
const PARKING_STICKER_PRICE = 1000.00;

function ensureParkingStickerOrdersTable(mysqli $connection): bool {
    $created = $connection->query("CREATE TABLE IF NOT EXISTS parking_sticker_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        amount DECIMAL(10, 2) NOT NULL DEFAULT 1000.00,
        status ENUM('pending', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
        paymongo_checkout_id VARCHAR(100) DEFAULT NULL,
        checkout_url VARCHAR(500) DEFAULT NULL,
        paymongo_payment_id VARCHAR(100) DEFAULT NULL,
        gateway_status VARCHAR(50) DEFAULT NULL,
        bill_payment_id INT DEFAULT NULL,
        claim_status ENUM('not_submitted', 'pending', 'issued') NOT NULL DEFAULT 'not_submitted',
        proof_file VARCHAR(100) DEFAULT NULL,
        proof_submitted_at DATETIME DEFAULT NULL,
        issued_by INT DEFAULT NULL,
        issued_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME DEFAULT NULL,
        INDEX (user_id),
        INDEX (status),
        UNIQUE KEY ux_sticker_checkout_id (paymongo_checkout_id),
        CONSTRAINT fk_sticker_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
    if (!$created) {
        return false;
    }

    $checkoutUrlColumn = $connection->query("SHOW COLUMNS FROM parking_sticker_orders LIKE 'checkout_url'");
    if ($checkoutUrlColumn && $checkoutUrlColumn->num_rows === 0) {
        if (!$connection->query('ALTER TABLE parking_sticker_orders ADD checkout_url VARCHAR(500) DEFAULT NULL AFTER paymongo_checkout_id')) {
            return false;
        }
    }

    $columns = [
        'quantity' => 'ALTER TABLE parking_sticker_orders ADD quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id',
        'bill_payment_id' => 'ALTER TABLE parking_sticker_orders ADD bill_payment_id INT DEFAULT NULL AFTER gateway_status',
        'claim_status' => "ALTER TABLE parking_sticker_orders ADD claim_status ENUM('not_submitted', 'pending', 'issued') NOT NULL DEFAULT 'not_submitted' AFTER bill_payment_id",
        'proof_file' => 'ALTER TABLE parking_sticker_orders ADD proof_file VARCHAR(100) DEFAULT NULL AFTER claim_status',
        'proof_submitted_at' => 'ALTER TABLE parking_sticker_orders ADD proof_submitted_at DATETIME DEFAULT NULL AFTER proof_file',
        'issued_by' => 'ALTER TABLE parking_sticker_orders ADD issued_by INT DEFAULT NULL AFTER proof_submitted_at',
        'issued_at' => 'ALTER TABLE parking_sticker_orders ADD issued_at DATETIME DEFAULT NULL AFTER issued_by',
    ];
    foreach ($columns as $column => $alterSql) {
        $exists = $connection->query("SHOW COLUMNS FROM parking_sticker_orders LIKE '{$column}'");
        if ($exists && $exists->num_rows === 0 && !$connection->query($alterSql)) {
            return false;
        }
    }

    return true;
}

function createParkingStickerOrderForBill(int $userId, int $billPaymentId, int $quantity = 1): int|false {
    $connection = connectDb();
    if (!ensureParkingStickerOrdersTable($connection)) {
        return false;
    }
    if ($quantity < 1 || $quantity > 10) {
        return false;
    }
    $amount = PARKING_STICKER_PRICE * $quantity;
    $stmt = $connection->prepare("INSERT INTO parking_sticker_orders (user_id, quantity, amount, bill_payment_id, status, claim_status) VALUES (?, ?, ?, ?, 'pending', 'not_submitted')");
    $stmt->bind_param('iidi', $userId, $quantity, $amount, $billPaymentId);
    if (!$stmt->execute()) {
        return false;
    }
    return (int)$connection->insert_id;
}

function getLatestParkingStickerOrder(int $userId): ?array {
    $connection = connectDb();
    if (!ensureParkingStickerOrdersTable($connection)) {
        return null;
    }
    $stmt = $connection->prepare('SELECT o.*, p.status AS bill_status, p.paid_at AS bill_paid_at FROM parking_sticker_orders o INNER JOIN payments p ON p.id = o.bill_payment_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function submitParkingStickerProof(int $orderId, int $userId, string $proofFile): bool {
    $connection = connectDb();
    if (!ensureParkingStickerOrdersTable($connection)) {
        return false;
    }
    $stmt = $connection->prepare("UPDATE parking_sticker_orders o INNER JOIN payments p ON p.id = o.bill_payment_id SET o.proof_file = ?, o.claim_status = 'pending', o.proof_submitted_at = NOW() WHERE o.id = ? AND o.user_id = ? AND p.status = 'paid' AND o.claim_status = 'not_submitted' AND o.proof_file IS NULL");
    $stmt->bind_param('sii', $proofFile, $orderId, $userId);
    return $stmt->execute() && $stmt->affected_rows === 1;
}

function getParkingStickerClaims(mysqli $connection): array {
    if (!ensureParkingStickerOrdersTable($connection)) {
        return [];
    }
    $result = $connection->query("SELECT o.id, o.user_id, o.quantity, o.amount, o.claim_status, o.proof_file, o.proof_submitted_at, o.issued_at, p.status AS payment_status, p.payment_method, p.paymongo_payment_id, p.gateway_status, p.paid_at, u.full_name, u.username, u.email, u.unit_number FROM parking_sticker_orders o INNER JOIN payments p ON p.id = o.bill_payment_id INNER JOIN users u ON u.id = o.user_id ORDER BY (p.status = 'paid' AND o.claim_status <> 'issued') DESC, FIELD(p.status, 'pending', 'overdue', 'paid'), o.created_at DESC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function markParkingStickerIssued(int $orderId, int $adminId): bool {
    $connection = connectDb();
    if (!ensureParkingStickerOrdersTable($connection)) {
        return false;
    }
    $stmt = $connection->prepare("UPDATE parking_sticker_orders o INNER JOIN payments p ON p.id = o.bill_payment_id SET o.status = 'paid', o.claim_status = 'issued', o.issued_by = ?, o.issued_at = NOW() WHERE o.id = ? AND o.claim_status <> 'issued' AND p.status = 'paid'");
    $stmt->bind_param('ii', $adminId, $orderId);
    return $stmt->execute() && $stmt->affected_rows === 1;
}

function paymongoSecretKey(): string {
    return appSetting('CONDO_PAYMONGO_SECRET_KEY', PAYMONGO_SECRET_KEY);
}

function paymongoWebhookSecret(): string {
    return appSetting('CONDO_PAYMONGO_WEBHOOK_SECRET', PAYMONGO_WEBHOOK_SECRET);
}

function ensurePaymongoColumns(mysqli $connection): void {
    $checks = [
        'paymongo_checkout_id' => "ALTER TABLE payments ADD COLUMN paymongo_checkout_id VARCHAR(100) DEFAULT NULL",
        'paymongo_payment_id'  => "ALTER TABLE payments ADD COLUMN paymongo_payment_id VARCHAR(100) DEFAULT NULL",
        'checkout_url'         => "ALTER TABLE payments ADD COLUMN checkout_url VARCHAR(500) DEFAULT NULL",
        'gateway_status'       => "ALTER TABLE payments ADD COLUMN gateway_status VARCHAR(50) DEFAULT NULL",
        'payment_channel'      => "ALTER TABLE payments ADD COLUMN payment_channel VARCHAR(30) DEFAULT NULL",
    ];
    foreach ($checks as $column => $alterSql) {
        $columnCheck = $connection->query("SHOW COLUMNS FROM payments LIKE '{$column}'");
        if (!$columnCheck || $columnCheck->num_rows === 0) {
            $connection->query($alterSql);
        }
    }
}

function normalizePaymentChannel(?string $channel): ?string {
    $normalized = strtolower(trim((string)$channel));
    return match ($normalized) {
        'gcash' => 'gcash',
        'paymaya', 'maya' => 'maya',
        'dob', 'brankas', 'online_banking' => 'online_banking',
        'card', 'cards', 'credit_card', 'debit_card' => 'card',
        'bank', 'bank_transfer', 'bank-transfer', 'instapay', 'pesonet' => 'bank',
        'cash' => 'cash',
        default => null,
    };
}

function paymentChannelLabel(?string $channel, ?string $paymentMethod = null): string {
    $channel = normalizePaymentChannel($channel) ?? normalizePaymentChannel($paymentMethod);
    if ($channel !== null) {
        return match ($channel) {
            'gcash' => 'GCash',
            'maya' => 'Maya',
            'online_banking' => 'Online Banking',
            'card' => 'Card',
            'bank' => 'Bank Transfer',
            'cash' => 'Cash',
        };
    }

    return match ($paymentMethod) {
        'online' => 'PayMongo payment (method unavailable)',
        'manual' => 'Bank / Cash (not specified)',
        default => ucfirst((string)$paymentMethod),
    };
}

function paymongoPaymentMethodsForSelection(string $selection): array {
    $configuredMethods = array_values(array_filter(array_map('trim', explode(',', appSetting('CONDO_PAYMONGO_METHODS', PAYMONGO_PAYMENT_METHODS_DEFAULT)))));

    if ($selection === 'bank') {
        return in_array('dob', $configuredMethods, true) ? ['dob'] : [];
    }
    if ($selection === 'online') {
        return array_values(array_diff($configuredMethods, ['dob', 'brankas']));
    }

    return [];
}

/**
 * Creates a PayMongo Checkout Session for one bill and returns
 * ['success' => bool, 'checkout_url' => ?string, 'checkout_id' => ?string, 'error' => ?string].
 *
 * $items is the bill's actual line items — [['category' => 'Water', 'amount' => 450.00], ...]
 * — sent to PayMongo as real line_items so the resident sees the same
 * breakdown on PayMongo's hosted page as on their Statement of Account.
 *
 * $paymentId is our own payments.id — sent as PayMongo metadata so the
 * webhook can reconcile the event back to this exact row.
 */
function createPaymongoCheckoutSession(int $paymentId, array $items, string $description, string $residentName, string $residentEmail, string $successUrl, string $cancelUrl, array $extraMetadata = [], ?array $paymentMethodTypes = null): array {
    $secretKey = paymongoSecretKey();
    if ($secretKey === '') {
        return ['success' => false, 'checkout_url' => null, 'checkout_id' => null, 'error' => 'PayMongo is not configured. Set CONDO_PAYMONGO_SECRET_KEY for your PayMongo account.'];
    }
    $methods = $paymentMethodTypes ?? array_filter(array_map('trim', explode(',', appSetting('CONDO_PAYMONGO_METHODS', PAYMONGO_PAYMENT_METHODS_DEFAULT))));
    if (empty($methods)) {
        return ['success' => false, 'checkout_url' => null, 'checkout_id' => null, 'error' => 'No PayMongo payment methods are enabled for this selection.'];
    }

    $lineItems = [];
    foreach ($items as $item) {
        $amount = (float)($item['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $lineItems[] = [
            'currency' => 'PHP',
            'amount'   => (int)round($amount * 100),
            'name'     => $item['category'] ?? 'Charge',
            'quantity' => 1,
        ];
    }
    if (empty($lineItems)) {
        return ['success' => false, 'checkout_url' => null, 'checkout_id' => null, 'error' => 'This bill has no chargeable items.'];
    }

    $payload = [
        'data' => [
            'attributes' => [
                'send_email_receipt' => true,
                'show_line_items'    => true,
                'show_description'   => true,
                'description'        => $description,
                'reference_number'   => 'DUES-' . $paymentId,
                'line_items'         => $lineItems,
                'payment_method_types' => array_values($methods),
                'billing' => [
                    'name'  => $residentName,
                    'email' => $residentEmail,
                ],
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'metadata' => [
                    'payment_id' => (string)$paymentId,
                ] + $extraMetadata,
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => $secretKey . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('PayMongo connection error: ' . $curlError);
        return ['success' => false, 'checkout_url' => null, 'checkout_id' => null, 'error' => 'Could not reach PayMongo. Please try again.'];
    }

    $decoded = json_decode($body, true);

    if ($httpCode < 200 || $httpCode >= 300 || !isset($decoded['data']['id'])) {
        $apiError = $decoded['errors'][0]['detail'] ?? ('PayMongo request failed (HTTP ' . $httpCode . ')');
        error_log('PayMongo checkout session error: ' . $body);
        return ['success' => false, 'checkout_url' => null, 'checkout_id' => null, 'error' => $apiError];
    }

    return [
        'success'      => true,
        'checkout_url' => $decoded['data']['attributes']['checkout_url'] ?? null,
        'checkout_id'  => $decoded['data']['id'],
        'error'        => null,
    ];
}

function reconcilePaymongoCheckoutPayment(mysqli $connection, int $paymentId, int $userId): bool {
    if (paymongoSecretKey() === '') {
        error_log('PayMongo checkout verification skipped: CONDO_PAYMONGO_SECRET_KEY is not configured.');
        return false;
    }
    ensurePaymongoColumns($connection);
    $paymentQuery = $connection->prepare('SELECT id, user_id, status, paymongo_checkout_id FROM payments WHERE id = ? AND user_id = ? LIMIT 1');
    $paymentQuery->bind_param('ii', $paymentId, $userId);
    $paymentQuery->execute();
    $payment = $paymentQuery->get_result()->fetch_assoc();
    if (!$payment || $payment['status'] === 'paid' || empty($payment['paymongo_checkout_id'])) {
        return false;
    }

    $checkoutId = (string)$payment['paymongo_checkout_id'];
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($checkoutId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERPWD => paymongoSecretKey() . ':',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('PayMongo checkout verification failed for payment #' . $paymentId . ': ' . ($curlError ?: 'HTTP ' . $httpCode));
        return false;
    }

    $checkout = json_decode($body, true);
    $resource = $checkout['data'] ?? [];
    $attributes = $resource['attributes'] ?? [];
    if (($resource['id'] ?? '') !== $checkoutId) {
        return false;
    }

    $metadataPaymentId = (int)($attributes['metadata']['payment_id'] ?? 0);
    if ($metadataPaymentId > 0 && $metadataPaymentId !== $paymentId) {
        error_log('PayMongo checkout metadata mismatch for payment #' . $paymentId);
        return false;
    }

    $isPaid = ($attributes['status'] ?? '') === 'paid';
    $paymongoPaymentId = null;
    foreach (($attributes['payments'] ?? []) as $checkoutPayment) {
        $paymentAttributes = $checkoutPayment['attributes'] ?? [];
        if (($paymentAttributes['status'] ?? '') === 'paid') {
            $isPaid = true;
            $paymongoPaymentId = $checkoutPayment['id'] ?? null;
            break;
        }
    }
    if (!$isPaid) {
        return false;
    }

    $gatewayStatus = 'checkout_session.paid';
    $update = $connection->prepare("UPDATE payments SET status = 'paid', paid_at = COALESCE(paid_at, NOW()), paymongo_payment_id = COALESCE(?, paymongo_payment_id), gateway_status = ? WHERE id = ? AND user_id = ? AND status <> 'paid'");
    $update->bind_param('ssii', $paymongoPaymentId, $gatewayStatus, $paymentId, $userId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        return false;
    }

    markViolationsPaidForBill($paymentId);
    logAudit('checkout_reconcile', 'payment', $paymentId, 'PayMongo checkout status confirmed payment as paid');
    return true;
}

/**
 * Verifies the `Paymongo-Signature` header against the raw request body.
 * Must be called with the UNPARSED request body — parsing/re-encoding
 * JSON before this check can change byte-for-byte content and break the
 * signature even for a legitimate request.
 *
 * Reference: https://docs.paymongo.com/docs/developer-tools-webhook-setup-management
 */
function verifyPaymongoWebhookSignature(string $rawBody, ?string $signatureHeader): bool {
    if (!$signatureHeader) {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $segment) {
        [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
        if ($key !== null && $value !== null) {
            $parts[$key] = $value;
        }
    }

    $timestamp = $parts['t'] ?? null;
    // Test-mode signature ('te') for sandbox keys; fall back to live ('li')
    // in case the board later switches this endpoint to live keys.
    $providedSignature = $parts['te'] ?? $parts['li'] ?? null;

    if ($timestamp === null || $providedSignature === null) {
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, paymongoWebhookSecret());

    return hash_equals($expectedSignature, $providedSignature);
}

/**
 * Best-effort extraction of our internal payment_id from a decoded
 * PayMongo webhook event. Tries the metadata we attached at checkout
 * creation first (most reliable), then falls back to matching the
 * checkout session id we stored on the payments row. Returns null if
 * neither can be determined. Also extracts the paid channel from the
 * underlying payment resource when PayMongo includes it.
 */
function extractPaymongoWebhookContext(array $event): array {
    $resource = $event['data']['attributes']['data'] ?? [];
    $attributes = $resource['attributes'] ?? [];

    $metadata = $attributes['metadata'] ?? null;
    if (!$metadata && isset($attributes['payments'][0]['attributes']['metadata'])) {
        $metadata = $attributes['payments'][0]['attributes']['metadata'];
    }

    $checkoutId = null;
    if (($resource['type'] ?? '') === 'checkout_session') {
        $checkoutId = $resource['id'] ?? null;
    }

    $paymentId = null;
    $paymentAttributes = $attributes['payments'][0]['attributes'] ?? [];
    if (isset($attributes['payments'][0]['id'])) {
        $paymentId = $attributes['payments'][0]['id'];
    } elseif (($resource['type'] ?? '') === 'payment') {
        $paymentId = $resource['id'] ?? null;
        $paymentAttributes = $attributes;
    }

    $methodCandidates = [
        $paymentAttributes['source']['type'] ?? null,
        $paymentAttributes['payment_method_details']['type'] ?? null,
        $paymentAttributes['payment_method_type'] ?? null,
        $paymentAttributes['payment_method'] ?? null,
    ];
    $paymentChannel = null;
    foreach ($methodCandidates as $candidate) {
        if (is_string($candidate) && normalizePaymentChannel($candidate) !== null) {
            $paymentChannel = normalizePaymentChannel($candidate);
            break;
        }
    }

    $paymentAmount = $paymentAttributes['amount'] ?? null;

    return [
        'internal_payment_id' => isset($metadata['payment_id']) ? (int)$metadata['payment_id'] : null,
        'checkout_id'         => $checkoutId,
        'paymongo_payment_id' => $paymentId,
        'payment_channel'     => $paymentChannel,
        'amount'              => is_numeric($paymentAmount) ? (int)$paymentAmount : null,
        'event_type'          => $event['data']['attributes']['type'] ?? null,
    ];
}
