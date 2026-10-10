<?php
/**
 * Billing & Payments — itemized statements.
 *
 * A "bill" is still just a row in `payments` (so PayMongo checkout,
 * the webhook, audit logging, and due-date reminders all keep working
 * exactly as before — they only ever cared about payments.amount and
 * payments.status). What's new is `bill_items`: each bill can now carry
 * multiple line items (Condo Dues, Water, Electricity, Parking, a
 * violation fine, etc.), and payments.amount is kept as the running
 * total of its items.
 *
 * Old payment rows created before this feature has no items — callers
 * should treat an empty item list as "one line item equal to the
 * payment's own amount" (see getBillWithItems()) so nothing looks broken
 * for historical data.
 */

const STANDARD_CHARGE_CATEGORIES = ['Condo Dues', 'Water', 'Electricity', 'Parking', 'Rent/Lease', 'Other'];

function ensureBillingTables(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    $itemsOk = $connection->query("CREATE TABLE IF NOT EXISTS bill_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL,
        category VARCHAR(50) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (payment_id),
        CONSTRAINT fk_bill_items_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
    )") === true;

    $checks = [
        'billing_scope' => "ALTER TABLE payments ADD COLUMN billing_scope VARCHAR(30) NOT NULL DEFAULT 'unit'",
        'billing_period_start' => "ALTER TABLE payments ADD COLUMN billing_period_start DATE DEFAULT NULL",
        'billing_period_end'   => "ALTER TABLE payments ADD COLUMN billing_period_end DATE DEFAULT NULL",
    ];
    foreach ($checks as $column => $alterSql) {
        $columnCheck = $connection->query("SHOW COLUMNS FROM payments LIKE '{$column}'");
        if (!$columnCheck || $columnCheck->num_rows === 0) {
            $connection->query($alterSql);
        }
    }

    $statusColumn = $connection->query("SHOW COLUMNS FROM payments LIKE 'status'");
    $statusDefinition = $statusColumn ? $statusColumn->fetch_assoc() : null;
    if ($statusDefinition && !str_contains((string)$statusDefinition['Type'], "'rolled_forward'")) {
        if (!$connection->query("ALTER TABLE payments MODIFY status ENUM('pending', 'paid', 'overdue', 'rejected', 'rolled_forward') NOT NULL DEFAULT 'pending'")) {
            return false;
        }
    }

    return $itemsOk;
}

function recalculateBillTotal(mysqli $connection, int $paymentId): void {
    $result = $connection->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM bill_items WHERE payment_id = ?');
    $result->bind_param('i', $paymentId);
    $result->execute();
    $total = (float)$result->get_result()->fetch_assoc()['total'];

    $update = $connection->prepare("UPDATE payments SET amount = ? WHERE id = ? AND status IN ('pending','overdue') AND paymongo_checkout_id IS NULL AND billing_scope<>'amenity_reservation'");
    $update->bind_param('di', $total, $paymentId);
    $update->execute();
}

