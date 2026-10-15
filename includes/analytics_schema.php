<?php
/** Index-only upgrade. No transaction, activity or historical snapshot copies. */
function analyticsReportingIndexes(): array {
    return ['payments'=>['ix_insight_created'=>'created_at,status','ix_insight_paid'=>'paid_at,status','ix_insight_due'=>'status,due_date'],
        'visitor_logs'=>['ix_insight_entry'=>'time_in','ix_insight_exit'=>'time_out'],
        'resident_service_requests'=>['ix_insight_registration'=>'request_kind,created_at,status'],
        'parking_requests'=>['ix_insight_entry'=>'actual_check_in','ix_insight_exit'=>'actual_check_out,fee_state','ix_insight_billing'=>'fee_state,payment_id'],
        'maintenance_requests'=>['ix_insight_created'=>'created_at,status,issue_type'],
        'bookings'=>['ix_insight_created'=>'created_at,status'],
        'violations'=>['ix_insight_created'=>'created_at,review_status','ix_insight_bill'=>'payment_id,bill_item_id','ix_insight_reviewed'=>'reviewed_at,review_status'],
        'job_reports'=>['ix_insight_filed'=>'filed_at,staff_role,status'],
        'analytics'=>['ix_insight_created'=>'created_at,event_type']];
}
function ensureAnalyticsReportingSchema(mysqli $db): bool {
    if(!schemaMutationAllowed())return true;
    foreach(analyticsReportingIndexes()as$table=>$indexes)foreach($indexes as$name=>$columns)if(!$db->query("SHOW INDEX FROM `$table` WHERE Key_name='$name'")->num_rows)$db->query("ALTER TABLE `$table` ADD INDEX `$name` ($columns)");
    return true;
}
