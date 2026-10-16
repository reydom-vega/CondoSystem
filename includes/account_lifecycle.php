<?php
/** Account access, physical occupancy and legal ownership are separate states. */
function ensureAccountArchiveSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    $columns = [
        'users'=>['archived_at'=>'DATETIME(6) DEFAULT NULL','archived_by'=>'INT DEFAULT NULL','archive_reason'=>'VARCHAR(1000) DEFAULT NULL'],
        'vehicles'=>['authorization_revoked_at'=>'DATETIME(6) DEFAULT NULL','authorization_revocation_reason'=>'VARCHAR(1000) DEFAULT NULL','unit_number_snapshot'=>'VARCHAR(30) DEFAULT NULL'],
        'payments'=>['unit_number_snapshot'=>'VARCHAR(30) DEFAULT NULL'],
        'maintenance_requests'=>['unit_number_snapshot'=>'VARCHAR(30) DEFAULT NULL'],
        'resident_service_requests'=>['unit_number_snapshot'=>'VARCHAR(30) DEFAULT NULL'],
        'parking_sticker_orders'=>['authorization_revoked_at'=>'DATETIME(6) DEFAULT NULL','authorization_revocation_reason'=>'VARCHAR(1000) DEFAULT NULL'],
        'parking_sticker_vehicles'=>['revoked_at'=>'DATETIME(6) DEFAULT NULL','revocation_reason'=>'VARCHAR(1000) DEFAULT NULL'],
    ];
    foreach ($columns as $table=>$fields) {
        if (!$db->query("SHOW TABLES LIKE '$table'")->num_rows) continue;
        foreach ($fields as $name=>$definition) if (!$db->query("SHOW COLUMNS FROM `$table` LIKE '$name'")->num_rows) $db->query("ALTER TABLE `$table` ADD `$name` $definition");
    }
    $db->query("CREATE TABLE IF NOT EXISTS account_unit_relationships (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        unit_number VARCHAR(30) NOT NULL, relationship_kind ENUM('owner','tenant','occupant') NOT NULL,
        owner_id INT DEFAULT NULL, started_at DATETIME(6) DEFAULT NULL,
        recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        occupancy_ended_at DATETIME(6) DEFAULT NULL, ended_at DATETIME(6) DEFAULT NULL,
        ended_by INT DEFAULT NULL, end_reason VARCHAR(1000) DEFAULT NULL,
        active_unit VARCHAR(30) GENERATED ALWAYS AS (IF(ended_at IS NULL,unit_number,NULL)) STORED,
        active_owner_unit VARCHAR(30) GENERATED ALWAYS AS (IF(ended_at IS NULL AND relationship_kind='owner',unit_number,NULL)) STORED,
        UNIQUE KEY ux_relationship_active(user_id,active_unit), UNIQUE KEY ux_relationship_owner(active_owner_unit),
        INDEX ix_relationship_unit(unit_number,ended_at,occupancy_ended_at),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS account_archive_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        relationship_id BIGINT UNSIGNED DEFAULT NULL, category ENUM('residents','tenants','staff') NOT NULL,
        event_kind ENUM('archive','occupancy_ended','restore') NOT NULL,
        account_archived TINYINT(1) NOT NULL DEFAULT 0, former_unit VARCHAR(30) DEFAULT NULL,
        former_role VARCHAR(30) NOT NULL, reason_code VARCHAR(60) NOT NULL, reason VARCHAR(1000) NOT NULL,
        actor_id INT NOT NULL, actor_name VARCHAR(150) NOT NULL,
        request_key CHAR(64) NOT NULL UNIQUE, snapshot MEDIUMTEXT NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX ix_archive_category(category,created_at,id), INDEX ix_archive_user(user_id,id),
        INDEX ix_archive_unit(former_unit,created_at), INDEX ix_archive_actor(actor_id,created_at),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY(relationship_id) REFERENCES account_unit_relationships(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['vehicles','payments','maintenance_requests','resident_service_requests'] as $table) {
        if (!$db->query("SHOW TABLES LIKE '$table'")->num_rows) continue;
        // A migration's unit-label backfill is not a new workflow event. Keep
        // historical modification dates, including ON UPDATE timestamps.
        $preserveUpdated=$db->query("SHOW COLUMNS FROM `$table` LIKE 'updated_at'")->num_rows ? ',r.updated_at=r.updated_at' : '';
        $db->query("UPDATE `$table` r JOIN users u ON u.id=r.user_id SET r.unit_number_snapshot=u.unit_number$preserveUpdated WHERE r.unit_number_snapshot IS NULL AND u.unit_number IS NOT NULL");
    }
    // Legacy start dates were not recorded. Do not invent an occupancy date.
    // Ambiguous ownership is reported for manual review instead of guessed.
    foreach ($db->query("SELECT id,role,account_type,unit_number,unit_owner_id,status,is_active,archived_at FROM users WHERE role='resident' AND status='approved' AND is_active=1 AND archived_at IS NULL AND TRIM(COALESCE(unit_number,''))<>'' ORDER BY id") as $row) {
        archiveEnsureLegacyRelationship($db,$row);
    }
    return true;
}