function getBillItems(mysqli $connection, int $paymentId): array {
    ensureBillingTables($connection);
    $stmt = $connection->prepare('SELECT * FROM bill_items WHERE payment_id = ? ORDER BY id ASC');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function addBillItem(int $paymentId, string $category, string $description, float $amount, ?int &$itemId = null): bool {
    if (!canManageBilling() || !is_finite($amount) || $amount<=0 || $amount>99999999.99
        || trim($category)==='' || strlen($category)>50 || strlen($description)>255) return false;
    $connection = connectDb();
    ensureBillingTables($connection);
    ensurePaymongoColumns($connection);
    $connection->begin_transaction();
    try {
        $bill=$connection->prepare("SELECT id,billing_scope FROM payments WHERE id=? AND status IN ('pending','overdue') AND paymongo_checkout_id IS NULL FOR UPDATE");
        $bill->bind_param('i',$paymentId); $bill->execute();
        $billRow=$bill->get_result()->fetch_assoc();
        if (!$billRow || $billRow['billing_scope']==='amenity_reservation' || ($billRow['billing_scope']==='personal_parking' && !in_array(trim($category),['Parking','Parking Sticker'],true))) { $connection->rollback(); return false; }
        $stmt=$connection->prepare('INSERT INTO bill_items (payment_id,category,description,amount) VALUES (?,?,?,?)');
        $amount=round($amount,2); $stmt->bind_param('issd',$paymentId,$category,$description,$amount);
        if (!$stmt->execute()) throw new RuntimeException('Bill item insert failed.');
        $itemId=(int)$connection->insert_id;
        recalculateBillTotal($connection,$paymentId); $connection->commit(); return true;
    } catch (Throwable $error) {
        $connection->rollback(); error_log('Could not append bill item: '.$error->getMessage()); return false;
    }
}

/**
 * Creates a new bill (a payments row with status 'pending') plus its
 * line items in one go. $items is a list of ['category', 'description', 'amount'].
 * Returns the new bill's id, or false on failure.
 */
function createBill(int $userId, array $items, ?string $periodStart, ?string $periodEnd, string $dueDate, string $paymentMethod = 'unbilled'): int|false {
    if (!canManageBilling() || !workflowDate($dueDate)
        || ($periodStart !== null && !workflowDate($periodStart))
        || ($periodEnd !== null && (!workflowDate($periodEnd) || $periodStart === null || $periodEnd < $periodStart))
        || !in_array($paymentMethod,['unbilled','cash','manual'],true)) return false;
    $connection=connectDb(); ensurePaymentsTable($connection); ensureBillingTables($connection); ensureAuditLogTable($connection);
    $charges=[]; $totalCents=0;
    foreach ($items as $item) {
        $amount=(float)($item['amount'] ?? 0);
        $category=trim((string)($item['category'] ?? 'Other'));
        $description=trim((string)($item['description'] ?? ''));
        if (!is_finite($amount) || $amount < 0 || $amount > 99999999.99 || strlen($category)>50 || $category==='' || strlen($description)>255) return false;
        $cents=(int)round($amount*100);
        if ($cents===0) continue;
        $totalCents+=$cents;
        $charges[]=['category'=>$category,'description'=>$description,'amount'=>$cents/100];
    }
    if (!$charges || $totalCents>9999999999) return false;
    $connection->begin_transaction();
    try {
        $requested=$connection->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $requested->bind_param('i',$userId); $requested->execute();
        if (!$requested->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        // Unit charges belong to the owner; tenant-only parking stays personal. Historical
        // invoices retain their original account and amount.
        $context=residentContext($connection,$userId);
        if (!$context || !$context['approved'] || empty($context['billing_user_id'])) { $connection->rollback(); return false; }
        $tenantParking=$context['account_kind']==='tenant' && !array_filter($charges,static fn(array $charge):bool=>!in_array($charge['category'],['Parking','Parking Sticker'],true));
        $billingScope=$tenantParking ? 'personal_parking' : 'unit';
        $userId=$tenantParking ? (int)$context['id'] : (int)$context['billing_user_id'];
        $resident=$connection->prepare("SELECT id FROM users WHERE id=? AND role='resident' AND is_verified=1 AND is_active=1 AND status='approved' FOR UPDATE");
        $resident->bind_param('i',$userId); $resident->execute();
        if (!$resident->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        $owner=residentContext($connection,$userId);
        if (!$owner || !$owner['approved'] || (!$tenantParking && $owner['account_kind']!=='owner')) { $connection->rollback(); return false; }
        if ($periodStart!==null) {
            $existing=$connection->prepare("SELECT id FROM payments WHERE user_id=? AND billing_period_start=? AND status<>'rolled_forward' LIMIT 1 FOR UPDATE");
            $existing->bind_param('is',$userId,$periodStart); $existing->execute();
            if ($existing->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        }
        $total=$totalCents/100;
        $insert=$connection->prepare("INSERT INTO payments (user_id,amount,payment_method,status,due_date,billing_period_start,billing_period_end,billing_scope) VALUES (?,?,?,'pending',?,?,?,?)");
        $insert->bind_param('idsssss',$userId,$total,$paymentMethod,$dueDate,$periodStart,$periodEnd,$billingScope);
        if (!$insert->execute()) throw new RuntimeException('Could not create bill.');
        $paymentId=(int)$connection->insert_id;
        foreach ($charges as $charge) {
            $line=$connection->prepare('INSERT INTO bill_items (payment_id,category,description,amount) VALUES (?,?,?,?)');
            $line->bind_param('issd',$paymentId,$charge['category'],$charge['description'],$charge['amount']);
            if (!$line->execute()) throw new RuntimeException('Could not create bill item.');
        }
        if (!logAudit('bill_created','payment',$paymentId,'Itemized bill created for resident #'.$userId,$connection)) throw new RuntimeException('Could not audit bill creation.');
        $connection->commit();
        trackEvent('bill_created',"Bill #{$paymentId} for user #{$userId}",$userId);
        return $paymentId;
    } catch (Throwable $error) {
        $connection->rollback(); error_log('Bill creation failed: '.$error->getMessage()); return false;
    }
}

/** The resident's most recent unpaid/overdue bill, or null if they're all settled. */
function getOpenBillForUser(mysqli $connection, int $userId): ?array {
    ensureBillingTables($connection);
    $stmt = $connection->prepare("SELECT * FROM payments WHERE user_id = ? AND status IN ('pending', 'overdue') ORDER BY due_date ASC, created_at ASC LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/** Called only inside the locked approval transaction; reuse payments and bill_items. */
function createAmenityReservationBill(mysqli $db,array $booking,int $payerId): int {
    if(!canAccess('bookings.review') || $booking['amenity']!=='Swimming Pool' || $booking['status']!=='pending' || $booking['payment_id']!==null) throw new InvalidArgumentException('A pending pool reservation is required for automatic billing.');
    $amount=(string)$booking['total_fee'];
    $due=$booking['booking_date'];
    $insert=$db->prepare("INSERT INTO payments (user_id,amount,payment_method,status,due_date,billing_scope) VALUES (?,?,'unbilled','pending',?,'amenity_reservation')");
    $insert->bind_param('iss',$payerId,$amount,$due); $insert->execute(); $paymentId=(int)$db->insert_id;
    $description='Swimming Pool · Booking #'.$booking['id'].' · Unit '.$booking['unit_number'].' · '.$booking['booking_date'].' '.substr($booking['booking_time'],0,5).'–'.substr($booking['end_time'],0,5).' · '.$booking['duration_hours'].' hour(s) × PHP '.$booking['hourly_rate'];
    $line=$db->prepare("INSERT INTO bill_items (payment_id,category,description,amount) VALUES (?,'Amenity Reservation',?,?)");
    $line->bind_param('iss',$paymentId,$description,$amount); $line->execute();
    if(!logAudit('bill_created','payment',$paymentId,'Automatic pool charge for booking #'.$booking['id'].'; unit payer #'.$payerId,$db)) throw new RuntimeException('Could not audit reservation billing.');
    return $paymentId;
}

/**
 * All of a resident's unpaid/overdue bills. Normally there's at most
 * one — but a violation fine will open a fresh bill if the resident has
 * none, so it's possible (if rare) for more than one to be outstanding
 * at once. The resident page lists all of them rather than assuming a
 * single bill, so nothing is ever silently unpayable.
 */
function getOpenBillsForUser(int $userId): array {
    $connection = connectDb();
    ensureBillingTables($connection);
    $stmt = $connection->prepare("SELECT * FROM payments WHERE user_id = ? AND status IN ('pending', 'overdue') ORDER BY due_date ASC, created_at ASC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($bills as &$bill) {
        $bill['items'] = getBillItems($connection, (int)$bill['id']);
        if (empty($bill['items'])) {
            $bill['items'] = [['id' => 0, 'payment_id' => $bill['id'], 'category' => 'Payment', 'description' => null, 'amount' => $bill['amount']]];
        }
    }
    return $bills;
}

/** A bill with its items attached; falls back to one synthetic line item for pre-billing-feature rows that have none. */
function getBillWithItems(mysqli $connection, int $paymentId): ?array {
    $stmt = $connection->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    if (!$bill) {
        return null;
    }
    $items = getBillItems($connection, $paymentId);
    if (empty($items)) {
        $items = [['id' => 0, 'payment_id' => $paymentId, 'category' => 'Payment', 'description' => null, 'amount' => $bill['amount']]];
    }
    $bill['items'] = $items;
    return $bill;
}

function getCurrentBillWithItemsForUser(int $userId): ?array {
    $connection = connectDb();
    $open = getOpenBillForUser($connection, $userId);
    return $open ? getBillWithItems($connection, (int)$open['id']) : null;
}

/** Past (paid) bills for a resident, most recent first, each with its items. */
function getBillHistoryForUser(int $userId, int $limit = 12): array {
    $connection = connectDb();
    ensureBillingTables($connection);
    $stmt = $connection->prepare("SELECT * FROM payments WHERE user_id = ? AND status = 'paid' ORDER BY paid_at DESC LIMIT ?");
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($bills as &$bill) {
        $bill['items'] = getBillItems($connection, (int)$bill['id']);
        if (empty($bill['items'])) {
            $bill['items'] = [['id' => 0, 'payment_id' => $bill['id'], 'category' => 'Payment', 'description' => null, 'amount' => $bill['amount']]];
        }
    }
    return $bills;
}

/** Statements visible to this resident, with gateway details restricted to the bill's payer. */
function getResidentVisibleBills(mysqli $connection, int $actorId, bool $paid = false, int $limit = 12): array {
    ensureBillingTables($connection);
    $context=residentContext($connection,$actorId);
    $ids=array_values(array_unique(array_map('intval',residentBillingUserIds($connection,$actorId))));
    if (!$context || !$context['approved'] || !$ids) return [];
    $columns='*';
    $status=$paid ? "status='paid'" : "status IN ('pending','overdue')";
    $order=$paid ? 'paid_at DESC,id DESC' : 'due_date ASC,created_at ASC,id ASC';
    $query='SELECT '.$columns.' FROM payments WHERE user_id IN ('.implode(',',$ids).') AND '.$status.' ORDER BY '.$order;
    if ($paid) $query.=' LIMIT '.max(1,min(100,$limit));
    $bills=$connection->query($query)->fetch_all(MYSQLI_ASSOC);
    foreach ($bills as &$bill) {
        if (!residentCanPayBill($connection,$actorId,(int)$bill['user_id'],(int)$bill['id'])) {
            $bill=array_intersect_key($bill,array_flip(['id','user_id','amount','status','due_date','billing_period_start','billing_period_end','paid_at','created_at','billing_scope']));
        }
        $bill['items']=getBillItems($connection,(int)$bill['id']);
        if (!$bill['items']) $bill['items']=[['category'=>'Payment','description'=>null,'amount'=>$bill['amount']]];
    }
    return $bills;
}

function getResidentBillingSummary(mysqli $connection, int $actorId): array {
    $ids=array_values(array_unique(array_map('intval',residentBillingUserIds($connection,$actorId))));
    if (!$ids) return ['count'=>0,'amount'=>0.0];
    ensurePaymentsTable($connection);
    $row=$connection->query("SELECT COUNT(*) AS count,COALESCE(SUM(amount),0) AS amount FROM payments WHERE user_id IN (".implode(',',$ids).") AND status IN ('pending','overdue')")->fetch_assoc();
    return ['count'=>(int)$row['count'],'amount'=>(float)$row['amount']];
}

/** One statement per approved unit owner per billing period, including paid-history deduplication. */
function generateStandardMonthlyBills(array $chargeTemplate, string $periodStart, string $periodEnd, string $dueDate): array {
    if (!canManageBilling() || !workflowDate($periodStart) || !workflowDate($periodEnd)
        || $periodEnd<$periodStart || !workflowDate($dueDate)) {
        return ['created'=>0,'skipped'=>0,'bill_ids'=>[],'error'=>'Billing permission and valid billing dates are required.'];
    }
    $connection=connectDb(); ensureBillingTables($connection);
    $items=[];
    foreach ($chargeTemplate as $category=>$amount) {
        if (!in_array($category,STANDARD_CHARGE_CATEGORIES,true) || !is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<0) {
            return ['created'=>0,'skipped'=>0,'bill_ids'=>[],'error'=>'Invalid billing charge.'];
        }
        if ((float)$amount>0) $items[]=['category'=>$category,'description'=>'','amount'=>(float)$amount];
    }
    if (!$items) return ['created'=>0,'skipped'=>0,'bill_ids'=>[],'error'=>'Enter at least one charge greater than zero.'];
    $residents=$connection->query("SELECT id FROM users WHERE role='resident' AND is_verified=1 AND is_active=1 AND status='approved'")->fetch_all(MYSQLI_ASSOC);
    $created=0; $skipped=0; $ids=[];
    foreach ($residents as $resident) {
        $context=residentContext($connection,(int)$resident['id']);
        if (!$context || !$context['approved'] || $context['account_kind']!=='owner') continue;
        // Locking the resident in createBill serializes competing generation requests.
        // Paid history also counts: rerunning a month must never bill residents twice.
        $id=createBill((int)$resident['id'],$items,$periodStart,$periodEnd,$dueDate);
        if ($id===false) $skipped++; else { $created++; $ids[]=$id; }
    }
    logAudit('generate_bills','payment',null,"Generated {$created} bill(s) for {$periodStart} to {$periodEnd}; skipped {$skipped}.");
    return ['created'=>$created,'skipped'=>$skipped,'bill_ids'=>$ids,'error'=>null];
}
