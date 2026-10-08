<?php
/**
 * Violation management.
 *
 * Monetary citations are added to an unpaid fine-only bill, or a new
 * fine bill. Monthly statements, sticker orders, and submitted checkout
 * amounts retain their original breakdown. Every open bill is payable
 * from the resident's Billing & Payments page.
 *
 * When that bill is later paid (webhook or manual mark-paid),
 * markViolationsPaidForBill() flips any violations tied to it to 'paid'.
 */

function ensureViolationsTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
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
    if (!schemaMutationAllowed()) return true;
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
 * line item to an unsubmitted fine-only bill (creating one if needed) so it
 * shows up on their Statement of Account right away.
 */
function issueViolation(int $userId, string $violationType, string $description, string $penaltyType, float $fineAmount, ?string $dueDate, int $issuedBy, ?string $location = null, ?string $evidencePath = null, ?string $adminRemarks = null): int|false {
    if (!canAccess('violations.issue') || $issuedBy !== (int)$_SESSION['user_id'] || trim($violationType)===''
        || strlen($violationType)>100 || !in_array($penaltyType,['warning','fine'],true)
        || !is_finite($fineAmount) || $fineAmount<0 || $fineAmount>99999999.99
        || ($dueDate!==null && !workflowDate($dueDate))) return false;
    $connection = connectDb();
    $fineRates = getViolationFineRates();
    if (isset($fineRates[$violationType])) {
        $fineAmount = (float)$fineRates[$violationType];
    }
    $isFine = $penaltyType==='fine';
    if (!$isFine) $fineAmount=0;
    if (!ensureViolationsTable($connection)) {
        return false;
    }
    if (($isFine && $fineAmount<=0) || !ensurePaymentsTable($connection) || !ensureBillingTables($connection)) {
        return false;
    }
    ensurePaymongoColumns($connection);
    ensureAuditLogTable($connection);

    $status = $isFine ? 'unpaid' : 'warning_issued';

    $connection->begin_transaction();
    try {
        $owner=$connection->prepare("SELECT id FROM users WHERE id=? AND role='resident' AND is_active=1 AND status='approved' FOR UPDATE");
        $owner->bind_param('i',$userId); $owner->execute();
        if (!$owner->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        $resident=residentContext($connection,$userId);
        if (!$resident || !$resident['approved']) { $connection->rollback(); return false; }
        $billUserId=(int)$resident['billing_user_id'];
        if ($billUserId!==$userId) {
            $owner->bind_param('i',$billUserId); $owner->execute();
            if (!$owner->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        }
        $insert = $connection->prepare('INSERT INTO violations (user_id, violation_type, description, location, evidence_path, admin_remarks, penalty_type, fine_amount, due_date, status, issued_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->bind_param('issssssdssi', $userId, $violationType, $description, $location, $evidencePath, $adminRemarks, $penaltyType, $fineAmount, $dueDate, $status, $issuedBy);
        if (!$insert->execute()) {
            throw new RuntimeException('Could not save the violation record.');
        }
        $violationId = (int)$connection->insert_id;

        if ($isFine) {
            $findBill = $connection->prepare("SELECT id FROM payments p WHERE user_id = ? AND status IN ('pending', 'overdue')
                AND paymongo_checkout_id IS NULL AND billing_period_start IS NULL
                AND NOT EXISTS (SELECT 1 FROM bill_items bi WHERE bi.payment_id=p.id AND bi.category<>'Violation Fine')
                ORDER BY due_date ASC, created_at ASC LIMIT 1 FOR UPDATE");
            $findBill->bind_param('i', $billUserId);
            if (!$findBill->execute()) {
                throw new RuntimeException('Could not find an open bill for the resident.');
            }
            $openBill = $findBill->get_result()->fetch_assoc();
            if ($openBill) {
                $billId = (int)$openBill['id'];
            } else {
                $billDueDate = $dueDate ?: (new DateTime())->modify('+15 days')->format('Y-m-d');
                $createBill = $connection->prepare("INSERT INTO payments (user_id, amount, payment_method, status, due_date, billing_period_start, billing_period_end) VALUES (?, 0, 'unbilled', 'pending', ?, NULL, NULL)");
                $createBill->bind_param('is', $billUserId, $billDueDate);
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

        if (!logAudit('citation_issued','violation',$violationId,$violationType.' for resident #'.$userId,$connection)) throw new RuntimeException('Could not audit citation.');
        $connection->commit();
    } catch (Throwable $error) {
        $connection->rollback();
        error_log('Could not issue violation for user #' . $userId . ': ' . $error->getMessage());
        return false;
    }

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
    $reason=trim($reason);
    if (!isLoggedIn() || ($_SESSION['role'] ?? '')!=='resident' || $userId!==(int)$_SESSION['user_id']
        || $reason==='' || strlen($reason)>5000) return false;
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
    if (!canAccess('violations.review') || !in_array($decision,['waive','reject_dispute'],true)) return false;
    $db=connectDb(); ensureViolationsTable($db); ensureBillingTables($db); ensurePaymongoColumns($db); ensureAuditLogTable($db);
    $find=$db->prepare('SELECT payment_id FROM violations WHERE id=?');
    $find->bind_param('i',$violationId); $find->execute(); $reference=$find->get_result()->fetch_assoc();
    if (!$reference) return false;
    $db->begin_transaction();
    try {
        $bill=null;
        if (!empty($reference['payment_id'])) {
            // The bill is always locked before its linked violation, including payment confirmation.
            $payment=$db->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');
            $payment->bind_param('i',$reference['payment_id']); $payment->execute(); $bill=$payment->get_result()->fetch_assoc();
        }
        $lock=$db->prepare('SELECT * FROM violations WHERE id=? FOR UPDATE');
        $lock->bind_param('i',$violationId); $lock->execute(); $violation=$lock->get_result()->fetch_assoc();
        if (!$violation || !in_array($violation['status'],['unpaid','disputed'],true)
            || ($bill && !in_array($bill['status'],['pending','overdue'],true))) { $db->rollback(); return false; }
        if ($decision==='reject_dispute') {
            if ($violation['status']!=='disputed') { $db->rollback(); return false; }
            $update=$db->prepare("UPDATE violations SET status='unpaid' WHERE id=? AND status='disputed'");
            $update->bind_param('i',$violationId); $update->execute();
        } else {
            // A submitted checkout has an immutable amount. Expire/reconcile it before a waiver.
            if (!$bill || !empty($bill['paymongo_checkout_id']) || empty($violation['bill_item_id'])
                || (int)$violation['payment_id']!==(int)$bill['id']) { $db->rollback(); return false; }
            $delete=$db->prepare('DELETE FROM bill_items WHERE id=? AND payment_id=?');
            $delete->bind_param('ii',$violation['bill_item_id'],$bill['id']);
            if (!$delete->execute() || $delete->affected_rows!==1) throw new RuntimeException('Fine item could not be removed.');
            recalculateBillTotal($db,(int)$bill['id']);
            $close=$db->prepare("UPDATE payments SET status='rejected',gateway_status='waived' WHERE id=? AND amount=0 AND status IN ('pending','overdue')");
            $close->bind_param('i',$bill['id']); $close->execute();
            $update=$db->prepare("UPDATE violations SET status='waived' WHERE id=?");
            $update->bind_param('i',$violationId); $update->execute();
        }
        if (!logAudit($decision,'violation',$violationId,'Violation decision recorded by management.',$db)) throw new RuntimeException('Could not audit violation decision.');
        $db->commit();
        return true;
    } catch (Throwable $error) { $db->rollback(); error_log('Violation resolution failed: '.$error->getMessage()); return false; }
}

/** Called whenever a bill is marked paid (webhook or manual) so its violations settle too. */
function markViolationsPaidForBill(int $paymentId): void {
    $connection = connectDb();
    ensureViolationsTable($connection);
    $stmt=$connection->prepare("UPDATE violations v JOIN payments p ON p.id=v.payment_id SET v.status='paid' WHERE p.id=? AND p.status='paid' AND v.status IN ('unpaid','disputed')");
    $stmt->bind_param('i',$paymentId); $stmt->execute();
}
