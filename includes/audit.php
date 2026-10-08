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
        return $connection->query("ALTER TABLE audit_logs ADD admin_role VARCHAR(30) DEFAULT NULL AFTER admin_name") === true;
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
function logAudit(string $action, string $entityType, ?int $entityId = null, string $details = '', ?mysqli $connection = null): bool {
    $ownsConnection = $connection === null;
    try {
    $connection ??= connectDb();
    if ($ownsConnection) ensureAuditLogTable($connection);

    $adminId = $_SESSION['user_id'] ?? null;
    $adminName = $_SESSION['username'] ?? 'System';
    $adminRole = $_SESSION['role'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $connection->prepare('INSERT INTO audit_logs (admin_id, admin_name, admin_role, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) return false;
    $stmt->bind_param('issssiss', $adminId, $adminName, $adminRole, $action, $entityType, $entityId, $details, $ip);
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
 * Most recent audit log entries. Filtering by action/entity/superadmin/date is
 * done in PHP by the caller (see superadmin/auditlog.php), matching how the
 * rest of the admin pages (bookingrequest.php, unitpayments.php) filter
 * an already-fetched result set rather than building dynamic SQL.
 */
function getAuditLogs(int $limit = 500): array {
    $connection = connectDb();
    ensureAuditLogTable($connection);
    $result = $connection->query("SELECT audit_logs.*, COALESCE(audit_logs.admin_role, users.role) AS resolved_role, COALESCE(users.staff_id, users.resident_id, audit_logs.admin_name) AS actor_id FROM audit_logs LEFT JOIN users ON users.id = audit_logs.admin_id ORDER BY audit_logs.created_at DESC LIMIT " . (int)$limit);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
