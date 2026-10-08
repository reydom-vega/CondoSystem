<?php
/**
 * PayMongo integration. Credentials select test or live mode.
 *
 * Uses the Checkout API: we create a Checkout Session from the server and
 * redirect the resident to PayMongo's hosted payment page, then verify the
 * signed checkout webhook or an authenticated Checkout Session lookup.
 * The stored session, exact amount, and currency must match. A browser
 * success/cancel redirect is never treated as proof of payment.
 *
 * Reference: https://developers.paymongo.com/docs/checkout-api
 *
 * Configure these values with your own PayMongo credentials. Keep this file
 * private and never commit real keys to a public repository. Use test keys for
 * sandbox payments and live keys only when ready to accept real payments.
 */

// Credentials belong in the environment, never in the application source.
const PAYMONGO_SECRET_KEY = '';
const PAYMONGO_WEBHOOK_SECRET = '';
const PAYMONGO_PAYMENT_METHODS_DEFAULT = 'card,gcash,paymaya,dob';
const PARKING_STICKER_PRICE = 1000.00;

function ensureParkingStickerOrdersTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
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
    if (!canManageBilling()) return false;
    $connection = connectDb();
    if (!ensureParkingStickerOrdersTable($connection)) {
        return false;
    }
    $policy = getParkingPolicy($connection);
    if ($quantity < 1 || $quantity > (int)$policy['sticker_max_quantity']) {
        return false;
    }
    $connection->begin_transaction();
    try {
        $bill=$connection->prepare("SELECT amount,status FROM payments WHERE id=? AND user_id=? AND status IN ('pending','overdue','paid') FOR UPDATE");
        $bill->bind_param('ii',$billPaymentId,$userId); $bill->execute(); $payment=$bill->get_result()->fetch_assoc();
        $existing=$connection->prepare('SELECT id FROM parking_sticker_orders WHERE bill_payment_id=? LIMIT 1');
        $existing->bind_param('i',$billPaymentId); $existing->execute();
        $existingOrder=$existing->get_result()->fetch_assoc();
        if (!$payment || (float)$payment['amount']<=0 || $existingOrder) { $connection->rollback(); return false; }
        $amount=(float)$payment['amount'];
        $status=$payment['status']==='paid' ? 'paid' : 'pending';
        $stmt=$connection->prepare("INSERT INTO parking_sticker_orders (user_id,quantity,amount,bill_payment_id,status,claim_status) VALUES (?,?,?,?,?,'not_submitted')");
        $stmt->bind_param('iidis',$userId,$quantity,$amount,$billPaymentId,$status);
        if (!$stmt->execute()) throw new RuntimeException('Could not link historical sticker bill.');
        $id=(int)$connection->insert_id; $connection->commit(); return $id;
    } catch (Throwable $error) { $connection->rollback(); error_log('Historical sticker linkage failed: '.$error->getMessage()); return false; }
}

function getLatestParkingStickerOrder(int $userId): ?array {
    $connection = connectDb();
    if (!ensureParkingStickerOrdersTable($connection)) {
        return null;
    }
    $stmt = $connection->prepare('SELECT o.*, p.status AS bill_status, p.paid_at AS bill_paid_at FROM parking_sticker_orders o INNER JOIN payments p ON p.id = o.bill_payment_id AND p.user_id=o.user_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 1');
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
    ensurePaymongoColumns($connection);
    ensureStickerVehicleLinks($connection);
    $result = $connection->query("SELECT o.id, o.user_id, o.quantity, o.amount, o.claim_status, o.proof_file, o.proof_submitted_at, o.issued_at, p.status AS payment_status, p.payment_method, p.paymongo_payment_id, p.gateway_status, p.paid_at, u.full_name, u.username, u.email, u.unit_number FROM parking_sticker_orders o INNER JOIN payments p ON p.id = o.bill_payment_id INNER JOIN users u ON u.id = o.user_id ORDER BY (p.status = 'paid' AND o.claim_status <> 'issued') DESC, FIELD(p.status, 'pending', 'overdue', 'paid'), o.created_at DESC");
    $claims = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($claims as &$claim) {
        $claim['vehicles'] = getStickerVehicles($connection,(int)$claim['id']);
        $context=residentContext($connection,(int)$claim['user_id']);
        $sponsorId=$context && $context['approved'] ? (int)$context['billing_user_id'] : 0;
        $claim['eligible_vehicles'] = !$claim['vehicles'] && $sponsorId>0 ? getEligibleStickerVehicles($connection,$sponsorId) : [];
    }
    unset($claim);
    return $claims;
}

