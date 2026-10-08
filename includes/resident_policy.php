<?php
/** Resident relationships are separate from staff roles; signup cannot grant ownership. */
function residentAccountKind(?string $type): string {
    return match (strtolower(trim((string)$type))) {
        '', 'resident owner' => 'owner', // Existing single-account units remain compatible.
        'tenant' => 'tenant',
        'family/relative of the owner', 'friend of owner' => 'occupant',
        default => 'unknown',
    };
}

/** Pure migration plan; ambiguous owners and existing links are never guessed. */
function residentOwnerLinkPlan(array $accounts): array {
    $rows=array_values(array_filter($accounts,static fn(array $row):bool=>($row['role'] ?? '')==='resident' && (array_key_exists('status',$row) ? $row['status'] : 'approved')==='approved'));
    $owners=[];
    foreach ($rows as $row) {
        $unit=normalizeUnitNumber($row['unit_number'] ?? null);
        if ($unit!=='' && residentAccountKind($row['account_type'] ?? null)==='owner' && (int)(array_key_exists('is_active',$row) ? $row['is_active'] : 1)===1 && (int)($row['is_verified'] ?? 0)===1) $owners[$unit][]=(int)$row['id'];
    }
    $links=[];
    foreach ($rows as $row) {
        $unit=normalizeUnitNumber($row['unit_number'] ?? null);
        if (in_array(residentAccountKind($row['account_type'] ?? null),['tenant','occupant'],true) && ($row['unit_owner_id'] ?? null)===null && count($owners[$unit] ?? [])===1) $links[(int)$row['id']]=$owners[$unit][0];
    }
    return $links;
}

function ensureResidentAccountSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    $column=$db->query("SHOW COLUMNS FROM users LIKE 'unit_owner_id'");
    if (!$column->num_rows && !$db->query('ALTER TABLE users ADD unit_owner_id INT DEFAULT NULL')) return false;
    $index=$db->query("SHOW INDEX FROM users WHERE Key_name='ix_users_unit_owner'");
    if (!$index->num_rows && !$db->query('ALTER TABLE users ADD INDEX ix_users_unit_owner (unit_owner_id)')) return false;
    // Backfill only in an explicit migration, never by guessing ownership on a read.
    if (PHP_SAPI==='cli' && appSetting('CONDO_MIGRATION_MODE')==='1') {
        $rows=$db->query("SELECT id,role,status,account_type,unit_number,unit_owner_id,is_active,is_verified FROM users WHERE role='resident' AND status='approved'")->fetch_all(MYSQLI_ASSOC);
        $link=$db->prepare('UPDATE users SET unit_owner_id=?,session_version=session_version+1 WHERE id=? AND unit_owner_id IS NULL');
        foreach(residentOwnerLinkPlan($rows) as $id=>$owner) {
            $link->bind_param('ii',$owner,$id); $link->execute();
        }
    }
    return true;
}

/** Always read identity, approved relationship and matching unit from storage. */
function residentContext(mysqli $db,int $userId): ?array {
    if ($userId<1) return null;
    $find=$db->prepare("SELECT id,role,account_type,unit_number,unit_owner_id,status,is_active,is_verified,session_version FROM users WHERE id=?");
    $find->bind_param('i',$userId); $find->execute(); $row=$find->get_result()->fetch_assoc();
    if (!$row || $row['role']!=='resident') return null;
    $row['id']=(int)$row['id'];
    $row['account_kind']=residentAccountKind($row['account_type']);
    $row['unit_number']=normalizeUnitNumber($row['unit_number']);
    $row['unit_owner_id']=$row['account_kind']==='owner' ? $userId : ($row['unit_owner_id']===null ? null : (int)$row['unit_owner_id']);
    $row['billing_user_id']=null;
    $row['approved']=(int)$row['is_active']===1 && (int)$row['is_verified']===1 && $row['status']==='approved' && $row['unit_number']!=='';
    if ($row['account_kind']==='owner') {
        $row['billing_user_id']=$userId;
    } elseif(in_array($row['account_kind'],['tenant','occupant'],true)) {
        $ownerId=(int)$row['unit_owner_id'];
        if ($ownerId===$userId || $ownerId<1) { $row['approved']=false; return $row; }
        $find->bind_param('i',$ownerId); $find->execute(); $owner=$find->get_result()->fetch_assoc();
        if (!$owner || $owner['role']!=='resident' || residentAccountKind($owner['account_type'])!=='owner' || $owner['status']!=='approved' || (int)$owner['is_active']!==1 || (int)$owner['is_verified']!==1 || normalizeUnitNumber($owner['unit_number'])!==$row['unit_number']) $row['approved']=false;
        else $row['billing_user_id']=$ownerId;
    } else $row['approved']=false;
    return $row;
}

