<?php
/** Only a paid payment resource for the stored checkout can settle a bill. */
function reconcilePaymongoCheckoutPayment(mysqli $connection, int $paymentId, int $userId): bool {
    if (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0)!==$userId) return false;
    if (paymongoSecretKey() === '') return false;
    ensurePaymongoColumns($connection);
    $find = $connection->prepare("SELECT user_id,paymongo_checkout_id FROM payments WHERE id=? AND status IN ('pending','overdue')");
    $find->bind_param('i', $paymentId); $find->execute();
    $bill = $find->get_result()->fetch_assoc();
    if (!$bill || !residentCanPayBill($connection,$userId,(int)$bill['user_id'],$paymentId) || empty($bill['paymongo_checkout_id'])) return false;
    $checkoutId = (string)$bill['paymongo_checkout_id'];
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($checkoutId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12,
        CURLOPT_USERPWD=>paymongoSecretKey() . ':', CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return false;
    $response = json_decode($body, true);
    $resource = $response['data'] ?? [];
    if (($resource['id'] ?? '') !== $checkoutId || ($resource['type'] ?? '') !== 'checkout_session') return false;
    $result = confirmPaymongoCheckoutPayment($connection, extractPaymongoWebhookContext([
        'data'=>['attributes'=>['type'=>'checkout_session.payment.paid','livemode'=>paymongoIsLiveMode(),'data'=>$resource]]
    ]), (int)$bill['user_id']);
    return ($result['confirmed'] ?? false) === true;
}

/** Verify the exact bytes, the configured test/live mode, and a five-minute replay window. */
function verifyPaymongoWebhookSignature(string $rawBody, ?string $signatureHeader): bool {
    $secret = paymongoWebhookSecret();
    if ($secret === '' || paymongoSecretKey() === '' || !$signatureHeader) return false;
    $parts = [];
    foreach (explode(',', $signatureHeader) as $segment) {
        [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
        if ($key !== null && $value !== null) $parts[$key] = $value;
    }
    $timestamp = $parts['t'] ?? '';
    $signature = $parts[paymongoIsLiveMode() ? 'li' : 'te'] ?? '';
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300
        || !preg_match('/^[a-f0-9]{64}$/D', $signature)) return false;
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $signature);
}

/** Support the v1 Event envelope and the current hosted-checkout envelope. */
function extractPaymongoWebhookContext(array $event): array {
    $eventAttributes = $event['data']['attributes'] ?? $event['data'] ?? [];
    $resource = $eventAttributes['data'] ?? [];
    $attributes = $resource['attributes'] ?? [];
    $paymentResource = [];
    foreach (($attributes['payments'] ?? []) as $attempt) {
        if (($attempt['attributes']['status'] ?? '') === 'paid') { $paymentResource = $attempt; break; }
    }
    if (($resource['type'] ?? '') === 'payment') $paymentResource = $resource;
    $paid = $paymentResource['attributes'] ?? [];
    $metadata = $attributes['metadata'] ?? $paid['metadata'] ?? [];
    $channel = null;
    foreach ([$paid['source']['type'] ?? null, $paid['payment_method_details']['type'] ?? null,
        $paid['payment_method_type'] ?? null, $paid['payment_method'] ?? null] as $candidate) {
        if (is_string($candidate) && normalizePaymentChannel($candidate) !== null) { $channel=normalizePaymentChannel($candidate); break; }
    }
    $amount = $paid['amount'] ?? null;
    return [
        'internal_payment_id'=>isset($metadata['payment_id']) ? (int)$metadata['payment_id'] : null,
        'checkout_id'=>($resource['type'] ?? '') === 'checkout_session' ? ($resource['id'] ?? null) : null,
        'paymongo_payment_id'=>$paymentResource['id'] ?? null,
        'payment_channel'=>$channel,
        'amount'=>is_int($amount) || (is_string($amount) && ctype_digit($amount)) ? (int)$amount : null,
        'currency'=>$paid['currency'] ?? null,
        'payment_status'=>$paid['status'] ?? null,
        'event_type'=>$eventAttributes['type'] ?? null,
        'livemode'=>$eventAttributes['livemode'] ?? $attributes['livemode'] ?? null,
    ];
}

