<?php
const RESIDENT_PERMIT_TYPES = ['Move-in', 'Move-out', 'Renovation', 'Delivery', 'Property Gate Pass'];

function ensureResidentServicesTables(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    if (!$db->query("CREATE TABLE IF NOT EXISTS resident_service_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        request_kind ENUM('visitor','permit') NOT NULL,
        permit_type VARCHAR(30) DEFAULT NULL,
        visitor_name VARCHAR(120) DEFAULT NULL,
        visitor_contact VARCHAR(30) DEFAULT NULL,
        visitor_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        details VARCHAR(500) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('pending','approved','rejected','cancelled','checked_in','checked_out') NOT NULL DEFAULT 'pending',
        access_token CHAR(64) NOT NULL UNIQUE,
        admin_notes VARCHAR(500) DEFAULT NULL,
        decided_by INT DEFAULT NULL,
        decided_at DATETIME DEFAULT NULL,
        visitor_log_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id, request_kind, status),
        INDEX(request_kind, start_date, end_date),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) return false;
    $column = $db->query("SHOW COLUMNS FROM resident_service_requests LIKE 'visitor_count'");
    return $column && ($column->num_rows > 0 || $db->query("ALTER TABLE resident_service_requests ADD visitor_count SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER visitor_contact") === true) && ensurePropertyGateSchema($db);
}