function residentUserHasPermission(mysqli $db,int $userId,string $capability): bool {
    $context=residentContext($db,$userId);
    if (!$context) return false;
    if ($capability==='resident.profile.edit') return (int)$context['is_active']===1 && (int)$context['is_verified']===1;
    if (!$context['approved']) return false;
    if ($capability==='resident.stickers.order' && $context['account_kind']==='tenant') return appSetting('CONDO_TENANT_PARKING','1')==='1';
    $permissions=[
        'resident.portal'=>null, 'resident.billing.view'=>null, 'resident.billing.pay'=>'owner',
        'resident.announcements.view'=>null, 'resident.violations.view'=>null,
        'resident.amenities.book'=>'CONDO_TENANT_AMENITIES', 'resident.maintenance.request'=>'CONDO_TENANT_MAINTENANCE',
        'resident.messages.use'=>'CONDO_TENANT_MESSAGES', 'resident.visitors.register'=>'CONDO_TENANT_VISITORS',
        'resident.parking.request'=>'CONDO_TENANT_PARKING', 'resident.vehicles.register'=>'CONDO_TENANT_VEHICLES',
        'resident.stickers.order'=>'owner', 'resident.permits.request'=>'CONDO_TENANT_PERMITS',
    ];
    if (!array_key_exists($capability,$permissions)) return false;
    if ($context['account_kind']==='owner' || $permissions[$capability]===null) return true;
    if ($permissions[$capability]==='owner') return false;
    return appSetting($permissions[$capability],$capability==='resident.permits.request' ? '0' : '1')==='1';
}

function residentHasPermission(string $capability): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? '')==='resident' && residentUserHasPermission(connectDb(),(int)$_SESSION['user_id'],$capability);
}

function requireResidentPermission(string $capability): void {
    if (!isLoggedIn()) redirect(buildUrl('login.php'));
    if (residentHasPermission($capability)) return;
    http_response_code(403); exit('This service is not available for your resident account.');
}

function residentAccountLabel(): string {
    if (!isLoggedIn() || ($_SESSION['role'] ?? '')!=='resident') return 'Resident';
    $context=residentContext(connectDb(),(int)$_SESSION['user_id']);
    return match($context['account_kind'] ?? '') { 'owner'=>'Unit Owner', 'tenant'=>'Tenant', 'occupant'=>'Authorized Occupant', default=>'Resident' };
}

/** Explicit owner links scope bills; same-unit text never grants access. */
function residentBillingUserIds(mysqli $db,int $actorId): array {
    $actor=residentContext($db,$actorId);
    if (!$actor || !$actor['approved']) return [];
    if ($actor['account_kind']!=='owner') return array_values(array_unique([$actorId,(int)$actor['billing_user_id']]));
    $ids=[$actorId];
    $find=$db->prepare("SELECT id FROM users WHERE unit_owner_id=? AND role='resident' AND status='approved' AND is_active=1 AND is_verified=1");
    $find->bind_param('i',$actorId); $find->execute();
    foreach($find->get_result() as $row) {
        $occupant=residentContext($db,(int)$row['id']);
        if ($occupant && $occupant['approved'] && $occupant['unit_number']===$actor['unit_number'] && in_array($occupant['account_kind'],['tenant','occupant'],true)) $ids[]=(int)$row['id'];
    }
    return $ids;
}

function residentCanPayBill(mysqli $db,int $actorId,int $billUserId,?int $paymentId=null): bool {
    $actor=residentContext($db,$actorId);
    if (!$actor || !$actor['approved']) return false;
    if ($paymentId!==null) {
        $find=$db->prepare('SELECT user_id,billing_scope FROM payments WHERE id=?');
        $find->bind_param('i',$paymentId); $find->execute(); $bill=$find->get_result()->fetch_assoc();
        if (!$bill || (int)$bill['user_id']!==$billUserId) return false;
        if ($bill['billing_scope']==='personal_parking') return $actor['account_kind']==='tenant' && $actorId===$billUserId;
    }
    return $actor && $actor['approved'] && $actor['account_kind']==='owner' && in_array($billUserId,residentBillingUserIds($db,$actorId),true);
}