/** Settle the bill and linked fine/sticker state together, with a locked idempotent transition. */
function confirmPaymongoCheckoutPayment(mysqli $db, array $context, ?int $expectedUserId = null): array {
    if (($context['event_type'] ?? '') !== 'checkout_session.payment.paid') return ['confirmed'=>false,'ignored'=>'event_type'];
    if (($context['livemode'] ?? null) !== paymongoIsLiveMode()) return ['confirmed'=>false,'ignored'=>'mode_mismatch'];
    if (empty($context['checkout_id']) || empty($context['paymongo_payment_id'])
        || ($context['payment_status'] ?? '') !== 'paid' || ($context['currency'] ?? '') !== 'PHP'
        || !is_int($context['amount'] ?? null)) return ['confirmed'=>false,'ignored'=>'incomplete_payment'];
    ensurePaymongoColumns($db); ensureViolationsTable($db); ensureParkingStickerOrdersTable($db); ensureAuditLogTable($db); ensureNotificationOutboxTable($db);
    $db->begin_transaction();
    try {
        $find = $db->prepare('SELECT p.*, u.full_name, u.email, u.contact_number FROM payments p JOIN users u ON u.id=p.user_id WHERE p.paymongo_checkout_id=? FOR UPDATE');
        $find->bind_param('s', $context['checkout_id']); $find->execute();
        $bill = $find->get_result()->fetch_assoc();
        if (!$bill) { $db->rollback(); return ['confirmed'=>false,'unmatched'=>true]; }
        if (($expectedUserId !== null && (int)$bill['user_id'] !== $expectedUserId)
            || (!empty($context['internal_payment_id']) && (int)$context['internal_payment_id'] !== (int)$bill['id'])
            || $context['amount'] !== (int)round((float)$bill['amount'] * 100)) {
            $db->rollback(); return ['confirmed'=>false,'ignored'=>'bill_mismatch'];
        }
        if ($bill['status'] === 'paid') { $db->rollback(); return ['confirmed'=>false,'already_paid'=>true]; }
        if (!in_array($bill['status'], ['pending','overdue'], true)) { $db->rollback(); return ['confirmed'=>false,'ignored'=>'bill_closed']; }
        $duplicate = $db->prepare('SELECT id FROM payments WHERE paymongo_payment_id=? AND id<>? LIMIT 1');
        $duplicate->bind_param('si', $context['paymongo_payment_id'], $bill['id']); $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) { $db->rollback(); return ['confirmed'=>false,'ignored'=>'payment_reused']; }
        $update = $db->prepare("UPDATE payments SET status='paid', paid_at=NOW(), paymongo_payment_id=?, gateway_status=?, payment_channel=COALESCE(?,payment_channel) WHERE id=? AND status IN ('pending','overdue')");
        $update->bind_param('sssi', $context['paymongo_payment_id'], $context['event_type'], $context['payment_channel'], $bill['id']);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Payment changed before confirmation.');
        settleLinkedBillRecords($db, (int)$bill['id']);
        if (!logAudit('gateway_confirm','payment',(int)$bill['id'],'PayMongo confirmed the checkout amount and currency.',$db)) throw new RuntimeException('Could not audit payment confirmation.');
        notifyResidentOfPayment((int)$bill['id'], $db);
        $db->commit();
        return ['confirmed'=>true,'payment'=>$bill];
    } catch (Throwable $error) {
        $db->rollback(); error_log('Payment confirmation failed: ' . $error->getMessage());
        throw $error;
    }
}

function settleLinkedBillRecords(mysqli $db, int $paymentId): void {
    $fine = $db->prepare("UPDATE violations SET status='paid' WHERE payment_id=? AND status IN ('unpaid','disputed')");
    $fine->bind_param('i', $paymentId); if (!$fine->execute()) throw new RuntimeException('Could not settle fine.');
    $sticker = $db->prepare("UPDATE parking_sticker_orders SET status='paid', paid_at=COALESCE(paid_at,NOW()) WHERE bill_payment_id=? AND status='pending'");
    $sticker->bind_param('i', $paymentId); if (!$sticker->execute()) throw new RuntimeException('Could not settle sticker order.');
}

function confirmCashBillPayment(mysqli $db, int $paymentId): bool {
    if (!canManageBilling()) return false;
    ensurePaymongoColumns($db); ensureViolationsTable($db); ensureParkingStickerOrdersTable($db); ensureAuditLogTable($db); ensureNotificationOutboxTable($db);
    $db->begin_transaction();
    try {
        $find = $db->prepare("SELECT id FROM payments WHERE id=? AND payment_method='cash' AND status IN ('pending','overdue') AND paymongo_checkout_id IS NULL AND amount>0 FOR UPDATE");
        $find->bind_param('i',$paymentId); $find->execute();
        if (!$find->get_result()->fetch_assoc()) { $db->rollback(); return false; }
        $update = $db->prepare("UPDATE payments SET status='paid',paid_at=NOW(),payment_channel='cash' WHERE id=? AND status IN ('pending','overdue')");
        $update->bind_param('i',$paymentId);
        if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('Cash bill changed.');
        settleLinkedBillRecords($db,$paymentId);
        if (!logAudit('cash_confirm','payment',$paymentId,'Cash received and confirmed by billing staff.',$db)) throw new RuntimeException('Could not audit cash receipt.');
        notifyResidentOfPayment($paymentId, $db);
        $db->commit();
        return true;
    } catch (Throwable $error) { $db->rollback(); error_log('Cash confirmation failed: '.$error->getMessage()); return false; }
}

