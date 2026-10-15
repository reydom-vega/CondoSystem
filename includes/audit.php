<?php
/**
 * Audit log.
 *
 * Tracks admin actions (approvals, rejections, status changes, creates,
 * deletes) so management can see who did what and when. This is kept
 * separate from the `analytics` table in config.php: analytics is
 * usage/marketing telemetry (logins, page activity), while this is an
 * accountability trail scoped to admin decisions.
 */

function ensureAuditLogTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    $created = $connection->query("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT DEFAULT NULL,
        admin_name VARCHAR(100) DEFAULT NULL,
        admin_role VARCHAR(30) DEFAULT NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NOT NULL,
        entity_id INT DEFAULT NULL,
        details VARCHAR(500) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (admin_id),
        INDEX (entity_type, entity_id),
        INDEX (created_at)
    )") === true;

    if (!$created) {
        return false;
    }

    $roleColumn = $connection->query("SHOW COLUMNS FROM audit_logs LIKE 'admin_role'");
    if ($roleColumn && $roleColumn->num_rows === 0) {
        if (!$connection->query("ALTER TABLE audit_logs ADD admin_role VARCHAR(30) DEFAULT NULL AFTER admin_name")) return false;
    }
    // No backfill: historical actor snapshots and outcomes must not be invented.
    foreach (['actor_name_snapshot'=>'VARCHAR(150) DEFAULT NULL','actor_identifier'=>'VARCHAR(30) DEFAULT NULL','actor_role_snapshot'=>'VARCHAR(30) DEFAULT NULL','outcome'=>'VARCHAR(20) DEFAULT NULL'] as $column=>$definition) {
        if (!$connection->query("SHOW COLUMNS FROM audit_logs LIKE '$column'")->num_rows && !$connection->query("ALTER TABLE audit_logs ADD $column $definition")) return false;
    }
    foreach (['ix_audit_period'=>'created_at,id','ix_audit_actor_period'=>'admin_id,created_at','ix_audit_module_period'=>'entity_type,created_at','ix_audit_role_period'=>'actor_role_snapshot,created_at','ix_audit_outcome_period'=>'outcome,created_at'] as $name=>$columns) {
        if (!$connection->query("SHOW INDEX FROM audit_logs WHERE Key_name='$name'")->num_rows && !$connection->query("ALTER TABLE audit_logs ADD INDEX $name ($columns)")) return false;
    }
    return true;
}

/**
 * Records one admin action. Call this right after any approve / reject /
 * status-change / create / delete action goes through.
 *
 * $action is a short machine-readable verb, e.g. 'approve', 'reject',
 * 'create', 'update', 'delete', 'mark_paid'. $entityType is the kind of
 * record affected, e.g. 'booking', 'maintenance_request', 'payment',
 * 'announcement', 'parking_request'. $details is a short human-readable
 * note shown in the log table.
 */
function logAudit(string $action, string $entityType, ?int $entityId = null, string $details = '', ?mysqli $connection = null, ?string $outcome = null, bool $automated = false): bool {
    $ownsConnection = $connection === null;
    try {
    $connection ??= connectDb();
    if ($ownsConnection) ensureAuditLogTable($connection);

    $adminId = $automated ? null : ($_SESSION['user_id'] ?? null);
    $adminName = $automated ? 'System' : ($_SESSION['username'] ?? 'System');
    $adminRole = $automated ? 'system' : ($_SESSION['role'] ?? null);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($outcome !== null && !in_array($outcome,['success','failed','refused'],true)) throw new InvalidArgumentException('Invalid audit outcome.');
    $name=$adminName; $identifier=$adminId ? 'USER-'.$adminId : 'System'; $role=$adminRole ?: 'system';
    if ($adminId) {
        $actor=$connection->prepare('SELECT full_name,staff_id,role,account_type FROM users WHERE id=?');$actor->bind_param('i',$adminId);$actor->execute();$user=$actor->get_result()->fetch_assoc();
        if ($user) {$name=$user['full_name'] ?: $adminName;$identifier=$user['staff_id'] ?: 'USER-'.$adminId;$role=$user['role']==='resident' && $user['account_type']==='Tenant' ? 'tenant' : $user['role'];}
    }
    $stmt = $connection->prepare('INSERT INTO audit_logs (admin_id, admin_name, admin_role, action, entity_type, entity_id, details, ip_address,actor_name_snapshot,actor_identifier,actor_role_snapshot,outcome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) return false;
    $stmt->bind_param('issssissssss', $adminId, $adminName, $adminRole, $action, $entityType, $entityId, $details, $ip,$name,$identifier,$role,$outcome);
    return $stmt->execute();
    } catch (Throwable $error) {
        if (!$ownsConnection) throw $error;
        // A committed operation must not be presented as failed if logging is unavailable.
        error_log('Audit storage unavailable: action=' . $action . ', entity=' . $entityType . ', id=' . (int)$entityId);
        return false;
    } finally {
        if ($ownsConnection && $connection instanceof mysqli) $connection->close();
    }
}

/**
 * Legacy internal helper retained for compatibility. The global Audit Log uses
 * includes/audit_report.php for authorized SQL filtering and safe projections.
 */
function getAuditLogs(int $limit = 500): array {
    $connection = connectDb();
    ensureAuditLogTable($connection);
    $result = $connection->query("SELECT audit_logs.*, COALESCE(audit_logs.admin_role, users.role) AS resolved_role, COALESCE(users.staff_id, users.resident_id, audit_logs.admin_name) AS actor_id FROM audit_logs LEFT JOIN users ON users.id = audit_logs.admin_id ORDER BY audit_logs.created_at DESC LIMIT " . (int)$limit);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
