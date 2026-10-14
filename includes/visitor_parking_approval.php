<?php
/** One management review reuses the individual decisions in one transaction. */
function vpApprovalVersion(array $parking, array $visitor, ?array $extension): string {
    $fields=[];
    foreach(['id','user_id','status','slot_id','requested_slot_id','vehicle_plate','start_date','end_date','expected_arrival_utc','expected_departure_utc','reservation_end_utc','fee_policy_version','fee_acknowledged_at'] as $key)$fields[$key]=$parking[$key]??null;
    $fields['visitor']=[$visitor['id'],$visitor['status'],$visitor['identity_status'],visitorIdentityVersion($visitor),$visitor['start_date'],$visitor['end_date'],$visitor['details']];
    $fields['extension']=$extension?[$extension['id'],$extension['status'],$extension['requested_until'],$extension['reason']]:null;
    return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
}

function vpApprovalPendingExtension(mysqli $db,int $id,bool $lock=false): ?array {
    $s=$db->prepare("SELECT * FROM parking_extensions WHERE parking_request_id=? AND status='pending' ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''));
    $s->bind_param('i',$id);$s->execute();return $s->get_result()->fetch_assoc()?:null;
}

function vpApprovalEligible(array $parking,array $visitor): bool {
    return $parking['request_type']==='visitor'&&!empty($parking['visitor_registration_id'])
        &&empty($parking['actual_check_in'])&&in_array($parking['status'],['pending','approved'],true)
        &&in_array($visitor['status'],['pending','approved'],true)&&$visitor['end_date']>=date('Y-m-d');
}

function vpApprovalReview(mysqli $db,int $id): array {
    if(!canAccess('visitors.approve_all'))throw new InvalidArgumentException('Only Admin and Super Admin can approve all visitor parking decisions.');
    $parking=vpLoad($db,$id);$visitor=gateRequest($db,(int)$parking['visitor_registration_id']);
    if(!$visitor||!vpApprovalEligible($parking,$visitor))throw new InvalidArgumentException('This request is no longer available for combined approval.');
    $extension=vpApprovalPendingExtension($db,$id);$version=visitorIdentityVersion($visitor);
    $slots=$db->query("SELECT id,slot_code,level FROM parking_slots WHERE slot_type='visitor' AND status='available' AND assigned_user_id IS NULL ORDER BY level,slot_code")->fetch_all(MYSQLI_ASSOC);
    return ['parking'=>vpSessionData($db,$parking,vpNow()),'visitor'=>['id'=>(int)$visitor['id'],'name'=>$visitor['visitor_name'],'count'=>(int)$visitor['visitor_count'],'purpose'=>$visitor['details'],'date'=>$visitor['start_date'],'status'=>$visitor['status'],'identity_status'=>$visitor['identity_status'],'id_type'=>$visitor['id_type'],'identity_note'=>$visitor['identity_review_note'],'identity_version'=>$version,
        'document_url'=>!empty($visitor['id_document_name'])&&!$visitor['id_deleted_at']&&!visitorIdExpired($visitor)?buildUrl('visitor_id_document.php?request_id='.(int)$visitor['id'].'&version='.$version):null],
        'slots'=>$slots,'selected_slot'=>(int)($parking['slot_id']?:$parking['requested_slot_id']),'slot_locked'=>$parking['status']==='approved',
        'extension'=>$extension?['id'=>(int)$extension['id'],'until'=>$extension['requested_until'],'reason'=>$extension['reason']]:null,'review_version'=>vpApprovalVersion($parking,$visitor,$extension)];
}

function vpApproveTogether(mysqli $db,int $id,array $input): array {
    if(!canAccess('visitors.approve_all')||!canAccess('visitors.identity.review')||!canAccess('parking.review'))throw new InvalidArgumentException('Only Admin and Super Admin can approve all visitor parking decisions.');
    $identityVersion=vpText($input,'identity_version',64,true);$reviewVersion=vpText($input,'review_version',64,true);
    $note=vpText($input,'approval_note',255);$includeExtension=($input['include_extension']??'')==='1';$extensionId=(int)($input['extension_id']??0);$slotId=(int)($input['slot_id']??0);
    // Prepare storage before the atomic decisions; MySQL DDL implicitly commits.
    ensureResidentServicesTables($db);ensureParkingTables($db);ensureVehiclesTable($db);ensureAuditLogTable($db);ensureNotificationOutboxTable($db);
    $db->begin_transaction();
    try {
        $link=$db->prepare("SELECT visitor_registration_id FROM parking_requests WHERE id=? AND request_type='visitor'");$link->bind_param('i',$id);$link->execute();$linked=$link->get_result()->fetch_assoc();
        if(!$linked||!$linked['visitor_registration_id'])throw new InvalidArgumentException('Linked visitor registration not found.');
        // Match the visitor-entry lock order; the reused helpers do not commit.
        $s=$db->prepare("SELECT * FROM resident_service_requests WHERE id=? AND request_kind='visitor' FOR UPDATE");$s->bind_param('i',$linked['visitor_registration_id']);$s->execute();$visitor=$s->get_result()->fetch_assoc();
        $parking=vpLoad($db,$id,true);
        if(!$visitor||!vpApprovalEligible($parking,$visitor)||(int)$visitor['user_id']!==(int)$parking['user_id'])throw new InvalidArgumentException('The visitor or parking request changed. Reload before approving.');
        if(!hash_equals(visitorIdentityVersion($visitor),$identityVersion))throw new InvalidArgumentException('The ID document changed. Reload and review the replacement before approving.');
        $path=!empty($visitor['id_document_name'])?visitorIdFilePath($visitor['id_document_name']):null;
        if(!$path||$visitor['id_deleted_at']||visitorIdExpired($visitor)||!@getimagesize($path))throw new InvalidArgumentException('A readable, current valid ID document is required before approval.');
        vpResponsibleAccount($db,(int)$parking['user_id']);
        $pending=vpApprovalPendingExtension($db,$id,true);
        $extensionDone=!$includeExtension;
        if($includeExtension&&$extensionId){$s=$db->prepare("SELECT status FROM parking_extensions WHERE id=? AND parking_request_id=? FOR UPDATE");$s->bind_param('ii',$extensionId,$id);$s->execute();$prior=$s->get_result()->fetch_assoc();$extensionDone=($prior['status']??'')==='approved';}
        if($parking['status']==='approved'&&$visitor['status']==='approved'&&visitorIdentityVerified($visitor)&&$extensionDone){$db->commit();return ['saved'=>true,'already_approved'=>true];}
        if(!hash_equals(vpApprovalVersion($parking,$visitor,$pending),$reviewVersion))throw new InvalidArgumentException('This request changed while you were reviewing it. Reload the review window.');
        if($includeExtension&&(!$pending||(int)$pending['id']!==$extensionId))throw new InvalidArgumentException('Reload and review the current extension request.');
        if(!visitorIdentityVerified($visitor)&&!reviewVisitorIdentity($db,(int)$visitor['id'],'verified',$note,$identityVersion,false))throw new InvalidArgumentException('The ID could not be verified. No approvals were saved.');
        if($visitor['status']==='pending'){
            if(!decideResidentServiceRequest($db,(int)$visitor['id'],'approved',$note,false))throw new InvalidArgumentException('The visit could not be approved. No approvals were saved.');
            if(!logAudit('approve','resident_service_request',(int)$visitor['id'],'Visit approved in combined parking review.',$db))throw new RuntimeException('Visit approval audit failed.');
        }
        if($parking['status']==='pending'){
            if(!decideParkingRequest($id,'approved',$slotId?:null,$note,$db,false))throw new InvalidArgumentException('An available visitor parking slot is required. No approvals were saved.');
        }else{
            $s=$db->prepare("SELECT id FROM parking_slots WHERE id=? AND slot_type='visitor' AND status='available' AND assigned_user_id IS NULL FOR UPDATE");$s->bind_param('i',$parking['slot_id']);$s->execute();
            if(!$s->get_result()->fetch_assoc()||vpSlotConflict($db,(int)$parking['slot_id'],$id,$parking['expected_arrival_utc']?:vpUtcInput($parking['start_date'].'T00:00'),$parking['reservation_end_utc']?:vpUtcInput(($parking['end_date']?:$parking['start_date']).'T23:59')))throw new InvalidArgumentException('The assigned slot is unavailable. No approvals were saved.');
        }
        if($includeExtension&&!vpDecideExtension($db,$extensionId,'approved',$note,'',false))throw new InvalidArgumentException('The requested extension could not be approved. No approvals were saved.');
        if(!logAudit('approve_together','visitor_parking',$id,'ID, visit and parking approved together'.($includeExtension?'; extension #'.$extensionId:'').'.',$db))throw new RuntimeException('Combined approval audit failed.');
        vpNotify($db,$id,'approval-complete','Visitor identity, visit and parking are approved.'.($includeExtension?' The requested parking extension is approved.':''));
        $db->commit();return ['saved'=>true,'already_approved'=>false];
    }catch(Throwable $error){$db->rollback();if($error instanceof RuntimeException&&$error->getMessage()==='No compatible parking slot is available for this request.')throw new InvalidArgumentException('No compatible visitor slot is available for these dates. Choose another slot; no approvals were saved.');throw $error;}
}
