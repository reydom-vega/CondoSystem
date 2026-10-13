-- Additive only. Use php scripts/migrate.php --apply to validate and record the release.
CREATE TABLE IF NOT EXISTS digital_id_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
    account_fingerprint CHAR(64) NOT NULL,
    issued_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_digital_id_token (token_hash),
    INDEX ix_digital_id_user (user_id,revoked_at), INDEX ix_digital_id_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
