<?php
/** Call inside a transaction. Inventory locks serialize allocation and reassignment. */
function selectParkingSlotForAllocation(mysqli $db, string $type, string $start, string $end, ?int $preferred = null, int $excludeRequest = 0): ?array {
    $sql = "SELECT * FROM parking_slots WHERE slot_type = ? AND status = 'available' AND assigned_user_id IS NULL";
    if ($preferred !== null) $sql .= ' AND id = ?';
    $sql .= ' ORDER BY level, slot_code, id FOR UPDATE';
    $find = $db->prepare($sql);
    if ($preferred !== null) $find->bind_param('si', $type, $preferred);
    else $find->bind_param('s', $type);
    $find->execute();
    foreach ($find->get_result()->fetch_all(MYSQLI_ASSOC) as $slot) {
        $id = (int)$slot['id'];
        // A standing resident assignment must also avoid future reservations.
        $dates = $type === 'resident' ? "COALESCE(end_date,start_date) >= CURRENT_DATE()" : 'start_date <= ? AND COALESCE(end_date,start_date) >= ?';
        $reserved = $db->prepare("SELECT id FROM parking_requests WHERE slot_id = ? AND id <> ? AND status = 'approved' AND (request_type = 'resident_assignment' OR ($dates)) LIMIT 1 FOR UPDATE");
        if ($type === 'resident') $reserved->bind_param('ii', $id, $excludeRequest);
        else $reserved->bind_param('iiss', $id, $excludeRequest, $end, $start);
        $reserved->execute();
        if ($reserved->get_result()->fetch_assoc()) continue;
        $vehicle = $db->prepare('SELECT id FROM vehicles WHERE parking_slot_id = ? LIMIT 1 FOR UPDATE');
        $vehicle->bind_param('i', $id); $vehicle->execute();
        if (!$vehicle->get_result()->fetch_assoc()) return $slot;
    }
    return null;
}

/** Link inventory, vehicle, and any standing requests on the same transaction. */
function occupyParkingSlotForVehicle(mysqli $db, array $vehicle, array $slot): void {
    $slotId = (int)$slot['id'];
    $update = $db->prepare("UPDATE parking_slots SET status = 'occupied', assigned_user_id = ?, assigned_unit = ?, vehicle_plate = ? WHERE id = ?");
    $update->bind_param('issi', $vehicle['user_id'], $vehicle['unit_number'], $vehicle['plate_number'], $slotId);
    if (!$update->execute()) throw new RuntimeException('Could not assign the resident slot.');
    $link = $db->prepare('UPDATE vehicles SET parking_slot_id = ? WHERE id = ?');
    $link->bind_param('ii', $slotId, $vehicle['id']);
    if (!$link->execute()) throw new RuntimeException('Could not link the vehicle slot.');
    $requests = $db->prepare("SELECT id, vehicle_plate FROM parking_requests WHERE user_id = ? AND request_type = 'resident_assignment' AND status = 'approved' FOR UPDATE");
    $requests->bind_param('i', $vehicle['user_id']); $requests->execute();
    foreach ($requests->get_result()->fetch_all(MYSQLI_ASSOC) as $request) {
        if (normalizePlateNumber($request['vehicle_plate']) !== $vehicle['normalized_plate']) continue;
        $sync = $db->prepare('UPDATE parking_requests SET slot_id = ? WHERE id = ?');
        $sync->bind_param('ii', $slotId, $request['id']);
        if (!$sync->execute()) throw new RuntimeException('Could not update the standing request.');
    }
}

