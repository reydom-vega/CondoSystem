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
    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare("UPDATE parking_slots SET status = 'occupied', assigned_user_id = ?, assigned_unit = ?, vehicle_plate = ? WHERE id = ?");
    $stmt->bind_param('issi', $userId, $unitNumber, $vehiclePlate, $slotId);
    if ($stmt->execute()) {
        logAudit('assign', 'parking_slot', $slotId, "Assigned to unit {$unitNumber}, plate {$vehiclePlate}");
        return true;
    }
    return false;
}

function releaseParkingSlot(int $slotId): bool {
    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare("UPDATE parking_slots SET status = 'available', assigned_user_id = NULL, assigned_unit = NULL, vehicle_plate = NULL WHERE id = ?");
    $stmt->bind_param('i', $slotId);
    if ($stmt->execute()) {
        logAudit('release', 'parking_slot', $slotId, 'Slot freed up');
        return true;
    }
    return false;
}

function setParkingSlotStatus(int $slotId, string $status): bool {
    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare('UPDATE parking_slots SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $slotId);
    if ($stmt->execute()) {
        logAudit('update_status', 'parking_slot', $slotId, "Status set to {$status}");
        return true;
    }
    return false;
}

function getParkingRequestsForUser(int $userId): array {
    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare('SELECT pr.*, ps.slot_code, ps.level, u.full_name, u.unit_number FROM parking_requests pr LEFT JOIN parking_slots ps ON ps.id = pr.slot_id INNER JOIN users u ON u.id = pr.user_id WHERE pr.user_id = ? ORDER BY pr.created_at DESC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function parkingPassSignature(int $requestId): string {
    return hash_hmac('sha256', 'parking-pass:' . $requestId, $GLOBALS['configuredDbPass'] . 'celadine-parking-pass');
}

function getParkingPass(int $requestId, string $signature): ?array {
    if (!hash_equals(parkingPassSignature($requestId), $signature)) {
        return null;
    }

    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare("SELECT pr.*, ps.slot_code, ps.level, u.full_name, u.unit_number, u.email FROM parking_requests pr INNER JOIN users u ON u.id = pr.user_id LEFT JOIN parking_slots ps ON ps.id = pr.slot_id WHERE pr.id = ? AND pr.request_type = 'visitor' AND pr.status = 'approved' LIMIT 1");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function parkingPassUrl(int $requestId): string {
    return buildUrl('parking_pass.php?request_id=' . $requestId . '&signature=' . parkingPassSignature($requestId));
}

function getAllParkingRequests(mysqli $connection): array {
    ensureParkingTables($connection);
    $result = $connection->query('SELECT pr.*, ps.slot_code, u.full_name, u.username, u.unit_number, u.email, u.contact_number FROM parking_requests pr INNER JOIN users u ON u.id = pr.user_id LEFT JOIN parking_slots ps ON ps.id = pr.slot_id ORDER BY FIELD(pr.status, "pending", "approved", "rejected", "cancelled"), pr.created_at DESC');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function createParkingRequest(int $userId, string $requestType, string $vehiclePlate, string $vehicleDescription, string $startDate, ?string $endDate): bool {
    $connection = connectDb();
    ensureParkingTables($connection);
    $stmt = $connection->prepare('INSERT INTO parking_requests (user_id, request_type, vehicle_plate, vehicle_description, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssss', $userId, $requestType, $vehiclePlate, $vehicleDescription, $startDate, $endDate);
    if ($stmt->execute()) {
        trackEvent('parking_request_created', $requestType, $userId);
        return true;
    }
    return false;
}

/**
 * Approves or rejects a parking request. On approval with a slot chosen,
 * the slot is marked occupied and tied to the requester. Every decision
 * is written to the audit log with the acting admin's identity.
 */
function decideParkingRequest(int $requestId, string $decision, ?int $slotId, string $adminNotes): bool {
    $connection = connectDb();
    ensureParkingTables($connection);

    $requestStmt = $connection->prepare('SELECT * FROM parking_requests WHERE id = ? LIMIT 1');
    $requestStmt->bind_param('i', $requestId);
    $requestStmt->execute();
    $request = $requestStmt->get_result()->fetch_assoc();
    if (!$request) {
        return false;
    }

    $adminId = $_SESSION['user_id'] ?? null;
    $status = $decision === 'approved' ? 'approved' : 'rejected';

    if ($status === 'approved' && $slotId) {
        $userStmt = $connection->prepare('SELECT unit_number FROM users WHERE id = ? LIMIT 1');
        $userStmt->bind_param('i', $request['user_id']);
        $userStmt->execute();
        $unit = $userStmt->get_result()->fetch_assoc()['unit_number'] ?? '';
        assignParkingSlot($slotId, (int)$request['user_id'], $unit, $request['vehicle_plate']);
    }

    $update = $connection->prepare('UPDATE parking_requests SET status = ?, slot_id = ?, admin_notes = ?, decided_by = ?, decided_at = NOW() WHERE id = ?');
    $update->bind_param('sisii', $status, $slotId, $adminNotes, $adminId, $requestId);

    if ($update->execute()) {
        logAudit($decision === 'approved' ? 'approve' : 'reject', 'parking_request', $requestId, "Request from user #{$request['user_id']} ({$request['vehicle_plate']})" . ($adminNotes !== '' ? " — {$adminNotes}" : ''));
        return true;
    }
    return false;
}
