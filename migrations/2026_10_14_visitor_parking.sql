-- Reference additive extension table. Existing parking request columns are migrated
-- idempotently by php scripts/migrate_visitor_parking.php --apply.
CREATE TABLE IF NOT EXISTS parking_extensions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parking_request_id INT NOT NULL,requested_by INT NOT NULL,
        expected_start_utc DATETIME(6) NOT NULL,requested_until DATETIME(6) NOT NULL,approved_until DATETIME(6) DEFAULT NULL,
        reason VARCHAR(1000) NOT NULL,status ENUM('pending','approved','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
        decision_note VARCHAR(1000) DEFAULT NULL,decided_by INT DEFAULT NULL,decided_at DATETIME(6) DEFAULT NULL,
        request_key CHAR(64) NOT NULL UNIQUE,created_at DATETIME(6) NOT NULL,
        pending_request INT GENERATED ALWAYS AS (IF(status='pending',parking_request_id,NULL)) STORED,
        UNIQUE KEY ux_pending_parking_extension(pending_request),INDEX ix_parking_extension(parking_request_id,status),
        CONSTRAINT fk_extension_parking FOREIGN KEY(parking_request_id) REFERENCES parking_requests(id) ON DELETE RESTRICT,
        CONSTRAINT fk_extension_actor FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
