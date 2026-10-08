<?php
/**
 * Parking management.
 *
 * Previously "Parking Slot" was just a third entry in the amenity-booking
 * list (resident/book_amenity.php), booked the same way as the swimming
 * pool or function hall: pick any date/time, no slot number, no vehicle
 * info, no capacity limit, and nothing stopping two residents from
 * "booking" the same non-existent slot. That never modeled how parking
 * actually works, so it's been split into its own system:
 *
 *   - parking_slots: the physical inventory (slot code, level, whether
 *     it's a resident-assigned slot or a visitor slot, current status).
 *   - parking_requests: what residents ask for — either a long-term
 *     resident slot assignment, or a short-term visitor slot with a
 *     plate number and date range. Admins approve/reject and, on
 *     approval, assign a specific slot.
 */

function ensureParkingTables(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    $slotsOk = $connection->query("CREATE TABLE IF NOT EXISTS parking_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slot_code VARCHAR(20) NOT NULL UNIQUE,
        level VARCHAR(30) DEFAULT NULL,
        slot_type ENUM('resident', 'visitor') NOT NULL DEFAULT 'resident',
        status ENUM('available', 'occupied', 'maintenance') NOT NULL DEFAULT 'available',
        assigned_user_id INT DEFAULT NULL,
        assigned_unit VARCHAR(30) DEFAULT NULL,
        vehicle_plate VARCHAR(20) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (slot_type),
        INDEX (status),
        CONSTRAINT fk_parking_slots_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
    )") === true;

    if (!$slotsOk) {
        return false;
    }

    $slotColumns = [
        'slot_code' => "slot_code VARCHAR(20) NOT NULL",
        'level' => "level VARCHAR(30) DEFAULT NULL",
        'slot_type' => "slot_type ENUM('resident', 'visitor') NOT NULL DEFAULT 'resident'",
        'status' => "status ENUM('available', 'occupied', 'maintenance') NOT NULL DEFAULT 'available'",
        'assigned_user_id' => "assigned_user_id INT DEFAULT NULL",
        'assigned_unit' => "assigned_unit VARCHAR(30) DEFAULT NULL",
        'vehicle_plate' => "vehicle_plate VARCHAR(20) DEFAULT NULL",
        'notes' => "notes VARCHAR(255) DEFAULT NULL",
        'created_at' => "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($slotColumns as $column => $definition) {
        $exists = $connection->query("SHOW COLUMNS FROM parking_slots LIKE '" . $connection->real_escape_string($column) . "'");
        if ($exists && $exists->num_rows === 0 && !$connection->query('ALTER TABLE parking_slots ADD COLUMN ' . $definition)) {
            return false;
        }
    }

    $requestsOk = $connection->query("CREATE TABLE IF NOT EXISTS parking_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        request_type ENUM('resident_assignment', 'visitor') NOT NULL DEFAULT 'visitor',
        vehicle_plate VARCHAR(20) NOT NULL,
        vehicle_description VARCHAR(120) DEFAULT NULL,
        start_date DATE NOT NULL,
        end_date DATE DEFAULT NULL,
        slot_id INT DEFAULT NULL,
        visitor_registration_id INT DEFAULT NULL,
        status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
        admin_notes VARCHAR(255) DEFAULT NULL,
        decided_by INT DEFAULT NULL,
        decided_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status),
        CONSTRAINT fk_parking_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_parking_requests_slot FOREIGN KEY (slot_id) REFERENCES parking_slots(id) ON DELETE SET NULL
    )") === true;

    if (!$requestsOk) {
        return false;
    }

    $requestColumns = [
        'user_id' => "user_id INT NOT NULL",
        'request_type' => "request_type ENUM('resident_assignment', 'visitor') NOT NULL DEFAULT 'visitor'",
        'vehicle_plate' => "vehicle_plate VARCHAR(20) NOT NULL",
        'vehicle_description' => "vehicle_description VARCHAR(120) DEFAULT NULL",
        'start_date' => "start_date DATE NOT NULL",
        'end_date' => "end_date DATE DEFAULT NULL",
        'slot_id' => "slot_id INT DEFAULT NULL",
        'visitor_registration_id' => "visitor_registration_id INT DEFAULT NULL",
        'status' => "status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'",
        'admin_notes' => "admin_notes VARCHAR(255) DEFAULT NULL",
        'decided_by' => "decided_by INT DEFAULT NULL",
        'decided_at' => "decided_at DATETIME DEFAULT NULL",
        'created_at' => "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($requestColumns as $column => $definition) {
        $exists = $connection->query("SHOW COLUMNS FROM parking_requests LIKE '" . $connection->real_escape_string($column) . "'");
        if ($exists && $exists->num_rows === 0 && !$connection->query('ALTER TABLE parking_requests ADD COLUMN ' . $definition)) {
            return false;
        }
    }

    $visitorIndex = $connection->query("SHOW INDEX FROM parking_requests WHERE Key_name = 'idx_parking_visitor_registration'");
    if (!$visitorIndex || ($visitorIndex->num_rows === 0 && !$connection->query('ALTER TABLE parking_requests ADD INDEX idx_parking_visitor_registration (visitor_registration_id)'))) return false;

    return true;
}

