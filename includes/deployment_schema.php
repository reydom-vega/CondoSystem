<?php
/** A migration is complete only after these dependencies are available. */
const APP_SCHEMA_VERSION = '2026.10.15.3';
require_once __DIR__.'/analytics_schema.php';

function deploymentSchemaRequirements(): array {
    return [
        'job_reports'=>['reference','user_id','staff_id','staff_name','staff_role','report_type','period_start','period_end','version','previous_report_id','request_key','request_digest','snapshot','snapshot_hash','status','filed_at'],
        'job_report_drafts'=>['user_id','report_type','period_start','notes','revision','updated_at'],
        'job_report_reviews'=>['report_id','actor_id','actor_name','action','feedback','created_at','request_key'],
        'parking_rate_versions'=>['effective_from','policy_json','created_by','created_at','reason'],
        'parking_extensions'=>['parking_request_id','requested_by','expected_start_utc','requested_until','approved_until','status','request_key','decided_by','decided_at'],
        'community_reports'=>['reference','reporting_mode','review_scope','assigned_reviewer_id','request_hash','submission_hash','audit_head','version','created_at','closed_at'],
        'report_identities'=>['report_id','user_id','last_read_event_id'],
        'report_access_credentials'=>['report_id','token_hash','expires_at','revoked_at'],
        'report_conflicts'=>['report_id','user_id'],
        'report_events'=>['report_id','request_hash','visibility','actor_kind','actor_user_id','old_status','new_status','previous_hash','event_hash','created_at'],
        'report_attachments'=>['report_id','event_id','stored_name','content_hash','deleted_at'],
        'users' => ['profile_photo', 'role', 'account_type', 'unit_owner_id', 'is_active', 'status', 'session_version', 'last_seen_at', 'verification_token', 'verification_expires', 'reset_token', 'reset_expires', 'failed_login_attempts', 'locked_until', 'phone_verified', 'phone_otp', 'phone_otp_expires', 'phone_otp_attempts', 'phone_otp_sent_at'],
        'authentication_limits' => ['bucket', 'attempts', 'window_started_at'],
        'digital_id_credentials' => ['user_id','token_hash','account_fingerprint','issued_at','expires_at','revoked_at'],
        'notification_outbox' => ['deduplication_key','user_id','event_kind','entity_id','channel','recipient','subject','body','status','attempts','next_attempt_at','locked_at','sent_at'],
        'payments' => ['status', 'billing_scope', 'billing_period_start', 'billing_period_end', 'paymongo_checkout_id', 'paymongo_payment_id', 'checkout_url', 'gateway_status', 'payment_channel', 'reminder_sent_at'],
        'bill_items' => ['payment_id', 'amount'],
        'maintenance_requests' => ['urgency', 'evidence_path', 'completion_note', 'before_photo_path', 'after_photo_path', 'status'],
        'messages' => ['attachment_path', 'attachment_name', 'attachment_mime', 'is_read'],
        'bookings' => ['attendees','status','unit_number','end_time','duration_hours','hourly_rate','total_fee','payment_id','approved_by','approved_at','rejection_reason','cancellation_note'],
        'analytics' => ['event_type'],
        'announcements' => ['expires_at'],
        'remember_tokens' => ['selector', 'validator_hash', 'expires_at', 'session_version'],
        'audit_logs' => ['admin_role', 'entity_type', 'entity_id','actor_name_snapshot','actor_identifier','actor_role_snapshot','outcome'],
        'parking_slots' => ['slot_code', 'slot_type', 'status', 'assigned_user_id', 'lookup_plate'],
        'parking_requests' => ['registration_authority','reservation_start_utc','fee_ack_rate_id','fee_acknowledged_by','registration_actor_id','rate_version_id','rate_policy_snapshot','calculation_snapshot','fee_state','rate_reviewed_by','rate_reviewed_at','rate_review_reason','fee_policy_version','vehicle_type','vehicle_model','vehicle_color','expected_arrival_utc','expected_departure_utc','reservation_end_utc','requested_slot_id','fee_acknowledged_at','actual_check_in','actual_check_out','rate_snapshot','billing_user_id','billing_scope_snapshot','sponsor_unit_snapshot','visitor_name_snapshot','authorized_until','parking_compliance','billable_seconds','billable_hours','parking_fee','last_monitored_at','payment_id','checked_in_by','checked_out_by','active_parking_plate','active_parking_slot','visitor_registration_id','slot_id','status','lookup_plate'],
        'parking_policy' => ['sticker_price', 'sticker_max_quantity', 'visitor_max_days'],
        'parking_sticker_orders' => ['quantity', 'bill_payment_id', 'claim_status', 'issued_at', 'issued_by'],
        'vehicles' => ['normalized_plate', 'or_cr_path', 'or_cr_mime', 'status'],
        'parking_sticker_vehicles' => ['order_id', 'vehicle_id', 'sticker_number'],
        'violations' => ['location', 'evidence_path', 'admin_remarks', 'payment_id', 'vehicle_id', 'visitor_registration_id', 'parking_request_id', 'parking_slot_id', 'observed_plate', 'observed_plate_normalized', 'registration_source', 'vehicle_snapshot', 'account_name_snapshot', 'unit_snapshot', 'incident_at', 'report_remarks', 'review_status', 'reviewed_by', 'reviewed_at', 'responsibility_note'],
        'visitor_logs' => ['visitor_count', 'checked_out_by', 'time_out', 'status'],
        'resident_service_requests' => ['gate_data', 'qr_token_hash', 'updated_at', 'visitor_count', 'request_kind', 'visitor_log_id', 'access_token', 'status', 'identity_status', 'id_type', 'id_number_last4', 'id_document_name', 'id_document_mime', 'id_uploaded_at', 'identity_reviewed_by', 'identity_reviewed_at', 'identity_review_note', 'id_deleted_at'],
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
        'digital_id_credentials' => ['token_hash'],
        'bookings' => ['payment_id'],
        'parking_requests'=>['payment_id','active_parking_plate','active_parking_slot'],
        'parking_extensions'=>['request_key','pending_request'],
        'job_reports'=>['reference','request_key','previous_report_id'],
        'job_report_reviews'=>['request_key'],
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
    foreach(analyticsReportingIndexes()as$table=>$required) {
        if(!isset($tables[$table]))continue;
        $actual=[];$result=$db->query("SHOW INDEX FROM `$table`");
        while($row=$result->fetch_assoc())$actual[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
        foreach($required as$name=>$columns){$found=$actual[$name]??[];ksort($found);if(implode(',',$found)!==$columns)$problems[]="Missing/mismatched reporting index: {$table}.{$name}";}
    }
    if(isset($tables['job_reports'])) {
        $triggers=[]; $result=$db->query('SHOW TRIGGERS'); while($row=$result->fetch_assoc())$triggers[]=$row['Trigger'];
        foreach(['jr_snapshot_immutable','jr_no_delete','jr_review_no_update','jr_review_no_delete'] as $trigger)if(!in_array($trigger,$triggers,true))$problems[]="Missing immutable report trigger: {$trigger}";
    }
    return $problems;
}