/** Sticker issuance calls this inside its transaction, never a nested transaction. */
function allocateStickerVehicleSlot(mysqli $db, int $vehicleId): int {
    $find = $db->prepare("SELECT v.*, u.unit_number FROM vehicles v JOIN users u ON u.id = v.user_id WHERE v.id = ? AND v.status = 'approved' FOR UPDATE");
    $find->bind_param('i', $vehicleId); $find->execute();
    $vehicle = $find->get_result()->fetch_assoc();
    if (!$vehicle || !(residentContext($db, (int)$vehicle['user_id'])['approved'] ?? false)) throw new RuntimeException('The vehicle resident is no longer approved.');
    // Reuse an existing standing assignment instead of consuming a second slot.
    $existing = $db->prepare('SELECT * FROM parking_slots WHERE assigned_user_id = ? OR id = ? ORDER BY id FOR UPDATE');
    $currentId = (int)($vehicle['parking_slot_id'] ?? 0);
    $existing->bind_param('ii', $vehicle['user_id'], $currentId); $existing->execute();
    foreach ($existing->get_result()->fetch_all(MYSQLI_ASSOC) as $slot) {
        if ((int)$slot['assigned_user_id'] !== (int)$vehicle['user_id'] || normalizePlateNumber((string)$slot['vehicle_plate']) !== $vehicle['normalized_plate']) continue;
        if ($slot['slot_type'] !== 'resident' || $slot['status'] !== 'occupied') throw new RuntimeException('The existing resident slot is unavailable.');
        occupyParkingSlotForVehicle($db, $vehicle, $slot);
        return (int)$slot['id'];
    }
    if ($currentId > 0) throw new RuntimeException('The vehicle slot assignment needs review.');
    $slot = selectParkingSlotForAllocation($db, 'resident', date('Y-m-d'), date('Y-m-d'));
    if (!$slot) throw new RuntimeException('No resident parking slot is available in inventory.');
    occupyParkingSlotForVehicle($db, $vehicle, $slot);
    return (int)$slot['id'];
}

/** Change the standing slot of a vehicle with an issued, paid sticker. */
function reassignStickerVehicleSlot(int $vehicleId, int $slotId): bool {
    if (!canAccess('stickers.issue') || $vehicleId < 1 || $slotId < 1) return false;
    $db = connectDb();
    if (!ensureParkingTables($db) || !ensureStickerVehicleLinks($db)) return false;
    ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $find = $db->prepare("SELECT v.*, u.unit_number FROM vehicles v JOIN users u ON u.id = v.user_id JOIN parking_sticker_vehicles sv ON sv.vehicle_id = v.id JOIN parking_sticker_orders o ON o.id = sv.order_id JOIN payments p ON p.id = o.bill_payment_id WHERE v.id = ? AND v.status = 'approved' AND o.claim_status = 'issued' AND o.status <> 'cancelled' AND p.status = 'paid' FOR UPDATE");
        $find->bind_param('i', $vehicleId); $find->execute(); $vehicle = $find->get_result()->fetch_assoc();
        if (!$vehicle || !(residentContext($db, (int)$vehicle['user_id'])['approved'] ?? false)) throw new RuntimeException('Issued resident vehicle not found.');
        $oldId = (int)($vehicle['parking_slot_id'] ?? 0);
        if ($oldId === 0) {
            $existing = $db->prepare('SELECT id, vehicle_plate FROM parking_slots WHERE assigned_user_id = ? ORDER BY id FOR UPDATE');
            $existing->bind_param('i', $vehicle['user_id']); $existing->execute();
            foreach ($existing->get_result()->fetch_all(MYSQLI_ASSOC) as $previous) {
                if (normalizePlateNumber((string)$previous['vehicle_plate']) === $vehicle['normalized_plate']) {
                    $oldId = (int)$previous['id'];
                    break;
                }
            }
        }
        if ($oldId === $slotId) { $db->rollback(); return true; }
        $slot = selectParkingSlotForAllocation($db, 'resident', date('Y-m-d'), date('Y-m-d'), $slotId);
        if (!$slot) throw new RuntimeException('Choose an available resident slot without reservations.');
        if ($oldId > 0) {
            $old = $db->prepare('SELECT * FROM parking_slots WHERE id = ? FOR UPDATE');
            $old->bind_param('i', $oldId); $old->execute(); $previous = $old->get_result()->fetch_assoc();
            if (!$previous || (int)$previous['assigned_user_id'] !== (int)$vehicle['user_id'] || normalizePlateNumber((string)$previous['vehicle_plate']) !== $vehicle['normalized_plate']) throw new RuntimeException('The old slot assignment changed.');
            $release = $db->prepare("UPDATE parking_slots SET status = IF(status = 'maintenance', 'maintenance', 'available'), assigned_user_id = NULL, assigned_unit = NULL, vehicle_plate = NULL WHERE id = ?");
            $release->bind_param('i', $oldId);
            if (!$release->execute()) throw new RuntimeException('Could not release the old slot.');
        }
        occupyParkingSlotForVehicle($db, $vehicle, $slot);
        if (!logAudit('reassign', 'parking_slot', $slotId, 'Vehicle #' . $vehicleId . ' moved from slot #' . $oldId, $db)) throw new RuntimeException('Could not audit slot reassignment.');
        $db->commit(); return true;
    } catch (Throwable $error) { $db->rollback(); error_log($error->getMessage()); return false; }
}

