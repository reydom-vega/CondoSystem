<?php
/**
 * Visitor / guest log feature module.
 * Lets security staff log a visitor in at the gate/lobby and check them
 * out later. Follows the same self-contained pattern as parking.php and
 * violations.php: one ensureXTable() bootstrap function plus small,
 * focused helpers that pages call directly.
 */

function ensureVisitorLogsTable(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    if (!$connection->query("CREATE TABLE IF NOT EXISTS visitor_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visitor_name VARCHAR(120) NOT NULL,
        visitor_contact VARCHAR(30) DEFAULT NULL,
        visitor_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        unit_number VARCHAR(20) NOT NULL,
        purpose VARCHAR(150) DEFAULT NULL,
        logged_by INT NOT NULL,
        time_in DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        time_out DATETIME DEFAULT NULL,
        status ENUM('checked_in', 'checked_out') NOT NULL DEFAULT 'checked_in',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (logged_by),
        INDEX (status),
        CONSTRAINT fk_visitor_logs_user FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE CASCADE
    )")) return false;
    $countColumn = $connection->query("SHOW COLUMNS FROM visitor_logs LIKE 'visitor_count'");
    return $countColumn && ($countColumn->num_rows > 0 || $connection->query("ALTER TABLE visitor_logs ADD visitor_count SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER visitor_contact") === true);
}

/**
 * Adds the checked_out_by column to an already-existing visitor_logs
 * table (older installs won't have it yet). Safe to call on every page
 * load — only alters the table the first time the column is missing.
 * Call this right after ensureVisitorLogsTable().
 */
function ensureVisitorLogColumns(mysqli $connection): bool {
    if (!schemaMutationAllowed()) return true;
    $result = $connection->query("SHOW COLUMNS FROM visitor_logs LIKE 'checked_out_by'");
    if ($result && $result->num_rows === 0) {
        $connection->query("ALTER TABLE visitor_logs ADD COLUMN checked_out_by INT NULL AFTER logged_by, ADD INDEX (checked_out_by)");
    }
    return true;
}

/**
 * Logs a new visitor in. Returns the new row's id, or false on failure.
 */
function logVisitorIn(mysqli $connection, string $visitorName, string $visitorContact, string $unitNumber, string $purpose, int $loggedBy, int $visitorCount = 1): int|false {
    if ($visitorCount < 1 || $visitorCount > 65535) return false;
    $stmt = $connection->prepare("INSERT INTO visitor_logs (visitor_name, visitor_contact, unit_number, purpose, logged_by, visitor_count) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('ssssii', $visitorName, $visitorContact, $unitNumber, $purpose, $loggedBy, $visitorCount);
    if (!$stmt->execute()) return false;
    return $connection->insert_id;
}

/**
 * Marks a visitor as checked out (sets time_out to now) and records which
 * staff account performed the checkout.
 */
function logVisitorOut(mysqli $connection, int $visitorLogId, int $checkedOutBy): bool {
    if (!canAccess('security.gate') || $checkedOutBy !== (int)$_SESSION['user_id']) return false;
    if (!ensureResidentServicesTables($connection)) return false;
    ensureQrScanHistorySchema($connection);
    $connection->begin_transaction();
    try {
    $stmt = $connection->prepare("UPDATE visitor_logs SET status = 'checked_out', time_out = NOW(), checked_out_by = ? WHERE id = ? AND status = 'checked_in'");
    if (!$stmt) throw new RuntimeException('Visitor checkout could not be prepared.');
    $stmt->bind_param('ii', $checkedOutBy, $visitorLogId);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) { $connection->rollback(); return false; }
    $update = $connection->prepare("UPDATE resident_service_requests SET status = 'checked_out' WHERE visitor_log_id = ? AND status = 'checked_in'");
    $update->bind_param('i', $visitorLogId);
    if (!$update->execute()) throw new RuntimeException('Registration checkout could not be saved.');
    $find=$connection->prepare('SELECT id FROM resident_service_requests WHERE visitor_log_id=?'); $find->bind_param('i',$visitorLogId); $find->execute(); $registration=$find->get_result()->fetch_assoc();
    if ($registration) qrActionEvent($connection,gateRequest($connection,(int)$registration['id']),'check_out','Visitor exit confirmed.');
    $connection->commit(); return true;
    } catch (Throwable $error) { $connection->rollback(); error_log($error->getMessage()); return false; }
}

/**
 * All visitors currently inside (not yet checked out), most recent first.
 * Includes the logging staff member's name for pages that display it.
 */
function getActiveVisitors(mysqli $connection): array {
    $visitors = [];
    $result = $connection->query("SELECT v.*, lu.full_name AS logged_by_name FROM visitor_logs v LEFT JOIN users lu ON lu.id = v.logged_by WHERE v.status = 'checked_in' ORDER BY v.time_in DESC");
    if ($result) { while ($row = $result->fetch_assoc()) { $visitors[] = $row; } }
    return $visitors;
}

/**
 * Most recent visitor log entries regardless of status, for dashboard
 * activity feeds. Includes both the staff member who logged the visitor
 * in and, if applicable, the one who checked them out.
 */
function getRecentVisitors(mysqli $connection, int $limit = 5): array {
    $visitors = [];
    $stmt = $connection->prepare("SELECT v.*, lu.full_name AS logged_by_name, ou.full_name AS checked_out_by_name FROM visitor_logs v LEFT JOIN users lu ON lu.id = v.logged_by LEFT JOIN users ou ON ou.id = v.checked_out_by ORDER BY v.time_in DESC LIMIT ?");
    if (!$stmt) return $visitors;
    $stmt->bind_param('i', $limit);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $visitors[] = $row; }
    }
    return $visitors;
}

/**
 * Count of visitors currently checked in (for stat cards).
 */
function countActiveVisitors(mysqli $connection): int {
    $result = $connection->query("SELECT COALESCE(SUM(visitor_count), 0) AS total FROM visitor_logs WHERE status = 'checked_in'");
    return $result ? (int)$result->fetch_assoc()['total'] : 0;
}
