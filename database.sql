-- The Celandine Homes: complete application schema (2026.10.09.5).
-- Import into an EMPTY database. Existing installations: php scripts/migrate.php --apply.
-- Contains no resident data, credentials or private signing keys.
-- After importing, run the migration to seed installation settings and record readiness.

SET NAMES utf8mb4;

CREATE TABLE `access_signing_keys` (
  `key_name` varchar(30) NOT NULL,
  `key_value` char(64) NOT NULL,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_data` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `event_type` (`event_type`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `created_at` (`created_at`),
  KEY `is_active` (`is_active`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `app_schema_versions` (
  `version` varchar(32) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `admin_name` varchar(100) DEFAULT NULL,
  `admin_role` varchar(30) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `entity_type` (`entity_type`,`entity_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `authentication_limits` (
  `bucket` char(64) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  PRIMARY KEY (`bucket`),
  KEY `window_started_at` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notification_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deduplication_key` char(64) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `event_kind` varchar(40) NOT NULL DEFAULT 'notice',
  `entity_id` int(11) DEFAULT NULL,
  `channel` enum('email','sms') NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `body` mediumtext NOT NULL,
  `status` enum('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `deduplication_key` (`deduplication_key`),
  KEY `status` (`status`,`next_attempt_at`),
  KEY `user_id` (`user_id`),
  KEY `event_kind` (`event_kind`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parking_policy` (
  `id` tinyint(4) NOT NULL,
  `sticker_price` decimal(10,2) NOT NULL,
  `sticker_max_quantity` int(11) NOT NULL,
  `visitor_max_days` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `qr_scan_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `scanned_content` text NOT NULL,
  `content_type` varchar(20) NOT NULL DEFAULT 'text',
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qr_type` varchar(20) NOT NULL DEFAULT 'unknown',
  `reference_id` int(11) DEFAULT NULL,
  `subject_name` varchar(120) NOT NULL DEFAULT '',
  `requester_name` varchar(120) NOT NULL DEFAULT '',
  `unit_number` varchar(30) NOT NULL DEFAULT '',
  `pass_number` varchar(40) NOT NULL DEFAULT '',
  `scanner_full_name` varchar(100) NOT NULL DEFAULT '',
  `scanner_role` varchar(20) NOT NULL DEFAULT 'unknown',
  `scan_action` varchar(30) NOT NULL DEFAULT 'verification',
  `verification_result` varchar(20) NOT NULL DEFAULT 'invalid',
  `remarks` varchar(500) NOT NULL DEFAULT '',
  `event_time_utc` datetime DEFAULT NULL,
  `created_at_utc` datetime DEFAULT NULL,
  `content_hash` char(64) DEFAULT NULL,
  `request_key` char(64) DEFAULT NULL,
  `verification_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qr_request` (`request_key`),
  KEY `user_id` (`user_id`),
  KEY `scanned_at` (`scanned_at`),
  KEY `ix_qr_time` (`event_time_utc`,`id`),
  KEY `ix_qr_filters` (`qr_type`,`verification_result`,`event_time_utc`),
  KEY `ix_qr_actor` (`user_id`,`content_hash`,`event_time_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `staff_id` varchar(20) DEFAULT NULL,
  `resident_id` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `unit_number` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `account_type` varchar(80) DEFAULT NULL,
  `unit_owner_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `session_version` int(11) NOT NULL DEFAULT 0,
  `verification_token` varchar(100) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `role` enum('resident','admin','superadmin','treasurer','maintenance','security') NOT NULL DEFAULT 'resident',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `phone_verified` tinyint(1) NOT NULL DEFAULT 0,
  `phone_otp` varchar(80) DEFAULT NULL,
  `phone_otp_expires` datetime DEFAULT NULL,
  `verification_expires` datetime DEFAULT NULL,
  `phone_otp_attempts` int(11) NOT NULL DEFAULT 0,
  `phone_otp_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `staff_id` (`staff_id`),
  UNIQUE KEY `ux_users_staff_id` (`staff_id`),
  KEY `ix_users_unit_owner` (`unit_owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `make` varchar(80) NOT NULL,
  `model` varchar(100) NOT NULL,
  `color` varchar(50) NOT NULL,
  `year` smallint(6) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `normalized_plate` varchar(20) NOT NULL,
  `or_cr_path` varchar(255) NOT NULL,
  `or_cr_mime` varchar(100) NOT NULL,
  `vehicle_photo_path` varchar(255) DEFAULT NULL,
  `photo_mime` varchar(100) DEFAULT NULL,
  `parking_slot_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','inactive') NOT NULL DEFAULT 'pending',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`status`),
  KEY `normalized_plate` (`normalized_plate`),
  CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `violation_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `penalty_type` enum('warning','fine') NOT NULL DEFAULT 'fine',
  `fine_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `status` enum('warning_issued','unpaid','paid','disputed','waived') NOT NULL DEFAULT 'warning_issued',
  `dispute_reason` text DEFAULT NULL,
  `bill_item_id` int(11) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `location` varchar(255) DEFAULT NULL,
  `evidence_path` varchar(255) DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `fk_violations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `visitor_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visitor_name` varchar(120) NOT NULL,
  `visitor_contact` varchar(30) DEFAULT NULL,
  `visitor_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `unit_number` varchar(20) NOT NULL,
  `purpose` varchar(150) DEFAULT NULL,
  `logged_by` int(11) NOT NULL,
  `checked_out_by` int(11) DEFAULT NULL,
  `time_in` datetime NOT NULL DEFAULT current_timestamp(),
  `time_out` datetime DEFAULT NULL,
  `status` enum('checked_in','checked_out') NOT NULL DEFAULT 'checked_in',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `logged_by` (`logged_by`),
  KEY `status` (`status`),
  KEY `checked_out_by` (`checked_out_by`),
  CONSTRAINT `fk_visitor_logs_user` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amenity` varchar(80) NOT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `status` enum('pending','approved','confirmed','rejected','cancelled','completed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attendees` int(11) NOT NULL DEFAULT 1,
  `unit_number` varchar(30) DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `duration_hours` tinyint unsigned DEFAULT NULL,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `cancellation_note` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  UNIQUE KEY `ux_booking_payment` (`payment_id`),
  KEY `ix_booking_schedule` (`amenity`,`booking_date`,`status`,`booking_time`,`end_time`),
  CONSTRAINT `fk_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `maintenance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `issue_type` varchar(80) NOT NULL,
  `description` text NOT NULL,
  `preferred_date` date DEFAULT NULL,
  `status` enum('pending','approved','in_progress','completed','closed','rejected','cancelled','reopened') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `location` varchar(80) DEFAULT NULL,
  `urgency` enum('low','normal','urgent') NOT NULL DEFAULT 'normal',
  `evidence_path` varchar(64) DEFAULT NULL,
  `completion_note` text DEFAULT NULL,
  `before_photo_path` varchar(64) DEFAULT NULL,
  `after_photo_path` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_maintenance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `sender_role` enum('resident','admin') NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_mime` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parking_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slot_code` varchar(20) NOT NULL,
  `level` varchar(30) DEFAULT NULL,
  `slot_type` enum('resident','visitor') NOT NULL DEFAULT 'resident',
  `status` enum('available','occupied','maintenance') NOT NULL DEFAULT 'available',
  `assigned_user_id` int(11) DEFAULT NULL,
  `assigned_unit` varchar(30) DEFAULT NULL,
  `vehicle_plate` varchar(20) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slot_code` (`slot_code`),
  KEY `slot_type` (`slot_type`),
  KEY `status` (`status`),
  KEY `fk_parking_slots_user` (`assigned_user_id`),
  CONSTRAINT `fk_parking_slots_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parking_sticker_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `paymongo_checkout_id` varchar(100) DEFAULT NULL,
  `checkout_url` varchar(500) DEFAULT NULL,
  `paymongo_payment_id` varchar(100) DEFAULT NULL,
  `gateway_status` varchar(50) DEFAULT NULL,
  `bill_payment_id` int(11) DEFAULT NULL,
  `claim_status` enum('not_submitted','pending','issued') NOT NULL DEFAULT 'not_submitted',
  `proof_file` varchar(100) DEFAULT NULL,
  `proof_submitted_at` datetime DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_sticker_checkout_id` (`paymongo_checkout_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `fk_sticker_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parking_sticker_vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `sticker_number` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_sticker_vehicle` (`vehicle_id`),
  UNIQUE KEY `sticker_number` (`sticker_number`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `parking_sticker_vehicles_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `parking_sticker_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `parking_sticker_vehicles_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(30) NOT NULL,
  `status` enum('pending','paid','overdue','rejected','rolled_forward') NOT NULL DEFAULT 'pending',
  `due_date` date NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paymongo_checkout_id` varchar(100) DEFAULT NULL,
  `paymongo_payment_id` varchar(100) DEFAULT NULL,
  `checkout_url` varchar(500) DEFAULT NULL,
  `gateway_status` varchar(50) DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `billing_scope` varchar(30) NOT NULL DEFAULT 'unit',
  `payment_channel` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_payment_checkout` (`paymongo_checkout_id`),
  UNIQUE KEY `ux_payment_gateway` (`paymongo_payment_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` varchar(24) NOT NULL,
  `validator_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `session_version` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_remember_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `resident_service_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `request_kind` enum('visitor','permit') NOT NULL,
  `permit_type` varchar(30) DEFAULT NULL,
  `visitor_name` varchar(120) DEFAULT NULL,
  `visitor_contact` varchar(30) DEFAULT NULL,
  `visitor_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `details` varchar(500) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('pending','approved','rejected','cancelled','checked_in','checked_out','changes_requested','expired','completed') NOT NULL DEFAULT 'pending',
  `access_token` char(64) NOT NULL,
  `admin_notes` varchar(500) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `visitor_log_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `gate_data` longtext DEFAULT NULL,
  `qr_token_hash` char(64) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_token` (`access_token`),
  KEY `user_id` (`user_id`,`request_kind`,`status`),
  KEY `request_kind` (`request_kind`,`start_date`,`end_date`),
  CONSTRAINT `resident_service_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bill_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  CONSTRAINT `fk_bill_items_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parking_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `request_type` enum('resident_assignment','visitor') NOT NULL DEFAULT 'visitor',
  `vehicle_plate` varchar(20) NOT NULL,
  `vehicle_description` varchar(120) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `slot_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `admin_notes` varchar(255) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `visitor_registration_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `fk_parking_requests_slot` (`slot_id`),
  KEY `idx_parking_visitor_registration` (`visitor_registration_id`),
  CONSTRAINT `fk_parking_requests_slot` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_parking_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `permit_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `stored_name` varchar(80) NOT NULL,
  `original_name` varchar(150) NOT NULL,
  `mime` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `permit_documents_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `resident_service_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `permit_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `remarks` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `permit_history_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `resident_service_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `permit_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `category` varchar(20) NOT NULL,
  `quantity` smallint(5) unsigned NOT NULL,
  `description` varchar(300) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `permit_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `resident_service_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