/** Change an approved reservation without changing its dates or pass URL. */
function reassignParkingRequestSlot(int $requestId, int $slotId): bool {
    if (!canAccess('parking.review') || $requestId < 1 || $slotId < 1) return false;
    $db = connectDb();
    if (!ensureParkingTables($db) || !ensureVehiclesTable($db)) return false;
    ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $find = $db->prepare("SELECT pr.*, u.unit_number FROM parking_requests pr JOIN users u ON u.id = pr.user_id WHERE pr.id = ? AND pr.status = 'approved' AND (pr.request_type = 'resident_assignment' OR COALESCE(pr.end_date,pr.start_date) >= CURRENT_DATE()) FOR UPDATE");
        $find->bind_param('i', $requestId); $find->execute(); $request = $find->get_result()->fetch_assoc();
        if (!$request || !(residentContext($db, (int)$request['user_id'])['approved'] ?? false)) throw new RuntimeException('Active parking reservation not found.');
        $type = $request['request_type'] === 'visitor' ? 'visitor' : 'resident';
        $oldId = (int)($request['slot_id'] ?? 0);
        if ($oldId === $slotId) { $db->rollback(); return true; }
        $slot = selectParkingSlotForAllocation($db, $type, $request['start_date'], $request['end_date'] ?: $request['start_date'], $slotId, $requestId);
        if (!$slot) throw new RuntimeException('Choose a compatible available slot with no conflicting reservation.');
        if ($type === 'resident') {
            $old = $db->prepare('SELECT * FROM parking_slots WHERE id = ? FOR UPDATE');
            $old->bind_param('i', $oldId); $old->execute(); $previous = $old->get_result()->fetch_assoc();
            if (!$previous || (int)$previous['assigned_user_id'] !== (int)$request['user_id'] || normalizePlateNumber((string)$previous['vehicle_plate']) !== normalizePlateNumber($request['vehicle_plate'])) throw new RuntimeException('The standing assignment changed.');
            $release = $db->prepare("UPDATE parking_slots SET status = IF(status = 'maintenance', 'maintenance', 'available'), assigned_user_id = NULL, assigned_unit = NULL, vehicle_plate = NULL WHERE id = ?");
            $release->bind_param('i', $oldId);
            if (!$release->execute()) throw new RuntimeException('Could not release the old resident slot.');
            $plate = normalizePlateNumber($request['vehicle_plate']);
            $registered = $db->prepare("SELECT v.*, u.unit_number FROM vehicles v JOIN users u ON u.id = v.user_id WHERE v.user_id = ? AND v.normalized_plate = ? AND v.status = 'approved' FOR UPDATE");
            $registered->bind_param('is', $request['user_id'], $plate); $registered->execute(); $vehicle = $registered->get_result()->fetch_assoc();
            if ($vehicle) {
                if (!empty($vehicle['parking_slot_id']) && (int)$vehicle['parking_slot_id'] !== $oldId) throw new RuntimeException('The vehicle assignment changed.');
                occupyParkingSlotForVehicle($db, $vehicle, $slot);
            } else {
                $assign = $db->prepare("UPDATE parking_slots SET status = 'occupied', assigned_user_id = ?, assigned_unit = ?, vehicle_plate = ? WHERE id = ?");
                $assign->bind_param('issi', $request['user_id'], $request['unit_number'], $request['vehicle_plate'], $slotId);
                if (!$assign->execute()) throw new RuntimeException('Could not assign the new resident slot.');
            }
        }
        $update = $db->prepare('UPDATE parking_requests SET slot_id = ? WHERE id = ?');
        $update->bind_param('ii', $slotId, $requestId);
        if (!$update->execute()) throw new RuntimeException('Could not change the designated slot.');
        if (!logAudit('reassign', 'parking_request', $requestId, 'Moved from slot #' . (int)$request['slot_id'] . ' to #' . $slotId, $db)) throw new RuntimeException('Could not audit visitor reassignment.');
        $db->commit(); return true;
    } catch (Throwable $error) { $db->rollback(); error_log($error->getMessage()); return false; }
}