function markParkingStickerIssued(int $orderId, int $adminId, array $vehicleIds = []): bool {
    if (!canReviewPermits() || $adminId !== (int)$_SESSION['user_id']) return false;
    $db = connectDb();
    if (!ensureStickerVehicleLinks($db)) return false;
    ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $find = $db->prepare("SELECT o.*, p.status AS payment_status, p.amount AS payment_amount FROM parking_sticker_orders o JOIN payments p ON p.id=o.bill_payment_id AND p.user_id=o.user_id WHERE o.id=? FOR UPDATE");
        $find->bind_param('i',$orderId); $find->execute(); $order=$find->get_result()->fetch_assoc();
        if (!$order || $order['payment_status']!=='paid' || $order['status']==='cancelled'
            || (int)round((float)$order['amount']*100)!==(int)round((float)$order['payment_amount']*100)) { $db->rollback(); return false; }
        $context=residentContext($db,(int)$order['user_id']);
        $sponsorId=$context && $context['approved'] ? (int)$context['billing_user_id'] : 0;
        if ($sponsorId<1 || !stickerVehicleBelongsToSponsor($db,$sponsorId,(int)$order['user_id'],true)) { $db->rollback(); return false; }
        $vehicles=getStickerVehicles($db,$orderId);
        if ($order['claim_status']==='issued' && $vehicles) { $db->rollback(); return false; }
        if (!$vehicles) {
            if (!linkStickerVehicles($db,$orderId,$sponsorId,(int)$order['quantity'],$vehicleIds)) { $db->rollback(); return false; }
            $vehicles=getStickerVehicles($db,$orderId);
        }
        if (count($vehicles)!==(int)$order['quantity']) { $db->rollback(); return false; }
        foreach ($vehicles as $index=>$vehicle) {
            $check=$db->prepare("SELECT id,user_id FROM vehicles WHERE id=? AND status='approved' FOR UPDATE");
            $check->bind_param('i',$vehicle['id']); $check->execute();
            $registered=$check->get_result()->fetch_assoc();
            if (!$registered || !stickerVehicleBelongsToSponsor($db,$sponsorId,(int)$registered['user_id'],true)) { $db->rollback(); return false; }
            $number='CS-' . str_pad((string)$orderId,6,'0',STR_PAD_LEFT) . '-' . str_pad((string)($index+1),2,'0',STR_PAD_LEFT);
            $update=$db->prepare('UPDATE parking_sticker_vehicles SET sticker_number=? WHERE order_id=? AND vehicle_id=?');
            $update->bind_param('sii',$number,$orderId,$vehicle['id']);
            if (!$update->execute()) throw new RuntimeException('Could not record sticker number.');
        }
        if ($order['claim_status']!=='issued') {
            $issue=$db->prepare("UPDATE parking_sticker_orders SET status='paid',claim_status='issued',issued_by=?,issued_at=NOW() WHERE id=? AND claim_status<>'issued'");
            $issue->bind_param('ii',$adminId,$orderId);
            if (!$issue->execute() || $issue->affected_rows!==1) throw new RuntimeException('Order changed before issuance.');
        }
        if (!logAudit('sticker_issued','parking_sticker',$orderId,'Sticker issuance recorded for approved registered vehicles.',$db)) throw new RuntimeException('Could not audit issuance.');
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); error_log($e->getMessage()); return false; }
}
function paymongoSecretKey(): string {
    return appSetting('CONDO_PAYMONGO_SECRET_KEY', PAYMONGO_SECRET_KEY);
}

function paymongoWebhookSecret(): string {
    return appSetting('CONDO_PAYMONGO_WEBHOOK_SECRET', PAYMONGO_WEBHOOK_SECRET);
}

function paymongoIsLiveMode(): bool {
    return str_starts_with(paymongoSecretKey(), 'sk_live_');
}

function paymongoCheckoutUrlIsSafe(?string $url): bool {
    $parts = parse_url((string)$url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && strtolower($parts['host'] ?? '') === 'checkout.paymongo.com'
        && !isset($parts['user']) && !isset($parts['pass']);
}

function ensurePaymongoColumns(mysqli $connection): void {
    if (!schemaMutationAllowed()) return;
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
    foreach (['paymongo_checkout_id'=>'ux_payment_checkout', 'paymongo_payment_id'=>'ux_payment_gateway'] as $column=>$index) {
        $exists=$connection->query("SHOW INDEX FROM payments WHERE Key_name='{$index}'");
        if ($exists && $exists->num_rows===0) $connection->query("ALTER TABLE payments ADD UNIQUE INDEX {$index} ({$column})");
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
    // The provider adapter also enforces payer authorization so a forged direct
    // call cannot bypass the resident payment workflow.
    $denied=['success'=>false,'checkout_url'=>null,'checkout_id'=>null,'error'=>'Only the approved unit owner can start a payment.'];
    if (!isLoggedIn()) return $denied;
    $authorizationDb=connectDb();
    $billOwner=$authorizationDb->prepare('SELECT user_id,status,amount,paymongo_checkout_id FROM payments WHERE id=? LIMIT 1');
    $billOwner->bind_param('i',$paymentId); $billOwner->execute();
    $billAccount=$billOwner->get_result()->fetch_assoc();
    if (!$billAccount || !in_array($billAccount['status'],['pending','overdue'],true) || (float)$billAccount['amount']<=0
        || !empty($billAccount['paymongo_checkout_id']) || !residentCanPayBill($authorizationDb,(int)$_SESSION['user_id'],(int)$billAccount['user_id'])) return $denied;
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
        error_log('PayMongo checkout session request failed (HTTP ' . $httpCode . ').');
        return ['success' => false, 'checkout_url' => null, 'checkout_id' => null, 'error' => 'The payment provider could not create a checkout. Contact billing staff if this continues.'];
    }

    return [
        'success'      => true,
        'checkout_url' => $decoded['data']['attributes']['checkout_url'] ?? null,
        'checkout_id'  => $decoded['data']['id'],
        'error'        => null,
    ];
}

require_once __DIR__ . '/payment_confirmation.php';
