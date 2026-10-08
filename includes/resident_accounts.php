<?php
/** Approve a verified application and serialize assignment of the same unit. */
function approvePendingResident(mysqli $db, int $userId, string $unitNumber): array {
    if (!canAccess('accounts.review')) throw new InvalidArgumentException('Account review requires the SuperAdmin role.');
    $unitNumber = normalizeUnitNumber($unitNumber);
    $inventory = array_map(static fn(array $unit): string => normalizeUnitNumber((string)($unit['unit_number'] ?? '')), loadUnitInventory());
    if ($userId < 1 || $unitNumber === '' || !in_array($unitNumber, $inventory, true)) {
        throw new InvalidArgumentException('Choose a valid unit from the building inventory.');
    }
    $databaseName = (string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
    $lockName = 'condo_unit_' . substr(hash('sha256', $databaseName . ':' . $unitNumber), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $lock->bind_param('s', $lockName); $lock->execute();
    if ((int)($lock->get_result()->fetch_assoc()['acquired'] ?? 0) !== 1) throw new RuntimeException('Unit assignment is busy. Try again.');
    try {
        $db->begin_transaction();
        $applicantQuery = $db->prepare("SELECT full_name, email, account_type, session_version FROM users WHERE id = ? AND role = 'resident' AND status = 'pending' AND is_verified = 1 FOR UPDATE");
        $applicantQuery->bind_param('i', $userId); $applicantQuery->execute();
        $applicant = $applicantQuery->get_result()->fetch_assoc();
        if (!$applicant) throw new InvalidArgumentException('This registration must be verified and pending before approval.');
        $kind=residentAccountKind($applicant['account_type']);
        if ($kind==='unknown') throw new InvalidArgumentException('Review the account relationship before approval.');
        $occupied = $db->prepare("SELECT id, unit_number, account_type, is_active, is_verified FROM users WHERE role = 'resident' AND status = 'approved' AND id <> ? FOR UPDATE");
        $occupied->bind_param('i', $userId); $occupied->execute();
        $owners=[];
        foreach ($occupied->get_result()->fetch_all(MYSQLI_ASSOC) as $assignment) {
            if (normalizeUnitNumber((string)$assignment['unit_number']) === $unitNumber && residentAccountKind($assignment['account_type'])==='owner') $owners[]=$assignment;
        }
        $ownerId=null;
        if ($kind==='owner' && $owners) throw new InvalidArgumentException('This unit already has an approved unit owner.');
        if ($kind!=='owner') {
            if (count($owners)!==1 || (int)$owners[0]['is_active']!==1 || (int)$owners[0]['is_verified']!==1) throw new InvalidArgumentException('Approve one active verified unit owner before linking a tenant or occupant.');
            $ownerId=(int)$owners[0]['id'];
        }
        $approve = $db->prepare("UPDATE users SET unit_number = ?, resident_id = ?, unit_owner_id = ?, status = 'approved', rejection_reason = NULL, is_active = 1, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role = 'resident' AND status = 'pending' AND is_verified = 1");
        $approve->bind_param('ssii', $unitNumber, $unitNumber, $ownerId, $userId);
        if (!$approve->execute() || $approve->affected_rows !== 1) throw new RuntimeException('The application changed during approval.');
        $db->commit();
        $applicant['unit_number'] = $unitNumber;
        $applicant['unit_owner_id'] = $ownerId;
        $applicant['session_version'] = (int)$applicant['session_version'] + 1;
        return $applicant;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s', $lockName); $release->execute();
    }
}

/** Remove residency access and standing reservations together when a unit is vacated. */
function unassignResidentUnit(mysqli $db, int $userId, string $expectedUnit): bool {
    if (!canAccess('units.manage') || $userId < 1 || normalizeUnitNumber($expectedUnit) === '') return false;
    // Prepare schemas before starting a transaction; DDL commits in MySQL.
    if (!ensureResidentServicesTables($db) || !ensureParkingTables($db) || !ensureRememberTokensTable($db) || !ensureVehiclesTable($db) || !ensureAmenityBookingSchema($db) || !ensureAuditLogTable($db)) return false;
    $db->begin_transaction();
    try {
        $resident = $db->prepare("SELECT unit_number,account_type FROM users WHERE id = ? AND role = 'resident' FOR UPDATE");
        $resident->bind_param('i', $userId); $resident->execute();
        $current = $resident->get_result()->fetch_assoc();
        if (!$current || normalizeUnitNumber((string)$current['unit_number']) !== normalizeUnitNumber($expectedUnit)) {
            $db->rollback(); return false;
        }
        $revokeIds=[$userId];
        if (residentAccountKind($current['account_type'])==='owner') {
            $linked=$db->prepare("SELECT id,account_type FROM users WHERE unit_owner_id=? AND role='resident' FOR UPDATE");
            $linked->bind_param('i',$userId); $linked->execute();
            foreach($linked->get_result() as $occupant) if (residentAccountKind($occupant['account_type'])!=='owner') $revokeIds[]=(int)$occupant['id'];
        }
        $update = $db->prepare("UPDATE users SET unit_number = NULL, resident_id = NULL, unit_owner_id=NULL, status = 'pending', rejection_reason = NULL, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role = 'resident'");
        $revocations=[
            'DELETE FROM remember_tokens WHERE user_id = ?',
            "UPDATE resident_service_requests SET status = 'cancelled' WHERE user_id = ? AND status IN ('pending','approved')",
            "UPDATE parking_requests SET status = 'cancelled' WHERE user_id = ? AND status IN ('pending','approved')",
            "UPDATE bookings SET status = 'cancelled' WHERE user_id = ? AND booking_date >= CURRENT_DATE() AND status IN ('pending','confirmed')",
            "UPDATE parking_slots SET status = IF(status = 'maintenance', 'maintenance', 'available'), assigned_user_id = NULL, assigned_unit = NULL, vehicle_plate = NULL WHERE assigned_user_id = ?",
            'UPDATE vehicles SET parking_slot_id = NULL WHERE user_id = ?',
        ];
        foreach($revokeIds as $revokeId) {
            $update->bind_param('i',$revokeId);
            if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('Resident assignment changed.');
            foreach ($revocations as $sql) {
                $statement = $db->prepare($sql); $statement->bind_param('i', $revokeId);
                if (!$statement->execute()) throw new RuntimeException('Residency access could not be revoked.');
            }
        }
        if (!logAudit('unassign','user',$userId,'Removed residency for '.count($revokeIds).' account(s) from unit '.normalizeUnitNumber($expectedUnit).'; financial history retained.',$db)) throw new RuntimeException('Residency removal could not be audited.');
        $db->commit();
        return true;
    } catch (Throwable $error) {
        $db->rollback(); error_log('Unit vacancy failed: ' . $error->getMessage()); return false;
    }
}
