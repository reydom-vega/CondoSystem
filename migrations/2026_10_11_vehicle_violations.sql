-- Additive upgrade for the project's MariaDB 10.4+ deployment.
-- Existing citations, financial statuses and bill references are retained.
-- Prefer: php scripts/migrate_vehicle_violations.php --apply (validates and records version).
ALTER TABLE violations
  MODIFY user_id INT NULL DEFAULT NULL,
  MODIFY status ENUM('warning_issued','unpaid','paid','disputed','waived') NULL DEFAULT 'warning_issued',
  ADD COLUMN IF NOT EXISTS vehicle_id INT NULL,
  ADD COLUMN IF NOT EXISTS visitor_registration_id INT NULL,
  ADD COLUMN IF NOT EXISTS parking_request_id INT NULL,
  ADD COLUMN IF NOT EXISTS parking_slot_id INT NULL,
  ADD COLUMN IF NOT EXISTS observed_plate VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS observed_plate_normalized VARCHAR(20) GENERATED ALWAYS AS (REGEXP_REPLACE(UPPER(observed_plate), '[^A-Z0-9]', '')) STORED,
  ADD COLUMN IF NOT EXISTS registration_source VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS vehicle_snapshot LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS account_name_snapshot VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS unit_snapshot VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS incident_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS report_remarks TEXT NULL,
  ADD COLUMN IF NOT EXISTS review_status ENUM('legacy','pending','approved','rejected') NOT NULL DEFAULT 'legacy',
  ADD COLUMN IF NOT EXISTS reviewed_by INT NULL,
  ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS responsibility_note TEXT NULL;
CREATE INDEX IF NOT EXISTS ix_violation_vehicle ON violations (vehicle_id,incident_at,id);
CREATE INDEX IF NOT EXISTS ix_violation_visitor ON violations (visitor_registration_id,incident_at,id);
CREATE INDEX IF NOT EXISTS ix_violation_plate ON violations (observed_plate_normalized,incident_at,id);
CREATE INDEX IF NOT EXISTS ix_violation_review ON violations (review_status,id);
CREATE INDEX IF NOT EXISTS ix_violation_slot ON violations (parking_slot_id,incident_at,id);
ALTER TABLE parking_slots ADD COLUMN IF NOT EXISTS lookup_plate VARCHAR(20) GENERATED ALWAYS AS (REGEXP_REPLACE(UPPER(vehicle_plate), '[^A-Z0-9]', '')) STORED;
ALTER TABLE parking_requests ADD COLUMN IF NOT EXISTS lookup_plate VARCHAR(20) GENERATED ALWAYS AS (REGEXP_REPLACE(UPPER(vehicle_plate), '[^A-Z0-9]', '')) STORED;
CREATE INDEX IF NOT EXISTS ix_lookup_plate ON parking_slots (lookup_plate);
CREATE INDEX IF NOT EXISTS ix_lookup_plate ON parking_requests (lookup_plate);