function createResidentServiceRequest(mysqli $db, int $userId, string $kind, array $input): int {
    if ($kind === 'permit' && ($input['permit_type'] ?? '') === 'Property Gate Pass') {
        if ($userId !== (int)($_SESSION['user_id'] ?? 0)) throw new InvalidArgumentException('Requester does not match your account.');
        return savePropertyGateRequest($db, $input);
    }
    if (!in_array($kind, ['visitor', 'permit'], true)) throw new InvalidArgumentException('Unknown request type.');
    if (!residentUserHasPermission($db,$userId,$kind==='visitor' ? 'resident.visitors.register' : 'resident.permits.request')) throw new InvalidArgumentException('This request type is not available for your resident account.');
    $start = trim((string)($input['start_date'] ?? ''));
    $end = trim((string)($input['end_date'] ?? '')) ?: $start;
    $details = trim((string)($input['details'] ?? ''));
    $name = trim((string)($input['visitor_name'] ?? ''));
    $contact = trim((string)($input['visitor_contact'] ?? ''));
    $type = trim((string)($input['permit_type'] ?? ''));
    $visitorCount = $kind === 'visitor' ? filter_var($input['visitor_count'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) : 1;
    if ($visitorCount === false) throw new InvalidArgumentException('Number of visitors must be a whole number between 1 and 65535.');
    if (!workflowDate($start) || !workflowDate($end) || $start < date('Y-m-d') || $end < $start) {
        throw new InvalidArgumentException('Choose valid dates from today onward, with the end on or after the start.');
    }
    if ($details === '' || strlen($details) > 500 || strlen($name) > 120 || strlen($contact) > 30) {
        throw new InvalidArgumentException('Enter a purpose or description (up to 500 characters) and valid visitor details.');
    }
    if ($kind === 'visitor' && ($name === '' || $start !== $end)) {
        throw new InvalidArgumentException('Provide the visitor name and a single visit date. Register each visit separately.');
    }
    if ($kind === 'permit' && !in_array($type, RESIDENT_PERMIT_TYPES, true)) {
        throw new InvalidArgumentException('Choose a supported permit type.');
    }
    $owner = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'resident' AND status = 'approved' AND is_verified = 1 AND is_active = 1 AND unit_number IS NOT NULL AND unit_number <> ''");
    $owner->bind_param('i', $userId);
    $owner->execute();
    if (!$owner->get_result()->fetch_assoc()) throw new InvalidArgumentException('An approved resident account with an assigned unit is required.');
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO resident_service_requests (user_id, request_kind, permit_type, visitor_name, visitor_contact, visitor_count, details, start_date, end_date, access_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('issssissss', $userId, $kind, $type, $name, $contact, $visitorCount, $details, $start, $end, $token);
    if (!$stmt->execute()) throw new RuntimeException('Could not save the request.');
    return (int)$db->insert_id;
}

function getResidentServiceRequests(mysqli $db, string $kind, ?int $userId = null): array {
    $itemCount = $kind === 'permit' ? ', (SELECT COUNT(*) FROM permit_items pi WHERE pi.request_id = r.id) AS item_count' : '';
    $sql = 'SELECT r.*, u.full_name, u.unit_number' . $itemCount . ' FROM resident_service_requests r JOIN users u ON u.id = r.user_id WHERE r.request_kind = ?';
    if ($userId !== null) $sql .= ' AND r.user_id = ?';
    $stmt = $db->prepare($sql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 200');
    if ($userId !== null) $stmt->bind_param('si', $kind, $userId); else $stmt->bind_param('s', $kind);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function decideResidentServiceRequest(mysqli $db, int $id, string $status, string $notes): bool {
    $request = gateRequest($db, $id);
    if ($request && $request['gate_data']) {
        try { gateTransition($db, $id, $status, ['admin_notes'=>$notes]); return true; }
        catch (InvalidArgumentException $e) { return false; }
    }
    if (!in_array($status, ['approved', 'rejected'], true) || strlen($notes) > 500) return false;
    $kind = isSecurity() ? 'visitor' : (canReviewPermits() ? null : 'denied');
    if ($kind === 'denied') return false;
    if (!ensureParkingTables($db)) return false;
    $actor = (int)$_SESSION['user_id'];
    $sql = "UPDATE resident_service_requests SET status = ?, admin_notes = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending' AND end_date >= CURDATE()";
    if ($kind !== null) $sql .= " AND request_kind = 'visitor'";
    $db->begin_transaction();
    try {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ssii', $status, $notes, $actor, $id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) { $db->rollback(); return false; }
        if ($status === 'rejected') {
            $cancel = $db->prepare("UPDATE parking_requests SET status = 'cancelled' WHERE visitor_registration_id = ? AND status IN ('pending','approved')");
            $cancel->bind_param('i', $id);
            if (!$cancel->execute()) throw new RuntimeException('Could not cancel linked parking.');
        }
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); error_log($e->getMessage()); return false; }
}

function residentServicePassUrl(array $request): string {
    if (!empty($request['gate_data'])) return gatePassUrl($request);
    return buildUrl('resident_service_pass.php?id=' . (int)$request['id'] . '&token=' . $request['access_token']);
}

function getResidentServicePass(mysqli $db, int $id, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    $stmt = $db->prepare("SELECT r.*, u.full_name, u.unit_number FROM resident_service_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND u.role = 'resident' AND u.status = 'approved' AND u.is_active = 1 AND u.is_verified = 1 AND u.unit_number IS NOT NULL AND u.unit_number <> ''");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['gate_data'])) return gateVerifyToken($db, $id, $token);
    return $row && (residentContext($db,(int)$row['user_id'])['approved'] ?? false) && hash_equals($row['access_token'], $token) ? $row : null;
}

function checkInRegisteredVisitor(mysqli $db, int $id, string $token): bool {
    if (!isSecurity()) return false;
    ensureQrScanHistorySchema($db);
    ensureVisitorLogsTable($db);
    ensureVisitorLogColumns($db);
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT r.*, u.unit_number, u.status AS resident_status, u.is_active, u.is_verified AS resident_verified, u.role AS resident_role FROM resident_service_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !hash_equals($row['access_token'], $token) || $row['request_kind'] !== 'visitor' || $row['status'] !== 'approved' || $row['resident_status'] !== 'approved' || !(int)$row['is_active'] || !(int)$row['resident_verified'] || $row['resident_role'] !== 'resident' || trim((string)$row['unit_number']) === '' || $row['start_date'] > date('Y-m-d') || $row['end_date'] < date('Y-m-d')) {
            $db->rollback(); return false;
        }
        if (!(residentContext($db,(int)$row['user_id'])['approved'] ?? false)) { $db->rollback(); return false; }
        $logId = logVisitorIn($db, $row['visitor_name'], $row['visitor_contact'] ?? '', $row['unit_number'], substr($row['details'], 0, 150), (int)$_SESSION['user_id'], (int)$row['visitor_count']);
        if (!$logId) throw new RuntimeException('Visitor log could not be saved.');
        $update = $db->prepare("UPDATE resident_service_requests SET status = 'checked_in', visitor_log_id = ? WHERE id = ?");
        $update->bind_param('ii', $logId, $id);
        if (!$update->execute()) throw new RuntimeException('Check-in could not be saved.');
        $eventRow=gateRequest($db,$id);
        qrActionEvent($db,$eventRow,'check_in','Visitor entry confirmed after identity verification.');
        $db->commit(); return true;
    } catch (Throwable $error) {
        $db->rollback(); error_log($error->getMessage()); return false;
    }
}