/** Bind parameters consistently without constructing SQL from user input. */
function lifecycleQuery(mysqli $db,string $sql,array $parameters=[]): mysqli_stmt {
    $stmt=$db->prepare($sql);
    if ($parameters) { $values=array_values($parameters); $stmt->bind_param(str_repeat('s',count($values)),...$values); }
    $stmt->execute(); return $stmt;
}

function activeAccountSql(string $alias='u',bool $verified=true): string {
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D',$alias)) throw new LogicException('Invalid account alias.');
    return "$alias.is_active=1 AND $alias.archived_at IS NULL".($verified?" AND $alias.is_verified=1":'');
}

function accountRoutineEligible(mysqli $db,int $userId,bool $accountNotice=false): bool {
    $row=lifecycleQuery($db,'SELECT role,status,is_active,is_verified,archived_at FROM users WHERE id=?',[$userId])->get_result()->fetch_assoc();
    return $row && (int)$row['is_active']===1 && $row['archived_at']===null
        && ($accountNotice || ((int)$row['is_verified']===1 && ($row['role']!=='resident' || $row['status']==='approved')));
}

/** Raw mail entry points also recheck an address belonging to an account. */
function accountRecipientEligible(mysqli $db,string $recipient,string $channel='email'): bool {
    if ($channel==='email') $rows=lifecycleQuery($db,'SELECT id,is_active,archived_at FROM users WHERE email=?',[$recipient])->get_result()->fetch_all(MYSQLI_ASSOC);
    else $rows=lifecycleQuery($db,"SELECT id,is_active,archived_at FROM users WHERE REPLACE(REPLACE(REPLACE(contact_number,'+',''),' ',''),'-','') IN (?,?)",[normalizePhDigits($recipient),'0'.substr(normalizePhDigits($recipient),2)])->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return true; // External oversight / anonymous case contacts are not user accounts.
    foreach ($rows as $row) if ((int)$row['is_active']===1 && $row['archived_at']===null) return true;
    return false;
}

