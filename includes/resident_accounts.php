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
        $applicantQuery = $db->prepare("SELECT full_name, email, account_type, session_version FROM users WHERE id = ? AND role = 'resident' AND status = 'pending' AND is_verified = 1 AND is_active=1 AND archived_at IS NULL FOR UPDATE");
        $applicantQuery->bind_param('i', $userId); $applicantQuery->execute();
        $applicant = $applicantQuery->get_result()->fetch_assoc();
        if (!$applicant) throw new InvalidArgumentException('This registration must be verified and pending before approval.');
        $kind=residentAccountKind($applicant['account_type']);
        if ($kind==='unknown') throw new InvalidArgumentException('Review the account relationship before approval.');
        $occupied = $db->prepare("SELECT id, unit_number, account_type, is_active, is_verified FROM users WHERE role = 'resident' AND status = 'approved' AND is_active=1 AND archived_at IS NULL AND id <> ? FOR UPDATE");
        $occupied->bind_param('i', $userId); $occupied->execute();
        $owners=[];
        foreach ($occupied->get_result()->fetch_all(MYSQLI_ASSOC) as $assignment) {
            if (normalizeUnitNumber((string)$assignment['unit_number']) === $unitNumber && residentAccountKind($assignment['account_type'])==='owner') $owners[]=$assignment;
        }
        foreach(lifecycleQuery($db,"SELECT u.id,r.unit_number,u.account_type,u.is_active,u.is_verified FROM account_unit_relationships r JOIN users u ON u.id=r.user_id WHERE r.unit_number=? AND r.relationship_kind='owner' AND r.ended_at IS NULL AND u.is_active=1 AND u.archived_at IS NULL AND u.id<>? FOR UPDATE",[$unitNumber,$userId])->get_result() as $assignment) if(!in_array($assignment['id'],array_column($owners,'id')))$owners[]=$assignment;
        $ownerId=null;
        if ($kind==='owner' && $owners) throw new InvalidArgumentException('This unit already has an approved unit owner.');
        if ($kind!=='owner') {
            if (count($owners)!==1 || (int)$owners[0]['is_active']!==1 || (int)$owners[0]['is_verified']!==1) throw new InvalidArgumentException('Approve one active verified unit owner before linking a tenant or occupant.');
            $ownerId=(int)$owners[0]['id'];
        }
        $approve = $db->prepare("UPDATE users SET unit_number = ?, resident_id = ?, unit_owner_id = ?, status = 'approved', rejection_reason = NULL, is_active = 1, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1 WHERE id = ? AND role = 'resident' AND status = 'pending' AND is_verified = 1 AND is_active=1 AND archived_at IS NULL");
        $approve->bind_param('ssii', $unitNumber, $unitNumber, $ownerId, $userId);
        if (!$approve->execute() || $approve->affected_rows !== 1) throw new RuntimeException('The application changed during approval.');
        lifecycleQuery($db,'INSERT INTO account_unit_relationships(user_id,unit_number,relationship_kind,owner_id,started_at,recorded_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$userId,$unitNumber,$kind,$kind==='owner'?$userId:$ownerId]);
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

/** Compatibility entry point: unconfirmed legacy calls cannot archive an account. */
function unassignResidentUnit(mysqli $db,int $userId,string $expectedUnit,array $confirmation=[]): bool {
    if (!$confirmation) return false;
    try { archiveApply($db,array_replace($confirmation,['user_id'=>$userId,'unit_number'=>$expectedUnit,'mode'=>'end_occupancy']));return true; }
    catch (Throwable $error) { error_log('Confirmed occupancy end failed: '.get_class($error));return false; }
}
