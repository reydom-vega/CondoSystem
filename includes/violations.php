<?php
/**
 * Violation management.
 *
 * A violation with a monetary fine automatically creates a line item on
 * the resident's current bill (creating one if they don't already have
 * an open bill) via includes/billing.php, so the resident pays it
 * together with whatever else they owe — exactly one PayMongo checkout,
 * one Statement of Account, no separate "fines" payment flow to build.
 *
 * When that bill is later paid (webhook or manual mark-paid),
 * markViolationsPaidForBill() flips any violations tied to it to 'paid'.
 */

function ensureViolationsTable(mysqli $connection): bool {
    return $connection->query("CREATE TABLE IF NOT EXISTS violations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        violation_type VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        evidence_path VARCHAR(255) DEFAULT NULL,
        admin_remarks TEXT DEFAULT NULL,
        penalty_type ENUM('warning', 'fine') NOT NULL DEFAULT 'fine',
        fine_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
        due_date DATE DEFAULT NULL,
        status ENUM('warning_issued', 'unpaid', 'paid', 'disputed', 'waived') NOT NULL DEFAULT 'warning_issued',
        dispute_reason TEXT DEFAULT NULL,
        bill_item_id INT DEFAULT NULL,
        payment_id INT DEFAULT NULL,
        issued_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status),
        CONSTRAINT fk_violations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )") === true && ensureViolationColumns($connection);
}

function getViolationFineRates(): array {
    return [
        'Unauthorized Parking' => 1000.00,
        'Noise Complaint' => 500.00,
        'Pet Violation' => 500.00,
        'Unauthorized Renovation' => 5000.00,
        'Improper Waste Disposal' => 500.00,
        'Littering' => 300.00,
        'Smoking in Common Area' => 1000.00,
    ];
}

function ensureViolationColumns(mysqli $connection): bool {
    $columns = [
        'location' => 'VARCHAR(255) DEFAULT NULL',
        'evidence_path' => 'VARCHAR(255) DEFAULT NULL',
        'admin_remarks' => 'TEXT DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        $check = $connection->query("SHOW COLUMNS FROM violations LIKE '{$column}'");
        if (!$check || $check->num_rows === 0) {
            if (!$connection->query("ALTER TABLE violations ADD COLUMN {$column} {$definition}")) {
                return false;
            }
        }
    }

    $penaltyColumn = $connection->query("SHOW COLUMNS FROM violations LIKE 'penalty_type'");
    $penaltyDefinition = $penaltyColumn ? $penaltyColumn->fetch_assoc() : null;
    if ($penaltyDefinition && $penaltyDefinition['Default'] !== 'fine') {
        if (!$connection->query("ALTER TABLE violations MODIFY penalty_type ENUM('warning', 'fine') NOT NULL DEFAULT 'fine'")) {
            return false;
        }
    }

    return true;
}

/**
 * Issues a violation. A 'warning' just gets logged. A 'fine' also adds a
 * line item to the resident's open bill (creating one if needed) so it
 * shows up on their Statement of Account right away.
 */