function getParkingInventoryFilePath(): string {
    $baseDir = dirname(__DIR__);
    $candidates = [
        $baseDir . '/parking-inventory.csv',
        $baseDir . '/inventory/parking-inventory.csv',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

function importParkingInventoryCsv(mysqli $connection): array {
    $filePath = getParkingInventoryFilePath();
    $result = [
        'file_path' => $filePath,
        'rows_read' => 0,
        'imported' => 0,
        'skipped' => 0,
        'errors' => [],
        'ok' => false,
    ];

    if (!is_file($filePath)) {
        $result['errors'][] = "Parking inventory CSV not found: {$filePath}";
        return $result;
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        $result['errors'][] = "Could not open parking inventory CSV.";
        return $result;
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        $result['errors'][] = 'Parking inventory CSV is empty.';
        return $result;
    }

    $normalizedHeader = [];
    foreach ($header as $index => $name) {
        $headerName = preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) ?: (string) $name;
        $normalizedHeader[strtolower(str_replace([' ', '-', '_'], '', $headerName))] = $index;
    }

    $requiredColumns = ['parkingslotcode', 'level', 'type'];
    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $normalizedHeader)) {
            fclose($handle);
            $result['errors'][] = "Parking inventory CSV is missing required column: {$column}.";
            return $result;
        }
    }

    $existingCodes = [];
    $existingResult = $connection->query('SELECT slot_code FROM parking_slots');
    if ($existingResult) {
        while ($row = $existingResult->fetch_assoc()) {
            $existingCodes[strtoupper(trim((string) $row['slot_code']))] = true;
        }
    }

    $stmt = $connection->prepare('INSERT INTO parking_slots (slot_code, level, slot_type, status) VALUES (?, ?, ?, ?)');

    while (($row = fgetcsv($handle)) !== false) {
        $result['rows_read']++;
        if (!is_array($row) || count($row) < count($header)) {
            $result['errors'][] = "Invalid row {$result['rows_read']} in parking inventory CSV.";
            continue;
        }

        $slotCode = strtoupper(trim((string)($row[$normalizedHeader['parkingslotcode']] ?? '')));
        $level = trim((string)($row[$normalizedHeader['level']] ?? ''));
        $slotTypeRaw = $row[$normalizedHeader['type']] ?? 'resident';
        $slotType = strtolower(trim((string)$slotTypeRaw));

        if ($slotCode === '' || $level === '') {
            $result['errors'][] = "Invalid slot row {$result['rows_read']}: slot code and level are required.";
            continue;
        }
        if (!in_array($slotType, ['resident', 'visitor'], true)) {
            $result['errors'][] = "Invalid slot type for {$slotCode}: {$slotType}.";
            continue;
        }
        if (isset($existingCodes[$slotCode])) {
            $result['skipped']++;
            continue;
        }

        $status = 'available';
        $stmt->bind_param('ssss', $slotCode, $level, $slotType, $status);
        $stmt->execute();
        if ($stmt->errno !== 0) {
            $result['errors'][] = "Could not import {$slotCode}: {$stmt->error}";
            continue;
        }

        $existingCodes[$slotCode] = true;
        $result['imported']++;
    }

    fclose($handle);
    $result['ok'] = $result['errors'] === [];
    return $result;
}

