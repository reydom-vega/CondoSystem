-- Additive community cases. Anonymous cases have NO row in report_identities.
CREATE TABLE IF NOT EXISTS community_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(24) DEFAULT NULL UNIQUE,
 reporting_mode ENUM('identified','anonymous') NOT NULL,
 subject VARCHAR(160) NOT NULL, category VARCHAR(80) NOT NULL, other_category VARCHAR(160) NOT NULL DEFAULT '',
 incident_date DATE NOT NULL, incident_time TIME DEFAULT NULL,
 location VARCHAR(60) NOT NULL, location_details VARCHAR(200) NOT NULL DEFAULT '', unit_reference VARCHAR(60) NOT NULL DEFAULT '',
 description TEXT NOT NULL, person_kind VARCHAR(40) NOT NULL, person_name VARCHAR(160) NOT NULL DEFAULT '',
 person_unit VARCHAR(60) NOT NULL DEFAULT '', person_position VARCHAR(100) NOT NULL DEFAULT '', person_details VARCHAR(1000) NOT NULL DEFAULT '',
 urgency ENUM('normal','important','urgent') NOT NULL, priority ENUM('normal','important','urgent') NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'submitted', review_scope ENUM('ordinary','admin','oversight') NOT NULL DEFAULT 'ordinary',
 assigned_reviewer_id INT DEFAULT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 request_hash CHAR(64) NOT NULL UNIQUE, submission_hash CHAR(64) NOT NULL, audit_head CHAR(64) NOT NULL DEFAULT '',
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, closed_at DATETIME(6) DEFAULT NULL,
 INDEX ix_report_status(status,priority,created_at), INDEX ix_report_scope(review_scope,assigned_reviewer_id),
 CONSTRAINT fk_report_reviewer FOREIGN KEY(assigned_reviewer_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS report_identities (
 report_id BIGINT UNSIGNED PRIMARY KEY, user_id INT NOT NULL, last_read_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 INDEX ix_report_owner(user_id,report_id),
 CONSTRAINT fk_report_identity_case FOREIGN KEY(report_id) REFERENCES community_reports(id) ON DELETE RESTRICT,
 CONSTRAINT fk_report_identity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS report_access_credentials (
 report_id BIGINT UNSIGNED PRIMARY KEY, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL,
 CONSTRAINT fk_report_credential_case FOREIGN KEY(report_id) REFERENCES community_reports(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS report_conflicts (
 report_id BIGINT UNSIGNED NOT NULL, user_id INT NOT NULL, PRIMARY KEY(report_id,user_id),
 CONSTRAINT fk_report_conflict_case FOREIGN KEY(report_id) REFERENCES community_reports(id) ON DELETE RESTRICT,
 CONSTRAINT fk_report_conflict_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- A single append-only event stream holds status history, private messages,
-- assignments and internal notes; messages are not duplicated in another table.
CREATE TABLE IF NOT EXISTS report_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, report_id BIGINT UNSIGNED NOT NULL,
 request_hash CHAR(64) NOT NULL, event_type VARCHAR(40) NOT NULL,
 visibility ENUM('reporter','internal') NOT NULL, actor_kind ENUM('reporter','anonymous','management','system') NOT NULL,
 actor_user_id INT DEFAULT NULL, old_status VARCHAR(30) DEFAULT NULL, new_status VARCHAR(30) DEFAULT NULL,
 body TEXT NOT NULL, payload TEXT NOT NULL, previous_hash CHAR(64) NOT NULL, event_hash CHAR(64) NOT NULL,
 created_at DATETIME(6) NOT NULL, UNIQUE KEY uq_report_operation(report_id,request_hash), INDEX ix_report_timeline(report_id,visibility,id),
 CONSTRAINT fk_report_event_case FOREIGN KEY(report_id) REFERENCES community_reports(id) ON DELETE RESTRICT,
 CONSTRAINT fk_report_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS report_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, report_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
 stored_name VARCHAR(64) NOT NULL UNIQUE, display_name VARCHAR(150) NOT NULL, mime VARCHAR(30) NOT NULL,
 byte_size INT UNSIGNED NOT NULL, content_hash CHAR(64) NOT NULL, deleted_at DATETIME DEFAULT NULL,
 INDEX ix_report_evidence(report_id,event_id),
 CONSTRAINT fk_report_attachment_case FOREIGN KEY(report_id) REFERENCES community_reports(id) ON DELETE RESTRICT,
 CONSTRAINT fk_report_attachment_event FOREIGN KEY(event_id) REFERENCES report_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
