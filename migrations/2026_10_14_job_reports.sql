-- Additive job reports. Activity remains in its existing source tables.
CREATE TABLE IF NOT EXISTS job_report_drafts (
 user_id INT NOT NULL, report_type VARCHAR(10) NOT NULL, period_start DATE NOT NULL,
 notes TEXT NOT NULL, revision INT NOT NULL DEFAULT 1, updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(user_id,report_type,period_start),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS job_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(45) NOT NULL UNIQUE,
 user_id INT NOT NULL, staff_id VARCHAR(20) NOT NULL, staff_name VARCHAR(150) NOT NULL,
 staff_role VARCHAR(30) NOT NULL, report_type VARCHAR(10) NOT NULL,
 period_start DATE NOT NULL, period_end DATE NOT NULL, version INT NOT NULL,
 previous_report_id BIGINT UNSIGNED DEFAULT NULL UNIQUE,
 request_key CHAR(64) NOT NULL UNIQUE, request_digest CHAR(64) NOT NULL,
 snapshot MEDIUMTEXT NOT NULL, snapshot_hash CHAR(64) NOT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'submitted', filed_at DATETIME(6) NOT NULL,
 UNIQUE KEY jr_period_version(user_id,report_type,period_start,version),
 INDEX jr_review(status,filed_at), INDEX jr_staff(staff_id,staff_name),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(previous_report_id) REFERENCES job_reports(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS job_report_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, report_id BIGINT UNSIGNED NOT NULL,
 actor_id INT NOT NULL, actor_name VARCHAR(150) NOT NULL, action VARCHAR(12) NOT NULL,
 feedback TEXT NOT NULL, created_at DATETIME(6) NOT NULL, request_key CHAR(64) NOT NULL UNIQUE,
 INDEX jr_history(report_id,id),
 FOREIGN KEY(report_id) REFERENCES job_reports(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