function getParkingSlots(mysqli $connection, string $typeFilter = 'all', string $statusFilter = 'all', string $searchTerm = ''): array {
    ensureParkingTables($connection);
    if ($searchTerm !== '') {
        $searchPattern = '%' . $searchTerm . '%';
        $stmt = $connection->prepare('SELECT ps.*, u.full_name AS assigned_name FROM parking_slots ps LEFT JOIN users u ON u.id = ps.assigned_user_id WHERE ps.slot_code LIKE ? OR ps.vehicle_plate LIKE ? OR ps.assigned_unit LIKE ? OR u.unit_number LIKE ? ORDER BY ps.level ASC, ps.slot_code ASC');
        $stmt->bind_param('ssss', $searchPattern, $searchPattern, $searchPattern, $searchPattern);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $connection->query('SELECT ps.*, u.full_name AS assigned_name FROM parking_slots ps LEFT JOIN users u ON u.id = ps.assigned_user_id ORDER BY ps.level ASC, ps.slot_code ASC');
    }
    $slots = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    return array_values(array_filter($slots, function (array $slot) use ($typeFilter, $statusFilter): bool {
        if ($typeFilter !== 'all' && $slot['slot_type'] !== $typeFilter) {
            return false;
        }
        if ($statusFilter !== 'all' && $slot['status'] !== $statusFilter) {
            return false;
        }
        return true;
    }));
}

