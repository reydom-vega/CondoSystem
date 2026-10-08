<?php
function ensureAuthenticationSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    return $db->query("CREATE TABLE IF NOT EXISTS authentication_limits (
        bucket CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL, INDEX (window_started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}

/** Limit anonymous authentication requests by the server-observed client address. */
function allowAuthenticationRequest(mysqli $db, string $purpose, int $limit = 20): bool {
    ensureAuthenticationSchema($db);
    $bucket = hash('sha256', $purpose . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    $stmt = $db->prepare("INSERT INTO authentication_limits (bucket, attempts, window_started_at) VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, attempts + 1),
        window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_started_at)");
    $stmt->bind_param('s', $bucket);
    $stmt->execute();
    $stmt = $db->prepare('SELECT attempts FROM authentication_limits WHERE bucket = ?');
    $stmt->bind_param('s', $bucket);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['attempts'] <= $limit;
}

function authenticateCredentials(mysqli $db, string $identifier, string $password): ?array {
    if (!allowAuthenticationRequest($db, 'login')) return null;
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT id, username, email, unit_number, password_hash, failed_login_attempts, locked_until, is_verified, is_active, session_version, role FROM users WHERE username = ? OR email = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $validPassword = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        if (!$user) { $db->rollback(); return null; }
        $locked = !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
        if ($locked || (int)$user['is_active'] !== 1 || (int)$user['is_verified'] !== 1) { $db->rollback(); return null; }
        if (!$validPassword) {
            $attempts = !empty($user['locked_until']) ? 1 : (int)$user['failed_login_attempts'] + 1;
            $until = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            $stmt = $db->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
            $stmt->bind_param('isi', $attempts, $until, $user['id']);
            $stmt->execute();
            $db->commit();
            return null;
        }
        $stmt = $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_seen_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $user['id']);
            $stmt->execute();
        }
        $db->commit();
        unset($user['password_hash']);
        return $user;
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}

function passwordResetDigest(string $token): string {
    return 'sha256:' . hash('sha256', $token);
}

function issuePasswordResetToken(mysqli $db, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $digest = passwordResetDigest($token);
    $stmt = $db->prepare('UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ? AND is_active = 1');
    $stmt->bind_param('si', $digest, $userId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new RuntimeException('This account cannot request a password reset.');
    return $token;
}

function findPasswordResetUser(mysqli $db, string $token): ?array {
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;
    $digest = passwordResetDigest($token);
    $stmt = $db->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $digest);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function completePasswordReset(mysqli $db, string $token, string $password): bool {
    if (!isPasswordStrong($password) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) return false;
    ensureRememberTokensTable($db);
    $db->begin_transaction();
    try {
        $digest = passwordResetDigest($token);
        $stmt = $db->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() AND is_active = 1 LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $digest);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) { $db->rollback(); return false; }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL, session_version = session_version + 1, failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
        $stmt->bind_param('si', $hash, $user['id']);
        $stmt->execute();
        $stmt = $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $db->commit();
        logAudit('password_reset', 'authentication', (int)$user['id'], 'Password reset; existing sessions revoked');
        return true;
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}
