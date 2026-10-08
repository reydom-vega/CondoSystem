<?php
function parkingPolicyDefaults(): array {
    return ['sticker_price' => PARKING_STICKER_PRICE, 'sticker_max_quantity' => 10, 'visitor_max_days' => 7];
}
function ensureParkingPolicyTable(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    return $db->query("CREATE TABLE IF NOT EXISTS parking_policy (id TINYINT PRIMARY KEY, sticker_price DECIMAL(10,2) NOT NULL, sticker_max_quantity INT NOT NULL, visitor_max_days INT NOT NULL) ENGINE=InnoDB") === true;
}
function getParkingPolicy(?mysqli $db = null): array {
    $db ??= connectDb();
    if (!ensureParkingPolicyTable($db)) throw new RuntimeException('Parking policy storage is unavailable.');
    $row = $db->query('SELECT sticker_price, sticker_max_quantity, visitor_max_days FROM parking_policy WHERE id = 1')->fetch_assoc();
    return $row ?: parkingPolicyDefaults();
}
function saveParkingPolicy(mysqli $db, array $input): bool {
    if (!isSuperAdmin()) return false;
    $price = filter_var($input['sticker_price'] ?? '', FILTER_VALIDATE_FLOAT);
    $quantity = filter_var($input['sticker_max_quantity'] ?? '', FILTER_VALIDATE_INT);
    $days = filter_var($input['visitor_max_days'] ?? '', FILTER_VALIDATE_INT);
    if ($price === false || $price < 1 || $price > 100000 || $quantity === false || $quantity < 1 || $quantity > 100 || $days === false || $days < 1 || $days > 30) return false;
    ensureParkingPolicyTable($db);
    $stmt = $db->prepare('INSERT INTO parking_policy (id, sticker_price, sticker_max_quantity, visitor_max_days) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE sticker_price = VALUES(sticker_price), sticker_max_quantity = VALUES(sticker_max_quantity), visitor_max_days = VALUES(visitor_max_days)');
    $stmt->bind_param('dii', $price, $quantity, $days);
    return $stmt->execute();
}

/** Create the bill, bill item, and sticker claim using the same connection. */
function purchaseParkingStickers(mysqli $db, int $userId, int $quantity, array $vehicleIds = []): int|false {
    if (!residentUserHasPermission($db,$userId,'resident.stickers.order')) return false;
    $policy = getParkingPolicy($db);
    if ($quantity < 1 || $quantity > (int)$policy['sticker_max_quantity'] || count(array_unique(array_map('intval',$vehicleIds))) !== $quantity) return false;
    if (!ensurePaymentsTable($db) || !ensureBillingTables($db) || !ensureParkingStickerOrdersTable($db)) return false;
    if (!ensureStickerVehicleLinks($db)) return false;
    $db->begin_transaction();
    try {
        // Serialize orders by resident to prevent duplicate unpaid orders.
        $owner = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'resident' AND status = 'approved' AND is_active = 1 AND is_verified = 1 AND unit_number IS NOT NULL AND unit_number <> '' FOR UPDATE");
        $owner->bind_param('i', $userId); $owner->execute();
        if (!$owner->get_result()->fetch_assoc() || !residentUserHasPermission($db,$userId,'resident.stickers.order')) throw new RuntimeException('An approved resident with parking access is required.');
        $existing = $db->prepare("SELECT o.id FROM parking_sticker_orders o LEFT JOIN payments p ON p.id = o.bill_payment_id WHERE o.user_id = ? AND o.claim_status <> 'issued' AND o.status <> 'cancelled' AND (p.status IS NULL OR p.status <> 'cancelled') LIMIT 1");
        $existing->bind_param('i', $userId); $existing->execute();
        if ($existing->get_result()->fetch_assoc()) throw new RuntimeException('A sticker order is already in progress.');
        $amount = round((float)$policy['sticker_price'] * $quantity, 2);
        $due = (new DateTimeImmutable())->modify('+15 days')->format('Y-m-d');
        $billingScope=residentContext($db,$userId)['account_kind']==='tenant' ? 'personal_parking' : 'unit';
        $bill = $db->prepare("INSERT INTO payments (user_id, amount, payment_method, status, due_date, billing_scope) VALUES (?, ?, 'unbilled', 'pending', ?, ?)");
        $bill->bind_param('idss', $userId, $amount, $due, $billingScope);
        if (!$bill->execute()) throw new RuntimeException('Could not create sticker bill.');
        $billId = (int)$db->insert_id;
        $description = $quantity . ' resident parking sticker(s)';
        $item = $db->prepare("INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, 'Parking Sticker', ?, ?)");
        $item->bind_param('isd', $billId, $description, $amount);
        if (!$item->execute()) throw new RuntimeException('Could not create sticker bill item.');
        $order = $db->prepare('INSERT INTO parking_sticker_orders (user_id, quantity, amount, bill_payment_id) VALUES (?, ?, ?, ?)');
        $order->bind_param('iidi', $userId, $quantity, $amount, $billId);
        if (!$order->execute()) throw new RuntimeException('Could not create sticker claim.');
        $orderId = (int)$db->insert_id;
        if (!linkStickerVehicles($db,$orderId,$userId,$quantity,$vehicleIds)) throw new RuntimeException('Choose approved vehicles without an existing sticker order.');
        $db->commit();
        logAudit('create', 'parking_sticker', $orderId, 'Bill #' . $billId . ', quantity ' . $quantity);
        return $billId;
    } catch (Throwable $error) {
        $db->rollback(); error_log($error->getMessage()); return false;
    }
}
