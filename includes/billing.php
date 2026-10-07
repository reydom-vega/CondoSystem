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

    $update = $connection->prepare('UPDATE payments SET amount = ? WHERE id = ?');
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
    $connection = connectDb();
    ensureBillingTables($connection);
    $stmt = $connection->prepare('INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('issd', $paymentId, $category, $description, $amount);
    if (!$stmt->execute()) {
        return false;
    }
    $itemId = (int)$connection->insert_id;
    recalculateBillTotal($connection, $paymentId);
    return true;
}

/**
 * Adds new charges to a pending bill that was opened for an outstanding
 * violation fine. Returns the bill ID, null when there is no eligible bill,
 * or false if a matching bill could not be updated.
 */
function appendChargesToOpenFineBill(mysqli $connection, int $userId, array $items, ?string $periodStart, ?string $periodEnd, string $dueDate): int|false|null {
    ensureBillingTables($connection);
    $connection->begin_transaction();

    try {
        $findBill = $connection->prepare("SELECT p.id
            FROM payments p
            INNER JOIN bill_items bi ON bi.payment_id = p.id AND bi.category = 'Violation Fine'
            INNER JOIN violations v ON v.bill_item_id = bi.id AND v.payment_id = p.id
            WHERE p.user_id = ? AND p.status = 'pending'
                AND v.status IN ('unpaid', 'disputed')
            ORDER BY p.created_at ASC, p.id ASC
            LIMIT 1 FOR UPDATE");
        $findBill->bind_param('i', $userId);
        if (!$findBill->execute()) {
            throw new RuntimeException('Could not locate the resident fine bill.');
        }
        $bill = $findBill->get_result()->fetch_assoc();
        if (!$bill) {
            $connection->rollback();
            return null;
        }

        $paymentId = (int)$bill['id'];
        foreach ($items as $item) {
            $amount = (float)($item['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $category = (string)($item['category'] ?? 'Other');
            $description = (string)($item['description'] ?? '');
            $insertItem = $connection->prepare('INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, ?, ?, ?)');
            $insertItem->bind_param('issd', $paymentId, $category, $description, $amount);
            if (!$insertItem->execute()) {
                throw new RuntimeException('Could not append a charge to the resident fine bill.');
            }
        }

        $totalQuery = $connection->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM bill_items WHERE payment_id = ?');
        $totalQuery->bind_param('i', $paymentId);
        if (!$totalQuery->execute()) {
            throw new RuntimeException('Could not calculate the combined bill total.');
        }
        $total = (float)$totalQuery->get_result()->fetch_assoc()['total'];

        $updateBill = $connection->prepare("UPDATE payments
            SET amount = ?, due_date = ?, billing_period_start = ?, billing_period_end = ?
            WHERE id = ? AND status = 'pending'");
        $updateBill->bind_param('dsssi', $total, $dueDate, $periodStart, $periodEnd, $paymentId);
        if (!$updateBill->execute() || $updateBill->affected_rows !== 1) {
            throw new RuntimeException('The resident fine bill changed before charges could be appended.');
        }

        $connection->commit();
        return $paymentId;
    } catch (Throwable $error) {
        $connection->rollback();
        error_log('Could not append charges to resident fine bill for user #' . $userId . ': ' . $error->getMessage());
        return false;
    }
}

/**
 * Creates a new bill (a payments row with status 'pending') plus its
 * line items in one go. $items is a list of ['category', 'description', 'amount'].
 * Returns the new bill's id, or false on failure.
 */
function createBill(int $userId, array $items, ?string $periodStart, ?string $periodEnd, string $dueDate, string $paymentMethod = 'unbilled'): int|false {
    $connection = connectDb();
    ensureBillingTables($connection);

    $insert = $connection->prepare("INSERT INTO payments (user_id, amount, payment_method, status, due_date, billing_period_start, billing_period_end) VALUES (?, 0, ?, 'pending', ?, ?, ?)");
    $insert->bind_param('issss', $userId, $paymentMethod, $dueDate, $periodStart, $periodEnd);
    if (!$insert->execute()) {
        return false;
    }
    $paymentId = $connection->insert_id;

    foreach ($items as $item) {
        $amount = (float)($item['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $category = $item['category'] ?? 'Other';
        $description = $item['description'] ?? '';
        $itemStmt = $connection->prepare('INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, ?, ?, ?)');
        $itemStmt->bind_param('issd', $paymentId, $category, $description, $amount);
        $itemStmt->execute();
    }

    recalculateBillTotal($connection, $paymentId);
    trackEvent('bill_created', "Bill #{$paymentId} for user #{$userId}", $userId);

    return $paymentId;
}

/** The resident's most recent unpaid/overdue bill, or null if they're all settled. */
function getOpenBillForUser(mysqli $connection, int $userId): ?array {
    ensureBillingTables($connection);
    $stmt = $connection->prepare("SELECT * FROM payments WHERE user_id = ? AND status IN ('pending', 'overdue') ORDER BY due_date ASC, created_at ASC LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
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

/**
 * Finds the resident's current open bill, or creates a fresh empty one
 * (due in 15 days) if they don't have one. Used when a violation fine
 * needs somewhere to land — the resident should be able to pay a new
 * fine together with whatever else they already owe.
 */
function getOrCreateOpenBillForUser(int $userId, ?string $dueDate = null): int {
    $connection = connectDb();
    $existing = getOpenBillForUser($connection, $userId);
    if ($existing) {
        return (int)$existing['id'];
    }
    $dueDate = $dueDate ?: (new DateTime())->modify('+15 days')->format('Y-m-d');
    $newId = createBill($userId, [], null, null, $dueDate);
    return $newId ?: 0;
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

function rollOverdueBillsIntoCurrent(mysqli $connection, array $overdueBills, array $currentItems, string $periodStart, string $periodEnd, string $dueDate): int|false {
    if (empty($overdueBills)) {
        return false;
    }

    $overdueBill = $overdueBills[0];
    $paymentId = (int)$overdueBill['id'];
    ensurePaymongoColumns($connection);
    $connection->begin_transaction();
    try {
        foreach ($overdueBills as $sourceBill) {
            $sourceId = (int)$sourceBill['id'];
            $selectItems = $connection->prepare('SELECT * FROM bill_items WHERE payment_id = ? ORDER BY id ASC');
            $selectItems->bind_param('i', $sourceId);
            if (!$selectItems->execute()) {
                throw new RuntimeException('Could not load an overdue bill breakdown.');
            }
            $overdueItems = $selectItems->get_result()->fetch_all(MYSQLI_ASSOC);
            if (empty($overdueItems)) {
                $overdueItems = [[
                    'category' => 'Payment',
                    'description' => '',
                    'amount' => $sourceBill['amount'],
                ]];
            }

            foreach ($overdueItems as $item) {
                $category = 'Overdue: ' . substr((string)$item['category'], 0, 40);
                $sourceDetails = 'From DUES-' . $sourceId . ', originally due ' . $sourceBill['due_date'];
                $description = trim($sourceDetails . (!empty($item['description']) ? ' - ' . $item['description'] : ''));
                $description = substr($description, 0, 255);
                $itemId = (int)($item['id'] ?? 0);
                if ($sourceId === $paymentId && $itemId > 0) {
                    $updateItem = $connection->prepare('UPDATE bill_items SET category = ?, description = ? WHERE id = ? AND payment_id = ?');
                    $updateItem->bind_param('ssii', $category, $description, $itemId, $sourceId);
                    if (!$updateItem->execute()) {
                        throw new RuntimeException('Could not label the overdue bill items.');
                    }
                } else {
                    $amount = (float)$item['amount'];
                    $insertItem = $connection->prepare('INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, ?, ?, ?)');
                    $insertItem->bind_param('issd', $paymentId, $category, $description, $amount);
                    if (!$insertItem->execute()) {
                        throw new RuntimeException('Could not preserve an overdue bill amount.');
                    }
                }
            }

            if ($sourceId !== $paymentId) {
                $archiveSource = $connection->prepare("UPDATE payments SET amount = 0, status = 'rolled_forward', gateway_status = 'rolled_forward', paymongo_checkout_id = NULL, checkout_url = NULL, paymongo_payment_id = NULL, payment_channel = NULL WHERE id = ? AND status = 'overdue'");
                $archiveSource->bind_param('i', $sourceId);
                if (!$archiveSource->execute() || $archiveSource->affected_rows !== 1) {
                    throw new RuntimeException('An overdue source bill changed before it could be rolled forward.');
                }
            }
        }

        foreach ($currentItems as $item) {
            $category = (string)($item['category'] ?? 'Other');
            $description = (string)($item['description'] ?? '');
            $amount = (float)($item['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $insertItem = $connection->prepare('INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, ?, ?, ?)');
            $insertItem->bind_param('issd', $paymentId, $category, $description, $amount);
            if (!$insertItem->execute()) {
                throw new RuntimeException('Could not add the current billing charges.');
            }
        }

        $recalculate = $connection->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM bill_items WHERE payment_id = ?');
        $recalculate->bind_param('i', $paymentId);
        if (!$recalculate->execute()) {
            throw new RuntimeException('Could not total the rolled-over bill.');
        }
        $total = (float)$recalculate->get_result()->fetch_assoc()['total'];

        $updateBill = $connection->prepare("UPDATE payments SET amount = ?, status = 'pending', due_date = ?, billing_period_start = ?, billing_period_end = ?, payment_method = 'unbilled', paymongo_checkout_id = NULL, checkout_url = NULL, paymongo_payment_id = NULL, gateway_status = 'rolled_forward', payment_channel = NULL WHERE id = ? AND status = 'overdue'");
        $updateBill->bind_param('dsssi', $total, $dueDate, $periodStart, $periodEnd, $paymentId);
        if (!$updateBill->execute() || $updateBill->affected_rows !== 1) {
            throw new RuntimeException('The overdue bill changed before it could be rolled forward.');
        }

        $connection->commit();
        trackEvent('bill_overdue_rolled_forward', count($overdueBills) . " overdue bill(s) rolled into bill #{$paymentId} for period {$periodStart}", (int)$overdueBill['user_id']);
        return $paymentId;
    } catch (Throwable $error) {
        $connection->rollback();
        error_log('Could not roll overdue bill #' . $paymentId . ' forward: ' . $error->getMessage());
        return false;
    }
}

/**
 * Bulk-generates one bill per resident for a billing period, skipping
 * anyone who already has a pending/overdue bill for that exact period
 * (so re-running this is safe). $chargeTemplate is
 * ['Condo Dues' => 2500.00, 'Water' => 450.00, ...] — a category with
 * amount 0 is left out of the generated bills entirely.
 */
function generateStandardMonthlyBills(array $chargeTemplate, string $periodStart, string $periodEnd, string $dueDate): array {
    $connection = connectDb();
    ensureBillingTables($connection);

    $items = [];
    foreach ($chargeTemplate as $category => $amount) {
        if ((float)$amount > 0) {
            $items[] = ['category' => $category, 'description' => '', 'amount' => (float)$amount];
        }
    }
    if (empty($items)) {
        return ['created' => 0, 'skipped' => 0, 'error' => 'No charges with an amount greater than zero were provided.'];
    }

    $residentsResult = $connection->query("SELECT id FROM users WHERE role = 'resident' AND is_verified = 1");
    $residents = $residentsResult ? $residentsResult->fetch_all(MYSQLI_ASSOC) : [];

    $created = 0;
    $skipped = 0;
    $newBillIds = [];

    foreach ($residents as $resident) {
        $userId = (int)$resident['id'];
        $currentPeriodCheck = $connection->prepare("SELECT id FROM payments WHERE user_id = ? AND billing_period_start = ? AND status IN ('pending', 'overdue') LIMIT 1");
        $currentPeriodCheck->bind_param('is', $userId, $periodStart);
        $currentPeriodCheck->execute();
        if ($currentPeriodCheck->get_result()->fetch_assoc()) {
            $skipped++;
            continue;
        }

        $openCheck = $connection->prepare("SELECT id FROM payments WHERE user_id = ? AND status = 'pending' LIMIT 1");
        $openCheck->bind_param('i', $userId);
        $openCheck->execute();
        if ($openCheck->get_result()->fetch_assoc()) {
            $combinedBillId = appendChargesToOpenFineBill($connection, $userId, $items, $periodStart, $periodEnd, $dueDate);
            if ($combinedBillId) {
                $created++;
                $newBillIds[] = $combinedBillId;
            } else {
                $skipped++;
            }
            continue;
        }

        $overdueCheck = $connection->prepare("SELECT * FROM payments WHERE user_id = ? AND status = 'overdue' ORDER BY due_date ASC, id ASC");
        $overdueCheck->bind_param('i', $userId);
        $overdueCheck->execute();
        $overdueBills = $overdueCheck->get_result()->fetch_all(MYSQLI_ASSOC);

        if (!empty($overdueBills)) {
            $rolledBillId = rollOverdueBillsIntoCurrent($connection, $overdueBills, $items, $periodStart, $periodEnd, $dueDate);
            if ($rolledBillId) {
                $created++;
                $newBillIds[] = $rolledBillId;
            } else {
                $skipped++;
            }
            continue;
        }

        $billId = createBill($userId, $items, $periodStart, $periodEnd, $dueDate);
        if ($billId) {
            $created++;
            $newBillIds[] = $billId;
        }
    }

    logAudit('generate_bills', 'payment', null, "Generated {$created} bill(s) for period {$periodStart} to {$periodEnd}; skipped {$skipped} resident(s) because the period was already billed or an open bill could not be rolled forward");

    return ['created' => $created, 'skipped' => $skipped, 'bill_ids' => $newBillIds, 'error' => null];
}