/** A checkout remains bound to its original amount; repeated submissions resume it. */
function startResidentBillPayment(mysqli $db, int $paymentId, int $userId, string $method, array $profile): array {
    if (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0)!==$userId) return ['success'=>false,'error'=>'Only the approved unit owner can pay this bill.'];
    if (!in_array($method,['online','bank','cash'],true)) return ['success'=>false,'error'=>'Please select a payment method.'];
    ensureBillingTables($db); ensurePaymongoColumns($db);
    $db->begin_transaction();
    try {
        $payer=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $payer->bind_param('i',$userId); $payer->execute();
        if (!$payer->get_result()->fetch_assoc()) { $db->rollback(); return ['success'=>false,'error'=>'Only the approved unit owner can pay this bill.']; }
        $find = $db->prepare("SELECT * FROM payments WHERE id=? AND status IN ('pending','overdue') AND amount>0 FOR UPDATE");
        $find->bind_param('i',$paymentId); $find->execute(); $bill=$find->get_result()->fetch_assoc();
        if ($bill && (int)$bill['user_id']!==$userId) {
            $billAccount=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $billAccount->bind_param('i',$bill['user_id']); $billAccount->execute();
            if (!$billAccount->get_result()->fetch_assoc()) $bill=null;
        }
        if (!$bill || !residentCanPayBill($db,$userId,(int)$bill['user_id'],$paymentId)) { $db->rollback(); return ['success'=>false,'error'=>'Only the approved unit owner can pay an open bill for this unit.']; }
        if (!empty($bill['paymongo_checkout_id'])) {
            $db->rollback();
            if ($method==='cash') return ['success'=>false,'error'=>'This bill already has an online checkout. Complete that checkout or ask billing staff to resolve it before paying cash.'];
            return paymongoCheckoutUrlIsSafe($bill['checkout_url'])
                ? ['success'=>true,'checkout_url'=>$bill['checkout_url'],'resumed'=>true]
                : ['success'=>false,'error'=>'The saved checkout link is unavailable. Contact billing staff.'];
        }
        if ($method==='cash') {
            $update=$db->prepare("UPDATE payments SET payment_method='cash',payment_channel='cash' WHERE id=?");
            $update->bind_param('i',$paymentId); $update->execute(); $db->commit();
            return ['success'=>true,'checkout_url'=>null];
        }
        $methods=paymongoPaymentMethodsForSelection($method);
        if (!$methods) { $db->rollback(); return ['success'=>false,'error'=>'This payment option is not configured. Please choose cash or contact billing staff.']; }
        $itemsQuery=$db->prepare('SELECT category,amount FROM bill_items WHERE payment_id=? ORDER BY id');
        $itemsQuery->bind_param('i',$paymentId); $itemsQuery->execute(); $items=$itemsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$items) $items=[['category'=>'Payment','amount'=>$bill['amount']]];
        $total=array_sum(array_map(fn($item)=>(int)round((float)$item['amount']*100),$items));
        if ($total !== (int)round((float)$bill['amount']*100)) { $db->rollback(); return ['success'=>false,'error'=>'The statement total needs review. Contact billing staff.']; }
        $checkout=createPaymongoCheckoutSession($paymentId,$items,'Statement of Account - DUES-'.$paymentId,
            ($profile['full_name'] ?? '') ?: 'Unit Owner',$profile['email'] ?? '',
            buildUrl('resident/payment_return.php?payment_id='.$paymentId.'&status=success'),
            buildUrl('resident/payment_return.php?payment_id='.$paymentId.'&status=cancelled'), [], $methods);
        if (!$checkout['success'] || !paymongoCheckoutUrlIsSafe($checkout['checkout_url'])) {
            $db->rollback(); return ['success'=>false,'error'=>$checkout['error'] ?: 'The payment provider returned an invalid checkout link.'];
        }
        $update=$db->prepare('UPDATE payments SET payment_method=?,paymongo_checkout_id=?,checkout_url=?,gateway_status=NULL,payment_channel=NULL WHERE id=?');
        $update->bind_param('sssi',$method,$checkout['checkout_id'],$checkout['checkout_url'],$paymentId);
        if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('Could not save checkout.');
        $db->commit(); return ['success'=>true,'checkout_url'=>$checkout['checkout_url']];
    } catch (Throwable $error) { $db->rollback(); error_log('Could not start bill payment: '.$error->getMessage()); return ['success'=>false,'error'=>'Payment could not be started. Please try again or contact billing staff.']; }
}