function getAvailableParkingSlots(mysqli $connection, string $type): array {
    ensureParkingTables($connection);
    $stmt = $connection->prepare("SELECT * FROM parking_slots WHERE status = 'available' AND slot_type = ? ORDER BY slot_code ASC");
    $stmt->bind_param('s', $type);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function createParkingSlot(string $slotCode, string $level, string $slotType): bool {
    if (!canAccess('parking.configure')) return false;
    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare('INSERT INTO parking_slots (slot_code, level, slot_type) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $slotCode, $level, $slotType);
    if ($stmt->execute()) {
        logAudit('create', 'parking_slot', $connection->insert_id, "Added slot {$slotCode} ({$level}, {$slotType})");
        return true;
    }
    return false;
}

/** Directly assigns a resident to a slot (for standing resident assignments, outside the request flow). */
function assignParkingSlot(int $slotId, int $userId, string $unitNumber, string $vehiclePlate): bool {
    if (!canAccess('parking.configure') || $slotId < 1 || $userId < 1 || normalizePlateNumber($vehiclePlate) === '') return false;
    $connection = connectDb();
    ensureParkingTables($connection); ensureVehiclesTable($connection); ensureAuditLogTable($connection);
    $connection->begin_transaction();
    try {
        $slot = $connection->prepare("SELECT id FROM parking_slots WHERE id = ? AND slot_type = 'resident' AND status = 'available' AND assigned_user_id IS NULL FOR UPDATE");
        $slot->bind_param('i', $slotId); $slot->execute();
        if (!$slot->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        $plate = normalizePlateNumber($vehiclePlate);
        $vehicleQuery = $connection->prepare("SELECT v.id, v.plate_number, u.unit_number FROM vehicles v JOIN users u ON u.id = v.user_id WHERE v.user_id = ? AND v.normalized_plate = ? AND v.status = 'approved' AND v.parking_slot_id IS NULL AND u.role = 'resident' AND u.status = 'approved' AND u.is_verified = 1 AND u.is_active = 1 FOR UPDATE");
        $vehicleQuery->bind_param('is', $userId, $plate); $vehicleQuery->execute();
        $vehicle = $vehicleQuery->get_result()->fetch_assoc();
        if (!$vehicle || normalizeUnitNumber((string)$vehicle['unit_number']) === '' || normalizeUnitNumber((string)$vehicle['unit_number']) !== normalizeUnitNumber($unitNumber)) { $connection->rollback(); return false; }
        $reserved = $connection->prepare("SELECT id FROM parking_requests WHERE slot_id = ? AND status = 'approved' AND COALESCE(end_date,start_date) >= CURRENT_DATE() LIMIT 1 FOR UPDATE");
        $reserved->bind_param('i', $slotId); $reserved->execute();
        if ($reserved->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        $update = $connection->prepare("UPDATE parking_slots SET status = 'occupied', assigned_user_id = ?, assigned_unit = ?, vehicle_plate = ? WHERE id = ? AND status = 'available' AND assigned_user_id IS NULL");
        $update->bind_param('issi', $userId, $vehicle['unit_number'], $vehicle['plate_number'], $slotId);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Slot assignment changed.');
        $vehicleUpdate = $connection->prepare('UPDATE vehicles SET parking_slot_id = ? WHERE id = ? AND parking_slot_id IS NULL');
        $vehicleUpdate->bind_param('ii', $slotId, $vehicle['id']);
        if (!$vehicleUpdate->execute() || $vehicleUpdate->affected_rows !== 1) throw new RuntimeException('Vehicle assignment changed.');
        if (!logAudit('assign', 'parking_slot', $slotId, 'Assigned resident vehicle ' . $vehicle['plate_number'], $connection)) throw new RuntimeException('Assignment audit failed.');
        $connection->commit(); return true;
    } catch (Throwable $error) { $connection->rollback(); error_log('Standing parking assignment failed: ' . $error->getMessage()); return false; }
}

function releaseParkingSlot(int $slotId): bool {
    if (!canAccess('parking.configure') || $slotId < 1) return false;
    $connection = connectDb();
    ensureParkingTables($connection); ensureVehiclesTable($connection); ensureAuditLogTable($connection);
    $connection->begin_transaction();
    try {
        $slot = $connection->prepare("SELECT id FROM parking_slots WHERE id = ? AND slot_type = 'resident' AND assigned_user_id IS NOT NULL FOR UPDATE");
        $slot->bind_param('i', $slotId); $slot->execute();
        if (!$slot->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        $visitor = $connection->prepare("SELECT id FROM parking_requests WHERE slot_id = ? AND request_type = 'visitor' AND status = 'approved' AND COALESCE(end_date,start_date) >= CURRENT_DATE() LIMIT 1 FOR UPDATE");
        $visitor->bind_param('i', $slotId); $visitor->execute();
        if ($visitor->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        foreach ([
            "UPDATE parking_slots SET status = IF(status='maintenance','maintenance','available'), assigned_user_id=NULL, assigned_unit=NULL, vehicle_plate=NULL WHERE id=?",
            'UPDATE vehicles SET parking_slot_id=NULL WHERE parking_slot_id=?',
            "UPDATE parking_requests SET status='cancelled' WHERE slot_id=? AND request_type='resident_assignment' AND status='approved'"
        ] as $sql) {
            $update = $connection->prepare($sql); $update->bind_param('i',$slotId);
            if (!$update->execute()) throw new RuntimeException('Standing assignment could not be released.');
        }
        if (!logAudit('release','parking_slot',$slotId,'Released standing resident assignment',$connection)) throw new RuntimeException('Release audit failed.');
        $connection->commit(); return true;
    } catch (Throwable $error) { $connection->rollback(); error_log('Standing parking release failed: ' . $error->getMessage()); return false; }
}

function setParkingSlotStatus(int $slotId, string $status): bool {
    if (!canAccess('parking.configure') || $slotId < 1 || !in_array($status, ['available','occupied','maintenance'], true)) return false;
    $connection = connectDb();
    ensureParkingTables($connection);
    $connection->begin_transaction();
    try {
        $slotQuery = $connection->prepare('SELECT status, assigned_user_id FROM parking_slots WHERE id = ? FOR UPDATE');
        $slotQuery->bind_param('i', $slotId); $slotQuery->execute();
        $slot = $slotQuery->get_result()->fetch_assoc();
        if (!$slot || ($status === 'available' && !empty($slot['assigned_user_id']))) { $connection->rollback(); return false; }
        if ($status !== 'available') {
            $reservation = $connection->prepare("SELECT id FROM parking_requests WHERE slot_id = ? AND status = 'approved' AND COALESCE(end_date, start_date) >= CURRENT_DATE() LIMIT 1 FOR UPDATE");
            $reservation->bind_param('i', $slotId); $reservation->execute();
            if ($reservation->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        }
        $update = $connection->prepare('UPDATE parking_slots SET status = ? WHERE id = ?');
        $update->bind_param('si', $status, $slotId);
        if (!$update->execute()) throw new RuntimeException('Slot status could not be saved.');
        $connection->commit();
        logAudit('update_status', 'parking_slot', $slotId, "Status set to {$status}");
        return true;
    } catch (Throwable $error) { $connection->rollback(); error_log($error->getMessage()); return false; }
}

function getParkingRequestsForUser(int $userId): array {
    $connection = connectDb();
    ensureParkingTables($connection);
    ensureResidentServicesTables($connection);
    $stmt = $connection->prepare('SELECT pr.*, ps.slot_code, ps.level, u.full_name, u.unit_number, vr.visitor_name, vr.status AS visitor_status FROM parking_requests pr LEFT JOIN parking_slots ps ON ps.id = pr.slot_id INNER JOIN users u ON u.id = pr.user_id LEFT JOIN resident_service_requests vr ON vr.id = pr.visitor_registration_id AND vr.user_id = pr.user_id WHERE pr.user_id = ? ORDER BY pr.created_at DESC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function parkingPassSignature(int $requestId): string {
    $key = appSetting('CONDO_PASS_SIGNING_KEY');
    if ($key === '') {
        $db = connectDb();
        if (schemaMutationAllowed()) {
            $db->query("CREATE TABLE IF NOT EXISTS access_signing_keys (key_name VARCHAR(30) PRIMARY KEY, key_value CHAR(64) NOT NULL) ENGINE=InnoDB");
            $generated = bin2hex(random_bytes(32));
            $insert = $db->prepare("INSERT IGNORE INTO access_signing_keys (key_name,key_value) VALUES ('parking', ?)");
            $insert->bind_param('s', $generated); $insert->execute();
        }
        $result = $db->query("SELECT key_value FROM access_signing_keys WHERE key_name = 'parking'");
        $key = $result ? (string)($result->fetch_assoc()['key_value'] ?? '') : '';
        $db->close();
        if ($key === '') throw new RuntimeException('Parking signing key is missing. Run the database migrations.');
    }
    return hash_hmac('sha256', 'parking-pass:' . $requestId, $key);
}

function getParkingPass(int $requestId, string $signature): ?array {
    if (!hash_equals(parkingPassSignature($requestId), $signature)) {
        return null;
    }

    $connection = connectDb();
    ensureParkingTables($connection);
    ensureResidentServicesTables($connection);
    $stmt = $connection->prepare("SELECT pr.*, ps.slot_code, ps.level, u.full_name, u.unit_number, u.email, vr.visitor_name, vr.status AS visitor_status FROM parking_requests pr INNER JOIN users u ON u.id = pr.user_id LEFT JOIN parking_slots ps ON ps.id = pr.slot_id LEFT JOIN resident_service_requests vr ON vr.id = pr.visitor_registration_id AND vr.user_id = pr.user_id WHERE pr.id = ? AND pr.request_type = 'visitor' AND pr.status = 'approved' AND u.status = 'approved' AND u.is_active = 1 AND u.is_verified = 1 AND u.role = 'resident' AND TRIM(COALESCE(u.unit_number,'')) <> '' AND (pr.slot_id IS NULL OR ps.status <> 'maintenance') AND (pr.visitor_registration_id IS NULL OR (vr.request_kind = 'visitor' AND vr.status IN ('approved','checked_in'))) LIMIT 1");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $pass=$stmt->get_result()->fetch_assoc();
    return $pass && (residentContext($connection,(int)$pass['user_id'])['approved'] ?? false) ? $pass : null;
}

function parkingPassUrl(int $requestId): string {
    return buildUrl('parking_pass.php?request_id=' . $requestId . '&signature=' . parkingPassSignature($requestId));
}

function getAllParkingRequests(mysqli $connection): array {
    ensureParkingTables($connection);
    ensureResidentServicesTables($connection);
    $result = $connection->query('SELECT pr.*, ps.slot_code, u.full_name, u.username, u.unit_number, u.email, u.contact_number, vr.visitor_name, vr.status AS visitor_status FROM parking_requests pr INNER JOIN users u ON u.id = pr.user_id LEFT JOIN parking_slots ps ON ps.id = pr.slot_id LEFT JOIN resident_service_requests vr ON vr.id = pr.visitor_registration_id AND vr.user_id = pr.user_id ORDER BY FIELD(pr.status, "pending", "approved", "rejected", "cancelled"), pr.created_at DESC');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function createParkingRequest(int $userId, string $requestType, string $vehiclePlate, string $vehicleDescription, string $startDate, ?string $endDate): bool {
    $connection = connectDb();
    if (!residentUserHasPermission($connection,$userId,'resident.parking.request')) return false;
    ensureParkingTables($connection);
    $policy = getParkingPolicy($connection);
    $endDate = $endDate ?: $startDate;
    if ($requestType !== 'visitor' || $vehiclePlate === '' || strlen($vehiclePlate) > 20 || strlen($vehicleDescription) > 100 || !workflowDate($startDate) || !workflowDate($endDate) || $startDate < date('Y-m-d') || $endDate < $startDate || (strtotime($endDate) - strtotime($startDate)) >= ((int)$policy['visitor_max_days'] * 86400)) return false;
    $stmt = $connection->prepare('INSERT INTO parking_requests (user_id, request_type, vehicle_plate, vehicle_description, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssss', $userId, $requestType, $vehiclePlate, $vehicleDescription, $startDate, $endDate);
    if ($stmt->execute()) {
        trackEvent('parking_request_created', $requestType, $userId);
        return true;
    }
    return false;
}

/**
 * Assigns a compatible inventory slot automatically unless one is selected.
 * Visitor reservations are dated; standing resident assignments occupy
 * the inventory row. Decisions and assignments commit with their audit.
 */
function decideParkingRequest(int $requestId, string $decision, ?int $slotId, string $adminNotes): bool {
    if (!canAccess('parking.review') || !in_array($decision, ['approved', 'rejected'], true) || strlen($adminNotes) > 255) return false;
    $connection = connectDb();
    ensureParkingTables($connection);
    ensureResidentServicesTables($connection);
    ensureVehiclesTable($connection);
    ensureAuditLogTable($connection);
    $connection->begin_transaction();
    try {
        $requestStmt = $connection->prepare("SELECT pr.*, u.unit_number, u.status AS owner_status, u.is_active AS owner_active, u.is_verified AS owner_verified, u.role AS owner_role FROM parking_requests pr JOIN users u ON u.id = pr.user_id WHERE pr.id = ? AND pr.status = 'pending' FOR UPDATE");
        $requestStmt->bind_param('i', $requestId);
        $requestStmt->execute();
        $request = $requestStmt->get_result()->fetch_assoc();
        if (!$request) { $connection->rollback(); return false; }
        if ($decision==='approved' && !(residentContext($connection,(int)$request['user_id'])['approved'] ?? false)) { $connection->rollback(); return false; }
        if ($decision === 'approved' && ($request['owner_status'] !== 'approved' || (int)$request['owner_active'] !== 1 || (int)$request['owner_verified'] !== 1 || $request['owner_role'] !== 'resident' || trim((string)$request['unit_number']) === '' || ($request['request_type'] === 'visitor' && ($request['end_date'] ?: $request['start_date']) < date('Y-m-d')))) { $connection->rollback(); return false; }
        if ($decision === 'approved' && !empty($request['visitor_registration_id'])) {
            $visitorStmt = $connection->prepare("SELECT id FROM resident_service_requests WHERE id = ? AND user_id = ? AND request_kind = 'visitor' AND status IN ('approved','checked_in') FOR UPDATE");
            $visitorStmt->bind_param('ii', $request['visitor_registration_id'], $request['user_id']); $visitorStmt->execute();
            if (!$visitorStmt->get_result()->fetch_assoc()) { $connection->rollback(); return false; }
        }
        if ($decision === 'approved') {
            $type = $request['request_type'] === 'visitor' ? 'visitor' : 'resident';
            $endDate = $request['end_date'] ?: $request['start_date'];
            $slot = selectParkingSlotForAllocation($connection, $type, $request['start_date'], $endDate, $slotId, $requestId);
            if (!$slot) throw new RuntimeException('No compatible parking slot is available for this request.');
            $slotId = (int)$slot['id'];
            if ($type === 'resident') {
                $assign = $connection->prepare("UPDATE parking_slots SET status = 'occupied', assigned_user_id = ?, assigned_unit = ?, vehicle_plate = ? WHERE id = ?");
                $assign->bind_param('issi', $request['user_id'], $request['unit_number'], $request['vehicle_plate'], $slotId);
                if (!$assign->execute()) throw new RuntimeException('Slot assignment failed.');
                $plate = normalizePlateNumber($request['vehicle_plate']);
                $vehicle = $connection->prepare("SELECT v.*, u.unit_number FROM vehicles v JOIN users u ON u.id = v.user_id WHERE v.user_id = ? AND v.normalized_plate = ? AND v.status = 'approved' FOR UPDATE");
                $vehicle->bind_param('is', $request['user_id'], $plate); $vehicle->execute();
                $registered = $vehicle->get_result()->fetch_assoc();
                if ($registered) {
                    if (!empty($registered['parking_slot_id'])) throw new RuntimeException('This vehicle already has a resident slot.');
                    occupyParkingSlotForVehicle($connection, $registered, $slot);
                }
            }
        } else { $slotId = null; }
        $adminId = (int)$_SESSION['user_id'];
        $update = $connection->prepare("UPDATE parking_requests SET status = ?, slot_id = ?, admin_notes = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending'");
        $update->bind_param('sisii', $decision, $slotId, $adminNotes, $adminId, $requestId);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Request changed before approval.');
        if (!logAudit($decision === 'approved' ? 'approve' : 'reject', 'parking_request', $requestId, 'Slot #' . ($slotId ?? 0) . ' ' . $adminNotes, $connection)) throw new RuntimeException('Parking decision audit failed.');
        $connection->commit();
        return true;
    } catch (Throwable $error) { $connection->rollback(); error_log($error->getMessage()); return false; }
}