function issueViolation(int $userId, string $violationType, string $description, string $penaltyType, float $fineAmount, ?string $dueDate, int $issuedBy, ?string $location = null, ?string $evidencePath = null, ?string $adminRemarks = null): int|false {
    $connection = connectDb();
    $fineRates = getViolationFineRates();
    if (isset($fineRates[$violationType])) {
        $fineAmount = (float)$fineRates[$violationType];
    }
    $penaltyType = 'fine';
    $isFine = $fineAmount > 0;
    if (!ensureViolationsTable($connection)) {
        return false;
    }
    if (!$isFine || !ensurePaymentsTable($connection) || !ensureBillingTables($connection)) {
        return false;
    }

    $status = $isFine ? 'unpaid' : 'warning_issued';

    $connection->begin_transaction();
    try {
        $insert = $connection->prepare('INSERT INTO violations (user_id, violation_type, description, location, evidence_path, admin_remarks, penalty_type, fine_amount, due_date, status, issued_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->bind_param('issssssdssi', $userId, $violationType, $description, $location, $evidencePath, $adminRemarks, $penaltyType, $fineAmount, $dueDate, $status, $issuedBy);
        if (!$insert->execute()) {
            throw new RuntimeException('Could not save the violation record.');
        }
        $violationId = (int)$connection->insert_id;

        if ($isFine) {
            $findBill = $connection->prepare("SELECT id FROM payments WHERE user_id = ? AND status IN ('pending', 'overdue') ORDER BY due_date ASC, created_at ASC LIMIT 1 FOR UPDATE");
            $findBill->bind_param('i', $userId);
            if (!$findBill->execute()) {
                throw new RuntimeException('Could not find an open bill for the resident.');
            }
            $openBill = $findBill->get_result()->fetch_assoc();
            if ($openBill) {
                $billId = (int)$openBill['id'];
            } else {
                $billDueDate = $dueDate ?: (new DateTime())->modify('+15 days')->format('Y-m-d');
                $createBill = $connection->prepare("INSERT INTO payments (user_id, amount, payment_method, status, due_date, billing_period_start, billing_period_end) VALUES (?, 0, 'unbilled', 'pending', ?, NULL, NULL)");
                $createBill->bind_param('is', $userId, $billDueDate);
                if (!$createBill->execute()) {
                    throw new RuntimeException('Could not create the resident bill for this fine.');
                }
                $billId = (int)$connection->insert_id;
            }

            $insertItem = $connection->prepare("INSERT INTO bill_items (payment_id, category, description, amount) VALUES (?, 'Violation Fine', ?, ?)");
            $insertItem->bind_param('isd', $billId, $violationType, $fineAmount);
            if (!$insertItem->execute()) {
                throw new RuntimeException('Could not add the fine to the resident bill.');
            }
            $billItemId = (int)$connection->insert_id;
            recalculateBillTotal($connection, $billId);

            $link = $connection->prepare('UPDATE violations SET bill_item_id = ?, payment_id = ? WHERE id = ?');
            $link->bind_param('iii', $billItemId, $billId, $violationId);
            if (!$link->execute()) {
                throw new RuntimeException('Could not link the violation to its bill.');
            }
        }

        $connection->commit();
    } catch (Throwable $error) {
        $connection->rollback();
        error_log('Could not issue violation for user #' . $userId . ': ' . $error->getMessage());
        return false;
    }

    logAudit('create', 'violation', $violationId, "{$violationType} — " . ($isFine ? '₱' . number_format($fineAmount, 2) . ' fine' : 'warning') . " for user #{$userId}");

    return $violationId;
}

function getViolationsForUser(int $userId): array {
    $connection = connectDb();
    ensureViolationsTable($connection);
    $stmt = $connection->prepare('SELECT v.*, issuer.full_name AS issued_by_name FROM violations v LEFT JOIN users issuer ON issuer.id = v.issued_by WHERE v.user_id = ? ORDER BY v.created_at DESC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getAllViolations(mysqli $connection): array {
    ensureViolationsTable($connection);
    $result = $connection->query('SELECT v.*, u.full_name, u.unit_number FROM violations v INNER JOIN users u ON u.id = v.user_id ORDER BY FIELD(v.status, "disputed", "unpaid", "warning_issued", "paid", "waived"), v.created_at DESC');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** Resident-initiated: flags a fine for admin review instead of paying it outright. */
function disputeViolation(int $violationId, int $userId, string $reason): bool {
    $connection = connectDb();
    $stmt = $connection->prepare("UPDATE violations SET status = 'disputed', dispute_reason = ? WHERE id = ? AND user_id = ? AND status = 'unpaid'");
    $stmt->bind_param('sii', $reason, $violationId, $userId);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        logAudit('dispute', 'violation', $violationId, 'Resident disputed: ' . $reason);
        return true;
    }
    return false;
}

/**
 * Admin resolves a dispute (or waives a fine directly, dispute or not).
 * Waiving removes the associated charge from the resident's bill and
 * recalculates its total; rejecting a dispute leaves the charge in place.
 */
function resolveViolation(int $violationId, string $decision): bool {
    $connection = connectDb();
    $stmt = $connection->prepare('SELECT * FROM violations WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $violationId);
    $stmt->execute();
    $violation = $stmt->get_result()->fetch_assoc();
    if (!$violation) {
        return false;
    }

    if ($decision === 'waive') {
        if ($violation['bill_item_id']) {
            $connection->query('DELETE FROM bill_items WHERE id = ' . (int)$violation['bill_item_id']);
            if ($violation['payment_id']) {
                recalculateBillTotal($connection, (int)$violation['payment_id']);
            }
        }
        $update = $connection->prepare("UPDATE violations SET status = 'waived' WHERE id = ?");
        $update->bind_param('i', $violationId);
        $update->execute();
        logAudit('waive', 'violation', $violationId, 'Fine waived by admin');
        return true;
    }

    if ($decision === 'reject_dispute') {
        $update = $connection->prepare("UPDATE violations SET status = 'unpaid' WHERE id = ?");
        $update->bind_param('i', $violationId);
        $update->execute();
        logAudit('reject_dispute', 'violation', $violationId, 'Dispute rejected — fine stands');
        return true;
    }

    return false;
}

/** Called whenever a bill is marked paid (webhook or manual) so its violations settle too. */
function markViolationsPaidForBill(int $paymentId): void {
    $connection = connectDb();
    ensureViolationsTable($connection);
    $connection->query("UPDATE violations SET status = 'paid' WHERE payment_id = " . (int)$paymentId . " AND status IN ('unpaid', 'disputed')");
}
