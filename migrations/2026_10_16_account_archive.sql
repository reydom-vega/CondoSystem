-- Account Archive Management: additive schema only.
-- Use php scripts/migrate.php --apply for idempotent column checks and legacy relationship backfill.
ALTER TABLE `users` ADD `archived_at` DATETIME(6) DEFAULT NULL;
ALTER TABLE `users` ADD `archived_by` INT DEFAULT NULL;
ALTER TABLE `users` ADD `archive_reason` VARCHAR(1000) DEFAULT NULL;
ALTER TABLE `vehicles` ADD `authorization_revoked_at` DATETIME(6) DEFAULT NULL;
ALTER TABLE `vehicles` ADD `authorization_revocation_reason` VARCHAR(1000) DEFAULT NULL;
ALTER TABLE `vehicles` ADD `unit_number_snapshot` VARCHAR(30) DEFAULT NULL;
ALTER TABLE `payments` ADD `unit_number_snapshot` VARCHAR(30) DEFAULT NULL;
ALTER TABLE `maintenance_requests` ADD `unit_number_snapshot` VARCHAR(30) DEFAULT NULL;
ALTER TABLE `resident_service_requests` ADD `unit_number_snapshot` VARCHAR(30) DEFAULT NULL;
ALTER TABLE `parking_sticker_orders` ADD `authorization_revoked_at` DATETIME(6) DEFAULT NULL;
ALTER TABLE `parking_sticker_orders` ADD `authorization_revocation_reason` VARCHAR(1000) DEFAULT NULL;
ALTER TABLE `parking_sticker_vehicles` ADD `revoked_at` DATETIME(6) DEFAULT NULL;
ALTER TABLE `parking_sticker_vehicles` ADD `revocation_reason` VARCHAR(1000) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS account_unit_relationships (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_archive_events (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
