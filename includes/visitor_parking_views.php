<?php
const VP_COMPLIANCE_LABELS=['normal'=>'Normal Parking','at_24_hours'=>'At 24 Hours — Approval Required Beyond','approved_extended'=>'Approved Extended Stay','unapproved_extended'=>'Unapproved Extended Stay','overstayed_approved'=>'Overstayed Approved Period'];
/** Preserve legacy shared statement rules; vehicle-session bills belong to their payer. */
function vpBillingPrivacySql(mysqli $db,int $actor): string {
    return ' AND (user_id='.(int)$actor." OR (billing_scope<>'visitor_parking'".(visitorParkingFeesReady($db)?' AND NOT EXISTS(SELECT 1 FROM parking_requests vp_private WHERE vp_private.payment_id=payments.id)':'').'))';
}
function vpBillDetails(mysqli $db,int $payment): ?array {
    if(!visitorParkingFeesReady($db))return null;
    $s=$db->prepare('SELECT id,vehicle_plate,visitor_name_snapshot,sponsor_unit_snapshot,actual_check_in,actual_check_out,rate_snapshot,billable_seconds,billable_hours,parking_fee,parking_compliance,fee_policy_version,rate_version_id,calculation_snapshot,fee_state,visitor_registration_id,billing_user_id FROM parking_requests WHERE payment_id=? AND actual_check_out IS NOT NULL');$s->bind_param('i',$payment);$s->execute();return $s->get_result()->fetch_assoc()?:null;
}
function vpLoad(mysqli $db,int $id,bool $lock=false): array {
    if(!isLoggedIn())throw new InvalidArgumentException('Sign in to review parking.');
    $staff=canAccess('parking.review')||canAccess('parking.gate');$actor=(int)$_SESSION['user_id'];if(!$staff&&!residentHasPermission('resident.parking.request'))throw new InvalidArgumentException('Parking access denied.');
    $s=$db->prepare("SELECT pr.*,(SELECT slot_code FROM parking_slots WHERE id=pr.requested_slot_id) AS requested_slot_code,u.full_name,u.unit_number,v.status AS visitor_status,v.identity_status AS visitor_identity_status,v.visitor_name,v.visitor_log_id,ps.slot_code,p.status AS payment_status FROM parking_requests pr JOIN users u ON u.id=pr.user_id LEFT JOIN resident_service_requests v ON v.id=pr.visitor_registration_id LEFT JOIN parking_slots ps ON ps.id=pr.slot_id LEFT JOIN payments p ON p.id=pr.payment_id WHERE pr.id=? AND pr.request_type='visitor'".($staff?'':' AND pr.user_id=?').($lock?' FOR UPDATE':''));
    if($staff)$s->bind_param('i',$id);else $s->bind_param('ii',$id,$actor);$s->execute();$row=$s->get_result()->fetch_assoc();if(!$row)throw new InvalidArgumentException('Parking session not found or access denied.');return $row;
}
function vpSessionData(mysqli $db,array $row,string $now): array {
    $quote=vpSessionQuote($row,$row['actual_check_out']?:$now);
    $s=$db->prepare('SELECT id,expected_start_utc,requested_until,approved_until,reason,status,decision_note,decided_at,created_at FROM parking_extensions WHERE parking_request_id=? ORDER BY id DESC');$s->bind_param('i',$row['id']);$s->execute();$extensions=$s->get_result()->fetch_all(MYSQLI_ASSOC);foreach($extensions as&$extension)$extension['quote']=$row['actual_check_in']&&$extension['requested_until']>=$row['actual_check_in']?((int)$row['fee_policy_version']>=2?vpCalculate($row['actual_check_in'],$extension['requested_until'],json_decode($row['rate_policy_snapshot'],true,512,JSON_THROW_ON_ERROR)):vpQuote($row['actual_check_in'],$extension['requested_until'],$row['rate_snapshot']?:'50.00')):null;unset($extension);
    $currentRate=vpCurrentRate($db);$needsAck=!$row['actual_check_in']&&((int)$row['fee_policy_version']<2||!$row['fee_acknowledged_at']||(int)$row['fee_ack_rate_id']!==$currentRate['id']||(int)$row['fee_acknowledged_by']!==(int)$row['user_id']);
    $compliance=$row['actual_check_in']?vpCompliance($row,$row['actual_check_out']?:$now):'normal';
    return ['server_now'=>$now,'id'=>(int)$row['id'],'reference'=>'VP-'.str_pad((string)$row['id'],8,'0',STR_PAD_LEFT),'visitor'=>$row['visitor_name_snapshot']?:($row['visitor_name']??''),'plate'=>$row['vehicle_plate'],'type'=>$row['vehicle_type'],'model'=>$row['vehicle_model'],'color'=>$row['vehicle_color'],'host'=>$row['full_name'],'unit'=>$row['sponsor_unit_snapshot']?:$row['unit_number'],'requested_slot'=>$row['requested_slot_code']??null,'slot'=>$row['slot_code']?:'Not assigned','status'=>$row['actual_check_out']?'checked_out':($row['actual_check_in']?'active':$row['status']),'compliance'=>$compliance,'compliance_label'=>VP_COMPLIANCE_LABELS[$compliance],'arrival'=>$row['actual_check_in'],'departure'=>$row['actual_check_out'],'expected_arrival'=>$row['expected_arrival_utc'],'expected_departure'=>$row['expected_departure_utc'],'authorized_until'=>$row['authorized_until'],'quote'=>$quote,'rate_policy'=>$row['rate_policy_snapshot']?json_decode($row['rate_policy_snapshot'],true,512,JSON_THROW_ON_ERROR):$currentRate,'fee_state'=>$row['fee_state'],'needs_ack'=>$needsAck,'assisted'=>!empty($row['registration_actor_id'])&&(int)$row['registration_actor_id']!==(int)$row['user_id'],'can_finalize_rate'=>canAccess('parking.extensions.review')&&in_array($row['fee_state'],['pending_rate_review','pending_account_review'],true),'extensions'=>$extensions,'payment_id'=>$row['payment_id'],'payment_status'=>$row['payment_status'],'fee_policy'=>(int)$row['fee_policy_version'],'can_approve_all'=>canAccess('visitors.approve_all')&&!empty($row['visitor_registration_id'])&&!$row['actual_check_in']&&in_array($row['status'],['pending','approved'],true)&&in_array($row['visitor_status']??'pending',['pending','approved'],true)&&($row['status']==='pending'||($row['visitor_status']??'pending')==='pending'||($row['visitor_identity_status']??'pending')!=='verified'||(bool)array_filter($extensions,static fn(array $e):bool=>$e['status']==='pending')),'can_checkin'=>canAccess('parking.gate')&&$row['status']==='approved'&&!$row['actual_check_in']&&!$needsAck,'can_checkout'=>canAccess('parking.gate')&&(bool)$row['actual_check_in']&&!$row['actual_check_out'],'can_extend'=>residentHasPermission('resident.parking.request')&&$row['status']==='approved'&&!$row['actual_check_out']&&(bool)$row['fee_policy_version'],'can_review'=>canAccess('parking.extensions.review')];
}
function vpWorkspaceData(mysqli $db,array $input): array {
    if(!canAccess('parking.review')&&!residentHasPermission('resident.parking.request'))throw new InvalidArgumentException('Parking access denied.');
    monitorVisitorParking($db);$staff=canAccess('parking.review');$actor=(int)$_SESSION['user_id'];$where=$staff?'1=1':'pr.user_id='.$actor;if(($input['extensions_only']??'')==='1')$where.=" AND EXISTS(SELECT 1 FROM parking_extensions vp_extension WHERE vp_extension.parking_request_id=pr.id)";
    $search=vpText($input,'search',100);$filter=vpText($input,'filter',30);$page=max(1,min(10000,(int)($input['page']??1)));$offset=($page-1)*25;$like='%'.$search.'%';
    $filters=[''=>'1=1','active'=>'pr.actual_check_in IS NOT NULL AND pr.actual_check_out IS NULL','grace'=>"pr.fee_policy_version>=2 AND pr.actual_check_in IS NOT NULL AND pr.actual_check_out IS NULL AND TIMESTAMPDIFF(MICROSECOND,pr.actual_check_in,UTC_TIMESTAMP(6))<=COALESCE(JSON_EXTRACT(pr.rate_policy_snapshot,'$.grace_seconds'),0)*1000000",'overnight'=>"COALESCE(JSON_EXTRACT(pr.calculation_snapshot,'$.overnight_cents'),0)>0 OR (pr.actual_check_in IS NOT NULL AND pr.actual_check_out IS NULL AND (TIME(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+08:00'))>='22:00:00' OR TIME(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+08:00'))<'06:00:00'))",'rate_review'=>"pr.fee_state IN ('pending_rate_review','pending_account_review')",'paid'=>"p.status='paid'",'unpaid'=>"p.status IN ('pending','overdue')",'checked_out'=>'pr.actual_check_out IS NOT NULL','extended'=>"pr.parking_compliance<>'normal'",'pending'=>"EXISTS(SELECT 1 FROM parking_extensions e WHERE e.parking_request_id=pr.id AND e.status='pending')",'approved'=>"EXISTS(SELECT 1 FROM parking_extensions e WHERE e.parking_request_id=pr.id AND e.status='approved')",'rejected'=>"EXISTS(SELECT 1 FROM parking_extensions e WHERE e.parking_request_id=pr.id AND e.status='rejected')",'unapproved'=>"pr.parking_compliance IN ('unapproved_extended','overstayed_approved')"];
    if(!isset($filters[$filter]))throw new InvalidArgumentException('Choose a valid parking filter.');
    $sql="SELECT pr.*,(SELECT slot_code FROM parking_slots WHERE id=pr.requested_slot_id) AS requested_slot_code,u.full_name,u.unit_number,v.status AS visitor_status,v.identity_status AS visitor_identity_status,v.visitor_name,ps.slot_code,p.status AS payment_status FROM parking_requests pr JOIN users u ON u.id=pr.user_id LEFT JOIN resident_service_requests v ON v.id=pr.visitor_registration_id LEFT JOIN parking_slots ps ON ps.id=pr.slot_id LEFT JOIN payments p ON p.id=pr.payment_id WHERE pr.request_type='visitor' AND ($where) AND ({$filters[$filter]}) AND (?='' OR pr.vehicle_plate LIKE ? OR ps.slot_code LIKE ? OR COALESCE(pr.sponsor_unit_snapshot,u.unit_number) LIKE ? OR v.visitor_name LIKE ? OR CONCAT('VP-',LPAD(pr.id,8,'0')) LIKE ?) ORDER BY pr.actual_check_out IS NULL DESC,pr.created_at DESC,pr.id DESC LIMIT 25 OFFSET $offset";
    $s=$db->prepare($sql);$s->bind_param('ssssss',$search,$like,$like,$like,$like,$like);$s->execute();$now=vpNow();$rows=[];foreach($s->get_result()as$row)$rows[]=vpSessionData($db,$row,$now);
    $stats=$db->query("SELECT SUM(pr.actual_check_in IS NOT NULL AND pr.actual_check_out IS NULL) active,SUM(pr.actual_check_out IS NULL AND pr.parking_compliance='approved_extended') approved,SUM(pr.actual_check_out IS NULL AND pr.parking_compliance IN ('unapproved_extended','overstayed_approved')) unapproved,COALESCE(SUM(pr.parking_fee),0) charges,COALESCE(SUM(IF(p.status IN ('pending','overdue'),pr.parking_fee,0)),0) unpaid FROM parking_requests pr LEFT JOIN payments p ON p.id=pr.payment_id WHERE pr.request_type='visitor' AND ($where)")->fetch_assoc();
    $stats['pending']=(int)$db->query("SELECT COUNT(*) n FROM parking_extensions e JOIN parking_requests pr ON pr.id=e.parking_request_id WHERE e.status='pending' AND ($where)")->fetch_assoc()['n'];
    $stats['grace']=(int)$db->query("SELECT COUNT(*) n FROM parking_requests pr WHERE ($where) AND pr.actual_check_in IS NOT NULL AND pr.actual_check_out IS NULL AND pr.fee_policy_version>=2 AND TIMESTAMPDIFF(MICROSECOND,pr.actual_check_in,UTC_TIMESTAMP(6))<=COALESCE(JSON_EXTRACT(pr.rate_policy_snapshot,'$.grace_seconds'),0)*1000000")->fetch_assoc()['n'];
    $stats['rate_reviews']=(int)$db->query("SELECT COUNT(*) n FROM parking_requests pr WHERE ($where) AND pr.fee_state IN ('pending_rate_review','pending_account_review')")->fetch_assoc()['n'];
    $stats['paid']=$db->query("SELECT COALESCE(SUM(pr.parking_fee),0) n FROM parking_requests pr JOIN payments p ON p.id=pr.payment_id WHERE ($where) AND p.status='paid'")->fetch_assoc()['n'];
    if($staff)$stats['available_slots']=(int)$db->query("SELECT COUNT(*) n FROM parking_slots WHERE slot_type='visitor' AND status='available' AND assigned_user_id IS NULL")->fetch_assoc()['n'];
    return ['sessions'=>$rows,'stats'=>$stats,'server_now'=>$now,'page'=>$page,'has_more'=>count($rows)===25];
}
function vpCheckoutSession(mysqli $db,int $id): array {
    if(!canAccess('parking.gate'))throw new InvalidArgumentException('Only authorized Security personnel may confirm vehicle checkout.');
    $row=vpLoad($db,$id);if(!$row['actual_check_in'])throw new InvalidArgumentException('Vehicle has not checked in.');
    if(!$row['actual_check_out']&&!logVisitorOut($db,(int)$row['visitor_log_id'],(int)$_SESSION['user_id']))throw new InvalidArgumentException('Checkout could not be finalized. Refresh and confirm billing responsibility and slot occupancy.');
    return vpSessionData($db,vpLoad($db,$id),vpNow());
}
/** Legacy future reservations require host consent before activating the new fee policy. */
function vpAcknowledgeReservation(mysqli $db,int $id,array $input): void {
    if(!residentHasPermission('resident.parking.request'))throw new InvalidArgumentException('Parking access denied.');$actor=(int)$_SESSION['user_id'];$db->begin_transaction();try{
        $row=vpLoad($db,$id,true);if((int)$row['user_id']!==$actor||$row['actual_check_in']||$row['actual_check_out']||!in_array($row['status'],['pending','approved'],true))throw new InvalidArgumentException('This legacy reservation can no longer be updated.');
        vpApplyPlan($db,$id,$actor,$input);
        if($row['status']==='approved'){
            $updated=vpLoad($db,$id);$s=$db->prepare('SELECT id FROM parking_slots WHERE id=? FOR UPDATE');$s->bind_param('i',$row['slot_id']);$s->execute();
            if(!$s->get_result()->fetch_assoc()||vpSlotConflict($db,(int)$row['slot_id'],$id,$updated['reservation_start_utc'],$updated['reservation_end_utc']))throw new InvalidArgumentException('The confirmed visitor slot conflicts with the visit dates.');
        }
        $db->commit();
    }catch(Throwable $error){$db->rollback();throw $error;}
}
function vpNotificationRelevant(mysqli $db,array $job): bool {
    $id=(int)$job['entity_id'];$user=(int)$job['user_id'];
    $s=$db->prepare("SELECT pr.user_id,u.role,u.is_active,u.is_verified,u.status FROM parking_requests pr JOIN users u ON u.id=? WHERE pr.id=?");$s->bind_param('ii',$user,$id);$s->execute();$row=$s->get_result()->fetch_assoc();
    return $row&&(int)$row['is_active']===1&&(int)$row['is_verified']===1&&$row['status']==='approved'&&((int)$row['user_id']===$user||in_array($row['role'],['admin','superadmin','security'],true));
}
function vpReceiptFields(array $parking): array {
    $fields=['Vehicle plate'=>$parking['vehicle_plate'],'Parking session'=>'VP-'.str_pad((string)$parking['id'],8,'0',STR_PAD_LEFT),'Visitor reference'=>'#'.($parking['visitor_registration_id']??''),'Parking unit'=>$parking['sponsor_unit_snapshot'],'Vehicle check-in'=>vpLocal($parking['actual_check_in']),'Vehicle checkout'=>vpLocal($parking['actual_check_out']),'Parking duration'=>vpDuration((int)$parking['billable_seconds'])];
    if(!empty($parking['calculation_snapshot'])){
        $q=json_decode($parking['calculation_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if(isset($q['regular_cents']))$fields+=['Applied rate version'=>(string)$q['rate_version'],'Grace period'=>$q['grace_applied']?'Free grace applied':'Exceeded grace period','First hours fee'=>'PHP '.vpMoney($q['regular_cents']),'Succeeding hours fee'=>'PHP '.vpMoney($q['succeeding_cents']),'Overnight fee'=>'PHP '.vpMoney($q['overnight_cents'])];
    }else $fields+=['Billable hours'=>(string)$parking['billable_hours'],'Original hourly rate'=>'PHP '.$parking['rate_snapshot']];
    $fields['Parking fee']='PHP '.number_format((float)$parking['parking_fee'],2);return $fields;
}
function renderVisitorParkingBillSummary(?array $parking): void {
    if(!$parking)return;
    echo '<details class="vp-billing-summary"><summary>Visitor Parking Fee - View breakdown</summary>';
    foreach(vpReceiptFields($parking)as$title=>$value)echo '<p>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').': <strong>'.htmlspecialchars($value,ENT_QUOTES,'UTF-8').'</strong></p>';
    echo '</details>';
}
function vpVehicleCheckIn(mysqli $db,int $id): array {
    if(!canAccess('parking.gate'))throw new InvalidArgumentException('Only Security may confirm vehicle entry.');
    $row=vpLoad($db,$id);if($row['actual_check_out'])throw new InvalidArgumentException('This vehicle session is complete.');
    if($row['actual_check_in'])return vpSessionData($db,$row,vpNow());
    $visitor=gateRequest($db,(int)$row['visitor_registration_id']);
    if(!$visitor||!visitorAccessApprovalsComplete($db,$visitor)||$visitor['start_date']>date('Y-m-d')||$visitor['end_date']<date('Y-m-d')||!(residentContext($db,(int)$visitor['user_id'])['approved']??false))throw new InvalidArgumentException('Visitor identity, visit and parking approvals are required before entry.');
    if($visitor['status']==='approved'){
        if(!checkInRegisteredVisitor($db,(int)$visitor['id'],$visitor['access_token'],true))throw new InvalidArgumentException('Vehicle entry refused. Check slot occupancy and fee acknowledgment.');
    }elseif($visitor['status']==='checked_in'){
        $db->begin_transaction();try{
            $s=$db->prepare("SELECT * FROM resident_service_requests WHERE id=? AND status='checked_in' FOR UPDATE");$s->bind_param('i',$visitor['id']);$s->execute();$current=$s->get_result()->fetch_assoc();if(!$current)throw new InvalidArgumentException('Visitor status changed.');
            vpStartForVisitor($db,$current,(int)$current['visitor_log_id']);$db->commit();
        }catch(Throwable $error){$db->rollback();throw $error;}
    }else throw new InvalidArgumentException('The visitor is not eligible for vehicle entry.');
    return vpSessionData($db,vpLoad($db,$id),vpNow());
}
