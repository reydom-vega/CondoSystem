<?php
/** The caller prepares storage before starting any transaction. */
function insertLinkedVisitorParking(mysqli $db, int $userId, int $visitorId, string $plate, string $description, string $start, string $end, array $policy): int {
    if (!residentUserHasPermission($db,$userId,'resident.parking.request') || !residentUserHasPermission($db,$userId,'resident.visitors.register')) throw new InvalidArgumentException('Visitor parking is disabled for this resident account.');
    $plate = strtoupper(trim($plate));
    $description = trim($description);
    if ($plate === '' || strlen($plate) > 20 || strlen($description) > 120) throw new InvalidArgumentException('Enter a plate number (up to 20 characters) and vehicle details (up to 120 characters).');
    if (!workflowDate($start) || !workflowDate($end) || $start < date('Y-m-d') || $end < $start || (strtotime($end) - strtotime($start)) >= (int)$policy['visitor_max_days'] * 86400) throw new InvalidArgumentException('Choose a valid parking period of at most ' . (int)$policy['visitor_max_days'] . ' days, including the start date.');
    $visitor = $db->prepare("SELECT r.* FROM resident_service_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.user_id = ? AND r.request_kind = 'visitor' AND r.status IN ('pending','approved','checked_in') AND u.role = 'resident' AND u.status = 'approved' AND u.is_active = 1 AND u.is_verified = 1 AND u.unit_number IS NOT NULL AND u.unit_number <> '' FOR UPDATE");
    $visitor->bind_param('ii', $visitorId, $userId); $visitor->execute();
    $registration = $visitor->get_result()->fetch_assoc();
    if (!$registration || $registration['start_date'] < $start || $registration['end_date'] > $end) throw new InvalidArgumentException('Select your active visitor registration and a parking period that includes the visit date.');
    $duplicate = $db->prepare("SELECT id FROM parking_requests WHERE visitor_registration_id = ? AND status IN ('pending','approved') LIMIT 1 FOR UPDATE");
    $duplicate->bind_param('i', $visitorId); $duplicate->execute();
    if ($duplicate->get_result()->fetch_assoc()) throw new InvalidArgumentException('This visitor already has a parking request in progress.');
    $insert = $db->prepare("INSERT INTO parking_requests (user_id,request_type,visitor_registration_id,vehicle_plate,vehicle_description,start_date,end_date) VALUES (?, 'visitor', ?, ?, ?, ?, ?)");
    $insert->bind_param('iissss', $userId, $visitorId, $plate, $description, $start, $end);
    if (!$insert->execute()) throw new RuntimeException('Could not save visitor parking.');
    return (int)$db->insert_id;
}

function requestLinkedVisitorParking(mysqli $db, int $userId, int $visitorId, array $input): int {
    if (!ensureResidentServicesTables($db) || !ensureParkingTables($db)) throw new RuntimeException('Visitor parking storage is unavailable.');
    $policy = getParkingPolicy($db);
    $start = trim((string)($input['start_date'] ?? ''));
    $end = trim((string)($input['end_date'] ?? '')) ?: $start;
    $db->begin_transaction();
    try {
        $id = insertLinkedVisitorParking($db, $userId, $visitorId, (string)($input['vehicle_plate'] ?? ''), (string)($input['vehicle_description'] ?? ''), $start, $end, $policy);
        $db->commit(); return $id;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function registerVisitorWithParking(mysqli $db, int $userId, array $input): int {
    if (!ensureResidentServicesTables($db) || !ensureParkingTables($db)) throw new RuntimeException('Visitor storage is unavailable.');
    $policy = getParkingPolicy($db);
    $db->begin_transaction();
    try {
        $id = createResidentServiceRequest($db, $userId, 'visitor', $input);
        if (($input['needs_parking'] ?? '') === '1') {
            $date = trim((string)($input['start_date'] ?? ''));
            insertLinkedVisitorParking($db, $userId, $id, (string)($input['vehicle_plate'] ?? ''), (string)($input['vehicle_description'] ?? ''), $date, $date, $policy);
        }
        $db->commit(); return $id;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function cancelResidentServiceRequest(mysqli $db, int $userId, int $id, string $kind): bool {
    if (!ensureParkingTables($db)) return false;
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("UPDATE resident_service_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND request_kind = ? AND status IN ('pending','approved')");
        $stmt->bind_param('iis', $id, $userId, $kind); $stmt->execute();
        if ($stmt->affected_rows !== 1) { $db->rollback(); return false; }
        if ($kind === 'visitor') {
            $cancel = $db->prepare("UPDATE parking_requests SET status = 'cancelled' WHERE visitor_registration_id = ? AND user_id = ? AND status IN ('pending','approved')");
            $cancel->bind_param('ii', $id, $userId);
            if (!$cancel->execute()) throw new RuntimeException('Linked parking cancellation failed.');
        }
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function cancelVisitorParkingRequest(mysqli $db, int $userId, int $id): bool {
    $stmt = $db->prepare("UPDATE parking_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND request_type = 'visitor' AND status IN ('pending','approved')");
    $stmt->bind_param('ii', $id, $userId);
    return $stmt->execute() && $stmt->affected_rows === 1;
}
