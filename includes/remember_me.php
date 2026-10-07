<?php
/**
 * Persistent login ("Remember Me").
 *
 * This replaces the old login.php behavior, which stored the user's
 * password in a cookie encoded with base64 (not encryption — trivially
 * reversible) for 30 days, and only used it to pre-fill the password
 * field. That neither kept anyone logged in nor was safe to leave in a
 * browser's cookie jar, so it has been removed.
 *
 * This version issues a random selector/validator token pair. Only a
 * hash of the validator is stored in the database (so a stolen database
 * dump alone cannot be replayed), and the token is rotated on every
 * successful auto-login (so a stolen-but-already-used cookie stops
 * working). The cookie survives closing the browser because it carries
 * its own expiry, independent of the PHP session cookie.
 */

const REMEMBER_COOKIE_NAME = 'remember_token';
const REMEMBER_DURATION_DAYS = 30;

function ensureRememberTokensTable(mysqli $connection): bool {
    return $connection->query("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(24) NOT NULL UNIQUE,
        validator_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (expires_at),
        CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )") === true;
}

/**
 * Issues a fresh remember-me token for a user and sets the cookie. Call
 * this on successful login when "Remember Me" is checked, and again
 * (internally) each time an existing token is used to auto-login.
 */
function issueRememberToken(int $userId): void {
    $connection = connectDb();
    if (!ensureRememberTokensTable($connection)) {
        error_log('Remember Me: Failed to create/verify remember_tokens table');
        return;
    }

    // Clean up any old tokens for this user first (optional but good practice)
    $deleteOld = $connection->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
    if ($deleteOld) {
        $deleteOld->bind_param('i', $userId);
        $deleteOld->execute();
        $deleteOld->close();
    }

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);
    $expiresAt = (new DateTime())->modify('+' . REMEMBER_DURATION_DAYS . ' days')->format('Y-m-d H:i:s');

    $stmt = $connection->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        error_log('Remember Me: Prepare failed: ' . $connection->error);
        return;
    }
    
    $stmt->bind_param('isss', $userId, $selector, $validatorHash, $expiresAt);
    if (!$stmt->execute()) {
        error_log('Remember Me: Execute failed: ' . $stmt->error);
        $stmt->close();
        return;
    }
    $stmt->close();

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    $setCookieResult = setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, [
        'expires'  => time() + (86400 * REMEMBER_DURATION_DAYS),
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!$setCookieResult) {
        error_log('Remember Me: Failed to set cookie (output may have been sent)');
    }
}

/**
 * Called once per request from config.php, before any output. If the
 * visitor has no active session but presents a valid remember-me cookie,
 * this restores their session so they stay logged in after closing the
 * browser or after the 15-minute inactivity timeout — without needing to
 * keep re-entering a username and password.
 */
function attemptAutoLogin(): void {
    if (isLoggedIn() || empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        return;
    }

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        error_log('Remember Me: Cookie format invalid');
        forgetRememberToken();
        return;
    }
    [$selector, $validator] = $parts;

    $connection = connectDb();
    if (!ensureRememberTokensTable($connection)) {
        error_log('Remember Me: Failed to create/verify remember_tokens table in auto-login');
        return;
    }

    $stmt = $connection->prepare('SELECT rt.id, rt.user_id, rt.validator_hash, rt.expires_at, u.username, u.unit_number, u.role, u.is_verified, u.is_active, u.session_version, u.locked_until FROM remember_tokens rt INNER JOIN users u ON u.id = rt.user_id WHERE rt.selector = ? LIMIT 1');
    if (!$stmt) {
        error_log('Remember Me: Prepare failed in auto-login: ' . $connection->error);
        return;
    }
    
    $stmt->bind_param('s', $selector);
    if (!$stmt->execute()) {
        error_log('Remember Me: Execute failed in auto-login: ' . $stmt->error);
        $stmt->close();
        return;
    }
    
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        error_log('Remember Me: Token selector not found in database');
        forgetRememberToken();
        return;
    }

    $lockedUntil = $row['locked_until'] ? new DateTime($row['locked_until']) : null;
    $validatorMatch = hash_equals($row['validator_hash'], hash('sha256', $validator));
    $tokenNotExpired = new DateTime($row['expires_at']) > new DateTime();
    $userVerified = (int)$row['is_verified'] === 1;
    $userActive = (int)$row['is_active'] === 1;
    $userNotLocked = !($lockedUntil && $lockedUntil > new DateTime());

    if (!$validatorMatch) {
        error_log('Remember Me: Validator mismatch for selector ' . $selector . ' (possible token reuse)');
    }
    if (!$tokenNotExpired) {
        error_log('Remember Me: Token expired at ' . $row['expires_at']);
    }
    if (!$userVerified) {
        error_log('Remember Me: User not verified');
    }
    if (!$userNotLocked) {
        error_log('Remember Me: User account locked');
    }

    $isValid = $validatorMatch && $tokenNotExpired && $userVerified && $userNotLocked;
    $isValid = $isValid && $userActive;

    if (!$isValid) {
        forgetRememberToken();
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['user_id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['unit_number'] = $row['unit_number'];
    $_SESSION['role'] = $row['role'] ?? 'resident';
    $_SESSION['session_version'] = (int)$row['session_version'];
    $connection->query('UPDATE users SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = ' . (int)$row['user_id']);
    refreshSession();
    trackEvent('auto_login', $row['role'] ?? 'resident', (int)$row['user_id']);
    logAudit('auto_login', 'authentication', (int)$row['user_id'], 'Successful remember-me login');

    // Rotate: the old token is single-use, so a copied-but-already-used
    // cookie stops working even if it leaks later.
    $deleteStmt = $connection->prepare('DELETE FROM remember_tokens WHERE id = ?');
    if ($deleteStmt) {
        $deleteStmt->bind_param('i', $row['id']);
        $deleteStmt->execute();
        $deleteStmt->close();
    }
    issueRememberToken((int)$row['user_id']);
}

/**
 * Invalidates the current remember-me token, in the database and in the
 * browser. Safe to call even if no cookie is present. Used on logout and
 * whenever an auto-login attempt turns out to be invalid.
 */
function forgetRememberToken(): void {
    if (!empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
        if (isset($parts[0]) && $parts[0] !== '') {
            $connection = connectDb();
            if (ensureRememberTokensTable($connection)) {
                $stmt = $connection->prepare('DELETE FROM remember_tokens WHERE selector = ?');
                if ($stmt) {
                    $stmt->bind_param('s', $parts[0]);
                    if (!$stmt->execute()) {
                        error_log('Remember Me: Failed to delete token from database: ' . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    error_log('Remember Me: Prepare failed during forget: ' . $connection->error);
                }
            }
        }
    }

    $setCookieResult = setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!$setCookieResult) {
        error_log('Remember Me: Failed to clear cookie (output may have been sent)');
    }
    
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
}
