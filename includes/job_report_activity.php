<?php
/** Never select audit details, QR payloads, resident identities or case bodies. */
function jrAuditCatalog(): array {
    return [
        'booking'=>['Bookings','Booking request',['approved','rejected','cancelled','create','approve','reject','update']],
        'maintenance_request'=>['Maintenance','Maintenance request',['approve','reject','update','completed','in_progress','cancelled']],
        'payment'=>['Billing','Billing record',['bill_created','create','mark_paid','cash_confirm','gateway_confirm','webhook_confirm','reject','update','delete','cancel']],
        'announcement'=>['Announcements','Announcement',['create','update','delete']],
        'message'=>['Messages','Management message',['send','reply']],
        'staff'=>['Staff Management','Staff account',['create','update_role','deactivate','reactivate','reset_password','force_logout']],
        'user'=>['Accounts','Account',['approve','reject','unassign','update']],
        'unit'=>['Units','Unit record',['create','update','delete','assign']],
        'parking_request'=>['Parking','Parking request',['approve','reject','cancel','reassign']],
        'parking_extension'=>['Parking','Parking extension',['approved','rejected','cancel']],
        'parking_slot'=>['Parking','Parking slot',['create','assign','reassign','release','update_status']],
        'parking_sticker'=>['Parking','Parking sticker',['issue','sticker_issued']],
        'parking_inventory'=>['Parking','Parking inventory',['import']],
        'parking_policy'=>['Parking','Parking policy',['configure']],
        'parking_rate'=>['Parking','Visitor parking rate',['configure']],
        'vehicle'=>['Vehicles','Vehicle registration',['approve','reject','create','update']],
        'violation'=>['Violations','Violation record',['issue','citation_issued','vehicle_reported','vehicle_report_approved','vehicle_report_rejected','waive','reject','reject_dispute','update']],
        'visitor_identity'=>['Visitors','Visitor identification',['verified','rejected']],
        'resident_service_request'=>['Requests','Service request',['approve','reject','approved','rejected','update']],
        'visitor_parking'=>['Parking','Visitor parking',['check_in','check_out','free_checkout','finalize_rate']],
        'job_report'=>['Job Reports','Job report',['file','reviewed','returned']],
    ];
}
function jrActivityUnion(mysqli $db,int $user,array $p): array {
    $module=[];$description=[];$allowed=[];
    $quote=static fn(string $v):string=>"'".$db->real_escape_string($v)."'";
    $verbs=['create'=>'created','bill_created'=>'bill created','approve'=>'approved','reject'=>'rejected','update'=>'updated','delete'=>'deleted','mark_paid'=>'payment confirmed','cash_confirm'=>'cash payment confirmed','gateway_confirm'=>'payment reconciled','webhook_confirm'=>'payment confirmed','update_role'=>'role updated','reset_password'=>'password reset completed','force_logout'=>'sessions revoked','configure'=>'configuration updated','update_status'=>'status updated','check_in'=>'vehicle checked in','check_out'=>'vehicle checked out','file'=>'filed','returned'=>'returned for clarification','in_progress'=>'work started'];
    foreach(jrAuditCatalog() as $entity=>[$label,$noun,$actions]){
        $module[]='WHEN '.$quote($entity).' THEN '.$quote($label);
        foreach($actions as $action){$condition='entity_type='.$quote($entity).' AND action='.$quote($action);$allowed[]='('.$condition.')';$description[]='WHEN '.$condition.' THEN '.$quote($noun.' '.($verbs[$action]??str_replace('_',' ',$action)));}
    }
    $sql="SELECT CONCAT('AUD-',id) event_key,CONVERT_TZ(created_at,'+08:00','+00:00') happened_at,
      CASE entity_type ".implode(' ',$module)." END module,CASE ".implode(' ',$description)." END description,
      CONCAT(UPPER(REPLACE(entity_type,'_','-')),'-',COALESCE(entity_id,0)) reference,
      IF(action IN ('reject','rejected','reject_dispute','vehicle_report_rejected'),'Rejected','Completed') result,
      IF(action IN ('update','update_role','update_status','configure','reassign','in_progress'), 'update','action') activity_type,
      IF(action IN ('update','update_role','update_status','configure','reassign','in_progress'),0,1) completed,
      IF(action IN ('update','update_role','update_status','configure','reassign','in_progress'),1,0) updated
      FROM audit_logs a WHERE admin_id=? AND (admin_role IS NULL OR admin_role IN ('superadmin','admin','maintenance','treasurer','security')) AND created_at>=? AND created_at<? AND (".implode(' OR ',$allowed).") AND NOT (a.entity_type='violation' AND a.action='issue' AND EXISTS(SELECT 1 FROM audit_logs native WHERE native.admin_id=a.admin_id AND native.entity_type=a.entity_type AND native.entity_id=a.entity_id AND native.action='citation_issued' AND native.created_at BETWEEN a.created_at-INTERVAL 60 SECOND AND a.created_at+INTERVAL 60 SECOND)) AND NOT (a.entity_type='payment' AND a.action='create' AND EXISTS(SELECT 1 FROM audit_logs native WHERE native.admin_id=a.admin_id AND native.entity_type=a.entity_type AND native.entity_id=a.entity_id AND native.action='bill_created' AND native.created_at BETWEEN a.created_at-INTERVAL 60 SECOND AND a.created_at+INTERVAL 60 SECOND))";
    $params=[$user,$p['local_start'],$p['local_end']];
    foreach(['in'=>['logged_by','time_in','Visitor checked in'],'out'=>['checked_out_by','time_out','Visitor checked out']]as$direction=>[$actor,$time,$desc]){
        $sql.=" UNION ALL SELECT CONCAT('VIS-$direction-',id),CONVERT_TZ($time,'+08:00','+00:00'),'Visitors','$desc',CONCAT('VISIT-',id),'Completed','action',1,0 FROM visitor_logs WHERE $actor=? AND $time>=? AND $time<?";
        array_push($params,$user,$p['local_start'],$p['local_end']);
    }
    $sql.=" UNION ALL SELECT CONCAT('QR-',q.id),q.event_time_utc,'QR Verification',CONCAT(CASE q.qr_type WHEN 'digital_id' THEN 'Digital Condo ID' WHEN 'visitor' THEN 'Visitor pass' WHEN 'parking' THEN 'Parking pass' ELSE 'Permit pass' END,IF(q.verification_result='valid',' verified',' verification refused')),CONCAT('SCAN-',q.id),IF(q.verification_result='valid','Completed','Refused'),'verification',IF(q.verification_result='valid',1,0),0 FROM qr_scan_logs q WHERE q.user_id=? AND q.event_time_utc>=? AND q.event_time_utc<? AND q.scan_action='verification' AND q.reference_id IS NOT NULL AND q.qr_type IN ('digital_id','visitor','property','parking','permit') AND q.verification_result IN ('valid','invalid','expired','revoked','suspended','unauthorized','cancelled','already_used','not_found') AND NOT EXISTS(SELECT 1 FROM qr_scan_logs prior WHERE prior.user_id=q.user_id AND prior.qr_type=q.qr_type AND prior.reference_id=q.reference_id AND prior.verification_result=q.verification_result AND prior.scan_action='verification' AND prior.event_time_utc>=q.event_time_utc-INTERVAL 60 SECOND AND (prior.event_time_utc<q.event_time_utc OR (prior.event_time_utc=q.event_time_utc AND prior.id<q.id)))";
    array_push($params,$user,$p['utc_start'],$p['utc_end']);
    $sql.=" UNION ALL SELECT CONCAT('CASE-ACTIVITY-',id),created_at,'Community Reports','Updated restricted community report',CONCAT('CASE-ACTIVITY-',id),'Completed','update',0,1 FROM report_events WHERE actor_user_id=? AND actor_kind='management' AND event_type IN ('management_update','internal_note','status_changed','priority','assign','escalate','conflict','revoke_code') AND created_at>=? AND created_at<?";
    array_push($params,$user,$p['utc_start'],$p['utc_end']);return [$sql,$params];
}
function jrActivityFilter(array $filters,array &$params): string {
    $where=' WHERE 1=1';
    foreach(['module','result','activity_type']as$key)if(($filters[$key]??'')!==''){
        $value=(string)$filters[$key];if(mb_strlen($value)>60)throw new DomainException('Invalid activity filter.');$where.=" AND $key=?";$params[]=$value;
    }
    if(trim($filters['q']??'')!==''){$q=trim((string)$filters['q']);if(mb_strlen($q)>120)throw new DomainException('Search must be 120 characters or fewer.');$where.=' AND (description LIKE ? OR reference LIKE ?)';$needle='%'.addcslashes($q,'%_\\').'%';array_push($params,$needle,$needle);}
    foreach(['from_time'=>'>=','to_time'=>'<=']as$key=>$op)if(($filters[$key]??'')!==''){
        if(!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/',(string)$filters[$key]))throw new DomainException('Choose a valid time.');
        $where.=" AND TIME(CONVERT_TZ(happened_at,'+00:00','+08:00'))$op?";$params[]=$filters[$key].($key==='to_time'?':59':':00');
    }
    return $where;
}
function jrActivities(mysqli $db,int $user,array $p,array $filters=[],bool $complete=false): array {
    [$sql,$params]=jrActivityUnion($db,$user,$p);$baseParams=$params;$where=jrActivityFilter($filters,$params);
    $stats=jrQuery($db,"SELECT COUNT(*) total,COALESCE(SUM(completed),0) completed,COALESCE(SUM(updated),0) updated FROM ($sql) events$where",$params)->get_result()->fetch_assoc();
    $modules=jrQuery($db,"SELECT module,COUNT(*) total,COALESCE(SUM(completed),0) completed,COALESCE(SUM(updated),0) updated FROM ($sql) events GROUP BY module ORDER BY module",$baseParams)->get_result()->fetch_all(MYSQLI_ASSOC);
    $total=(int)$stats['total'];$page=max(1,(int)($filters['page']??1));$size=50;$pages=max(1,(int)ceil($total/$size));$page=min($pages,$page);
    if($complete&&$total>jrLimit())throw new DomainException('This period exceeds the '.jrLimit().' activity export limit. Use daily reports or ask your administrator to raise CONDO_JOB_REPORT_MAX_ACTIVITIES. No activities were omitted.');
    $limit=$complete?jrLimit():$size;$offset=$complete?0:($page-1)*$size;
    $rows=jrQuery($db,"SELECT * FROM ($sql) events$where ORDER BY happened_at,event_key LIMIT $limit OFFSET $offset",$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($rows as &$row){$row['local_time']=(new DateTimeImmutable($row['happened_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');$row['source']='System-Recorded';$row['completed']=(int)$row['completed'];$row['updated']=(int)$row['updated'];}unset($row);
    return ['total'=>$total,'completed'=>(int)$stats['completed'],'updated'=>(int)$stats['updated'],'modules'=>$modules,'rows'=>$rows,'page'=>$page,'pages'=>$pages];
}
function jrRoleMetrics(mysqli $db,int $user,array $p,string $role): array {
    $conditions=[
        'security'=>['Visitor Entries'=>"module='Visitors' AND description='Visitor checked in'",'Visitor Exits'=>"module='Visitors' AND description='Visitor checked out'",'QR Verifications'=>"module='QR Verification'",'Parking Checkouts'=>"module='Parking' AND description IN ('Visitor parking vehicle checked out','Visitor parking free checkout')"],
        'maintenance'=>['Requests Updated'=>"module='Maintenance'",'Requests Completed'=>"module='Maintenance' AND description='Maintenance request completed'",'Status Updates'=>"module='Maintenance' AND activity_type='update'"],
        'treasurer'=>['Billing Records Processed'=>"module='Billing'",'Payments Verified'=>"module='Billing' AND description IN ('Billing record cash payment confirmed','Billing record payment reconciled','Billing record payment confirmed')",'Billing Updates'=>"module='Billing' AND updated=1"],
        'admin'=>['Requests Reviewed'=>"module IN ('Bookings','Requests','Parking','Visitors') AND description REGEXP '(approved|rejected|verified)$'",'Reservations Processed'=>"module='Bookings'",'Maintenance Updates'=>"module='Maintenance'"],
        'superadmin'=>['Staff Actions'=>"module='Staff Management'",'Approvals'=>"description REGEXP '(approved|verified)$' AND module<>'QR Verification'",'System Management Actions'=>"module IN ('Accounts','Units','Announcements','Parking','Billing','Job Reports')"],
    ][$role]??[];
    if(!$conditions)return [];
    [$sql,$params]=jrActivityUnion($db,$user,$p);$columns=[];$index=0;foreach($conditions as$condition)$columns[]="COALESCE(SUM($condition),0) m".$index++;
    $row=jrQuery($db,'SELECT '.implode(',',$columns)." FROM ($sql) events",$params)->get_result()->fetch_assoc();$metrics=[];$index=0;foreach($conditions as$label=>$condition)$metrics[]=['label'=>$label,'value'=>(int)$row['m'.$index++]];return $metrics;
}