function archiveEnsureLegacyRelationship(mysqli $db,array $user): void {
    $unit=normalizeUnitNumber($user['unit_number'] ?? ''); $kind=residentAccountKind($user['account_type'] ?? null);
    if (($user['role']??'')!=='resident' || ($user['status']??'')!=='approved' || (int)($user['is_active']??0)!==1 || !empty($user['archived_at']) || $unit==='' || $kind==='unknown') return;
    if (lifecycleQuery($db,'SELECT id FROM account_unit_relationships WHERE user_id=? AND unit_number=? LIMIT 1',[$user['id'],$unit])->get_result()->num_rows) {
        // The existing owner-link migration can run after the first schema pass.
        // Carry its explicit link into a still-active imported period only.
        if($kind!=='owner' && !empty($user['unit_owner_id'])) lifecycleQuery($db,'UPDATE account_unit_relationships SET owner_id=? WHERE user_id=? AND unit_number=? AND owner_id IS NULL AND started_at IS NULL AND ended_at IS NULL',[$user['unit_owner_id'],$user['id'],$unit]);
        return;
    }
    if ($kind==='owner') {
        // Never choose an owner by row order when legacy assignments conflict.
        foreach ($db->query("SELECT id,unit_number,account_type FROM users WHERE role='resident' AND status='approved' AND is_active=1 AND archived_at IS NULL") as $other) {
            if ((int)$other['id']!==(int)$user['id'] && normalizeUnitNumber($other['unit_number'])===$unit && residentAccountKind($other['account_type'])==='owner') return;
        }
        if (lifecycleQuery($db,"SELECT id FROM account_unit_relationships WHERE active_owner_unit=? LIMIT 1",[$unit])->get_result()->num_rows) return;
    }
    lifecycleQuery($db,'INSERT INTO account_unit_relationships(user_id,unit_number,relationship_kind,owner_id,recorded_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$user['id'],$unit,$kind,$kind==='owner'?$user['id']:($user['unit_owner_id']??null)]);
}

function accountOccupiesUnit(mysqli $db,int $id,string $unit): bool {
    $rows=lifecycleQuery($db,'SELECT ended_at,occupancy_ended_at FROM account_unit_relationships WHERE user_id=? AND unit_number=?',[$id,normalizeUnitNumber($unit)])->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return true; // Existing fixtures / unmigrated approved assignments retain behavior.
    foreach ($rows as $row) if ($row['ended_at']===null && $row['occupancy_ended_at']===null) return true;
    return false;
}

function accountHasUnitEntitlement(mysqli $db,int $id,string $unit): bool {
    $rows=lifecycleQuery($db,'SELECT ended_at FROM account_unit_relationships WHERE user_id=? AND unit_number=?',[$id,normalizeUnitNumber($unit)])->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return true;
    foreach ($rows as $row) if ($row['ended_at']===null) return true;
    return false;
}

/** Freeze the original unit before changing an account's primary relationship. */
function recordAccountUnitSnapshots(mysqli $db,int $id,string $unit): void {
    foreach (['vehicles','payments','maintenance_requests','resident_service_requests'] as $table) lifecycleQuery($db,"UPDATE `$table` SET unit_number_snapshot=? WHERE user_id=? AND unit_number_snapshot IS NULL",[$unit,$id]);
}

/** Do not infer ownership from physical presence. Includes legacy fixture assignments. */
function activeUnitPopulation(mysqli $db): array {
    $result=[];
    foreach ($db->query("SELECT id,full_name,unit_number,account_type FROM users WHERE role='resident' AND status='approved' AND ".activeAccountSql('users')." AND TRIM(COALESCE(unit_number,''))<>''") as $row) {
        if ((residentContext($db,(int)$row['id'])['approved']??false) && accountOccupiesUnit($db,(int)$row['id'],$row['unit_number'])) $result[normalizeUnitNumber($row['unit_number'])][]=$row;
    }
    foreach ($db->query("SELECT u.id,u.full_name,r.unit_number,u.account_type FROM account_unit_relationships r JOIN users u ON u.id=r.user_id WHERE r.ended_at IS NULL AND r.occupancy_ended_at IS NULL AND u.status='approved' AND ".activeAccountSql()) as $row) {
        if (!(residentContext($db,(int)$row['id'])['approved']??false)) continue;
        $unit=normalizeUnitNumber($row['unit_number']);$ids=array_column($result[$unit]??[],'id');
        if (!in_array($row['id'],$ids)) $result[$unit][]=$row;
    }
    return $result;
}
