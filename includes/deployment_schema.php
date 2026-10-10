<?php
/** A migration is complete only after these dependencies are available. */
const APP_SCHEMA_VERSION = '2026.10.09.5';

function deploymentSchemaRequirements(): array {
    return [
        'users' => ['role', 'account_type', 'unit_owner_id', 'is_active', 'status', 'session_version', 'last_seen_at', 'verification_token', 'verification_expires', 'reset_token', 'reset_expires', 'failed_login_attempts', 'locked_until', 'phone_verified', 'phone_otp', 'phone_otp_expires', 'phone_otp_attempts', 'phone_otp_sent_at'],
        'authentication_limits' => ['bucket', 'attempts', 'window_started_at'],
        'notification_outbox' => ['deduplication_key','user_id','event_kind','entity_id','channel','recipient','subject','body','status','attempts','next_attempt_at','locked_at','sent_at'],
        'payments' => ['status', 'billing_scope', 'billing_period_start', 'billing_period_end', 'paymongo_checkout_id', 'paymongo_payment_id', 'checkout_url', 'gateway_status', 'payment_channel', 'reminder_sent_at'],
        'bill_items' => ['payment_id', 'amount'],
        'maintenance_requests' => ['urgency', 'evidence_path', 'completion_note', 'before_photo_path', 'after_photo_path', 'status'],
        'messages' => ['attachment_path', 'attachment_name', 'attachment_mime', 'is_read'],
        'bookings' => ['attendees','status','unit_number','end_time','duration_hours','hourly_rate','total_fee','payment_id','approved_by','approved_at','rejection_reason','cancellation_note'],
        'analytics' => ['event_type'],
        'announcements' => ['expires_at'],
        'remember_tokens' => ['selector', 'validator_hash', 'expires_at', 'session_version'],
        'audit_logs' => ['admin_role', 'entity_type', 'entity_id'],
        'parking_slots' => ['slot_code', 'slot_type', 'status', 'assigned_user_id'],
        'parking_requests' => ['visitor_registration_id', 'slot_id', 'status'],
        'parking_policy' => ['sticker_price', 'sticker_max_quantity', 'visitor_max_days'],
        'parking_sticker_orders' => ['quantity', 'bill_payment_id', 'claim_status', 'issued_at', 'issued_by'],
        'vehicles' => ['normalized_plate', 'or_cr_path', 'or_cr_mime', 'status'],
        'parking_sticker_vehicles' => ['order_id', 'vehicle_id', 'sticker_number'],
        'violations' => ['location', 'evidence_path', 'admin_remarks', 'payment_id'],
        'visitor_logs' => ['visitor_count', 'checked_out_by', 'time_out', 'status'],
        'resident_service_requests' => ['gate_data', 'qr_token_hash', 'updated_at', 'visitor_count', 'request_kind', 'visitor_log_id', 'access_token', 'status'],
        'permit_items' => ['request_id','item_name','category','quantity','description'],
        'permit_documents' => ['request_id','stored_name','original_name','mime'],
        'permit_history' => ['request_id','actor_id','action','remarks','created_at'],
        'access_signing_keys' => ['key_name', 'key_value'],
        'qr_scan_logs' => ['user_id','username','scanned_content','content_type','scanned_at','qr_type','reference_id','subject_name','requester_name','unit_number','pass_number','scanner_full_name','scanner_role','scan_action','verification_result','remarks','event_time_utc','created_at_utc','request_key','content_hash','verification_json'],
    ];
}

/** Read-only schema validation shared by migration and deployment preflight. */
function deploymentSchemaProblems(mysqli $db): array {
    $problems = [];
    $tables = [];
    $result = $db->query('SHOW TABLE STATUS');
    while ($row = $result->fetch_assoc()) $tables[$row['Name']] = $row;
    foreach (deploymentSchemaRequirements() as $table => $columns) {
        if (!isset($tables[$table])) { $problems[] = "Missing table: {$table}"; continue; }
        if (strcasecmp((string)$tables[$table]['Engine'], 'InnoDB') !== 0) $problems[] = "Nontransactional storage: {$table} (InnoDB required)";
        $actual = [];
        $fields = $db->query("SHOW COLUMNS FROM `{$table}`");
        while ($row = $fields->fetch_assoc()) $actual[$row['Field']] = true;
        foreach ($columns as $column) if (!isset($actual[$column])) $problems[] = "Missing column: {$table}.{$column}";
    }
    $uniqueColumns = [
        'users' => ['username', 'email'], 'remember_tokens' => ['selector'],
        'parking_slots' => ['slot_code'], 'resident_service_requests' => ['access_token'],
        'parking_sticker_vehicles' => ['vehicle_id', 'sticker_number'],
        'payments' => ['paymongo_checkout_id','paymongo_payment_id'],
        'notification_outbox' => ['deduplication_key'],
        'qr_scan_logs' => ['request_key'],
        'bookings' => ['payment_id'],
    ];
    foreach ($uniqueColumns as $table => $columns) {
        if (!isset($tables[$table])) continue;
        $indexes = [];
        $result = $db->query("SHOW INDEX FROM `{$table}` WHERE Non_unique=0");
        while ($row = $result->fetch_assoc()) $indexes[$row['Key_name']][] = $row['Column_name'];
        foreach ($columns as $column) {
            if (!in_array([$column], array_values($indexes), true)) $problems[] = "Missing unique index: {$table}.{$column}";
        }
    }
    return $problems;
}
