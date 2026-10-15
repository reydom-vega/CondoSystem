<?php
/** Caller holds the reservation lock and transaction; never remove decision history. */
function vpCancelPendingExtensions(mysqli $db,int $request,string $reason): void {
    if(!visitorParkingFeesReady($db))return;
    $s=$db->prepare("SELECT id FROM parking_extensions WHERE parking_request_id=? AND status='pending' FOR UPDATE");$s->bind_param('i',$request);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$actor=(int)($_SESSION['user_id']??0);
    foreach($rows as$row){$s=$db->prepare("UPDATE parking_extensions SET status='cancelled',decision_note=?,decided_by=?,decided_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'");$s->bind_param('sii',$reason,$actor,$row['id']);$s->execute();if(!logAudit('cancel','parking_extension',(int)$row['id'],$reason,$db))throw new RuntimeException('Extension cancellation audit failed.');}
}
function vpInsertExtension(mysqli $db,int $request,int $actor,string $start,string $until,string $reason,string $key): int {
    if(!preg_match('/^[a-f0-9-]{32,64}$/D',$key))throw new InvalidArgumentException('Reload the extension form.');$hash=hash('sha256',$key);
    $s=$db->prepare('SELECT id,parking_request_id,requested_by FROM parking_extensions WHERE request_key=?');$s->bind_param('s',$hash);$s->execute();$prior=$s->get_result()->fetch_assoc();
    if($prior){if((int)$prior['parking_request_id']!==$request||(int)$prior['requested_by']!==$actor)throw new InvalidArgumentException('Invalid extension request key.');return (int)$prior['id'];}
    $s=$db->prepare("INSERT INTO parking_extensions(parking_request_id,requested_by,expected_start_utc,requested_until,reason,request_key,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))");$s->bind_param('iissss',$request,$actor,$start,$until,$reason,$hash);$s->execute();$id=(int)$db->insert_id;
    if(!logAudit('request_extension','visitor_parking',$request,'Extension #'.$id.' submitted.',$db))throw new RuntimeException('Extension audit failed.');
    vpNotify($db,$request,'extension:'.$id.':pending','Parking extension approval is required. The request is pending management review.',true);return $id;
}
function vpRequestExtension(mysqli $db,int $request,array $input): int {
    if(!residentHasPermission('resident.parking.request'))throw new InvalidArgumentException('Resident parking access is required.');$actor=(int)$_SESSION['user_id'];
    $until=vpUtcInput(vpText($input,'requested_until',16,true));$reason=vpText($input,'extension_reason',1000,true);$key=vpText($input,'request_key',64,true);
    $db->begin_transaction();try{
        $s=$db->prepare("SELECT * FROM parking_requests WHERE id=? AND user_id=? AND request_type='visitor' AND status='approved' AND actual_check_out IS NULL AND fee_policy_version>=1 FOR UPDATE");$s->bind_param('ii',$request,$actor);$s->execute();$row=$s->get_result()->fetch_assoc();if(!$row)throw new InvalidArgumentException('Active visitor parking reservation not found.');
        $start=$row['actual_check_in']?:($row['reservation_start_utc']?:$row['expected_arrival_utc']);$quote=vpQuote($start,$until);if(!$quote['extended']||$until<=vpNow()||vpMicros($until)-vpMicros($start)>365*86400000000)throw new InvalidArgumentException('Request a future departure beyond 24 hours, within one year.');
        if(!empty($row['authorized_until'])&&$until<=$row['authorized_until'])throw new InvalidArgumentException('The requested departure is already authorized.');
        $id=vpInsertExtension($db,$request,$actor,$start,$until,$reason,$key);$db->commit();return $id;
    }catch(Throwable $error){$db->rollback();throw $error;}
}
/** Caller holds reservation + inventory locks. Exact times for new bookings, dates for legacy. */
function vpSlotConflict(mysqli $db,int $slot,int $exclude,string $start,string $end): bool {
    $localStart=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    $localEnd=(new DateTimeImmutable($end,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    $s=$db->prepare("SELECT id FROM parking_requests WHERE slot_id=? AND id<>? AND status='approved' AND actual_check_out IS NULL AND (
        (actual_check_in IS NOT NULL) OR request_type='resident_assignment' OR
        (expected_arrival_utc IS NOT NULL AND expected_arrival_utc<? AND reservation_end_utc>?) OR
        (expected_arrival_utc IS NULL AND start_date<=? AND COALESCE(end_date,start_date)>=?)) LIMIT 1 FOR UPDATE");
    $s->bind_param('iissss',$slot,$exclude,$end,$start,$localEnd,$localStart);$s->execute();return (bool)$s->get_result()->fetch_assoc();
}
function vpDecideExtension(mysqli $db,int $id,string $decision,string $note,string $approvedInput='',bool $manageTransaction=true): bool {
    if(!canAccess('parking.extensions.review')||!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Only Admin or Super Admin can decide extensions.');
    $note=vpText(['note'=>$note],'note',1000,$decision==='rejected');$actor=(int)$_SESSION['user_id'];
    if($manageTransaction)$db->begin_transaction();try{
        // Always lock reservation before extension to match gate and monitoring order.
        $s=$db->prepare('SELECT parking_request_id FROM parking_extensions WHERE id=?');$s->bind_param('i',$id);$s->execute();$link=$s->get_result()->fetch_assoc();if(!$link)throw new InvalidArgumentException('Extension request not found.');
        $s=$db->prepare("SELECT * FROM parking_requests WHERE id=? AND status='approved' AND actual_check_out IS NULL FOR UPDATE");$s->bind_param('i',$link['parking_request_id']);$s->execute();$row=$s->get_result()->fetch_assoc();if(!$row)throw new InvalidArgumentException('The parking reservation is no longer active.');
        $s=$db->prepare("SELECT * FROM parking_extensions WHERE id=? AND status='pending' FOR UPDATE");$s->bind_param('i',$id);$s->execute();$extension=$s->get_result()->fetch_assoc();if(!$extension){if($manageTransaction)$db->rollback();return false;}
        $until=null;
        if($decision==='approved'){
            $until=$approvedInput!==''?vpUtcInput($approvedInput):$extension['requested_until'];$start=$row['actual_check_in']?:($row['reservation_start_utc']?:$row['expected_arrival_utc']);
            if($until> $extension['requested_until']||$until<=vpNow()||!vpQuote($start,$until)['extended'])throw new InvalidArgumentException('Confirm a future authorized end within the requested extension period.');
            $s=$db->prepare('SELECT * FROM parking_slots WHERE id=? FOR UPDATE');$s->bind_param('i',$row['slot_id']);$s->execute();$slot=$s->get_result()->fetch_assoc();
            if(!$slot||$slot['slot_type']!=='visitor'||$slot['status']==='maintenance'||($slot['status']==='occupied'&&(!$row['actual_check_in']||(int)$slot['assigned_user_id']!==(int)$row['user_id']||$slot['vehicle_plate']!==$row['vehicle_plate']))||vpSlotConflict($db,(int)$slot['id'],(int)$row['id'],$start,$until))throw new InvalidArgumentException('The requested extension conflicts with parking availability.');
            $s=$db->prepare('UPDATE parking_requests SET authorized_until=GREATEST(COALESCE(authorized_until,?),?),reservation_end_utc=GREATEST(COALESCE(reservation_end_utc,?),?) WHERE id=?');$s->bind_param('ssssi',$until,$until,$until,$until,$row['id']);$s->execute();
        }
        $s=$db->prepare('UPDATE parking_extensions SET status=?,approved_until=?,decision_note=?,decided_by=?,decided_at=UTC_TIMESTAMP(6) WHERE id=? AND status=\'pending\'');$s->bind_param('sssii',$decision,$until,$note,$actor,$id);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('Extension decision changed.');
        if(!logAudit($decision,'parking_extension',$id,'Parking #'.$row['id'].'; authorized end '.($until??'none').'; '.$note,$db))throw new RuntimeException('Extension decision audit failed.');
        vpNotify($db,(int)$row['id'],'extension:'.$id.':'.$decision,'Parking extension '.$decision.'. '.($until?'Authorized until '.vpLocal($until).'. ':$note),true);if($manageTransaction)$db->commit();return true;
    }catch(Throwable $error){if(!$manageTransaction)throw $error;if($manageTransaction)$db->rollback();throw $error;}
}
function vpCancelExtension(mysqli $db,int $id): bool {
    if(!residentHasPermission('resident.parking.request'))return false;$actor=(int)$_SESSION['user_id'];$db->begin_transaction();try{
        $s=$db->prepare('SELECT parking_request_id FROM parking_extensions WHERE id=?');$s->bind_param('i',$id);$s->execute();$link=$s->get_result()->fetch_assoc();if(!$link){$db->rollback();return false;}
        $s=$db->prepare('SELECT id FROM parking_requests WHERE id=? AND user_id=? FOR UPDATE');$s->bind_param('ii',$link['parking_request_id'],$actor);$s->execute();if(!$s->get_result()->fetch_assoc()){$db->rollback();return false;}
        $s=$db->prepare("UPDATE parking_extensions SET status='cancelled',decided_by=?,decided_at=UTC_TIMESTAMP(6),decision_note='Cancelled by sponsoring resident.' WHERE id=? AND status='pending'");$s->bind_param('ii',$actor,$id);$s->execute();if($s->affected_rows!==1){$db->rollback();return false;}if(!logAudit('cancel','parking_extension',$id,'Sponsor cancelled pending extension.',$db))throw new RuntimeException('Extension cancellation audit failed.');$db->commit();return true;
    }catch(Throwable $error){$db->rollback();throw $error;}
}
/** Called by the existing minute worker and by authorized parking views. */
function monitorVisitorParking(mysqli $db): array {
    $counts=['checked'=>0,'changed'=>0];if(!visitorParkingFeesReady($db))return $counts;
    // Pending future reservations can expire without ever entering the property.
    $expired=$db->query("SELECT e.id,e.parking_request_id FROM parking_extensions e JOIN parking_requests pr ON pr.id=e.parking_request_id WHERE pr.actual_check_in IS NULL AND e.status IN ('pending','approved') AND COALESCE(e.approved_until,e.requested_until)<UTC_TIMESTAMP(6) ORDER BY e.requested_until,e.id LIMIT 500")->fetch_all(MYSQLI_ASSOC);
    foreach($expired as$item){$db->begin_transaction();try{
        $s=$db->prepare('SELECT id FROM parking_requests WHERE id=? AND actual_check_in IS NULL FOR UPDATE');$s->bind_param('i',$item['parking_request_id']);$s->execute();if(!$s->get_result()->fetch_assoc()){$db->rollback();continue;}
        $s=$db->prepare("UPDATE parking_extensions SET status='expired' WHERE id=? AND status IN ('pending','approved') AND COALESCE(approved_until,requested_until)<UTC_TIMESTAMP(6)");$s->bind_param('i',$item['id']);$s->execute();
        if($s->affected_rows&&!logAudit('expire','parking_extension',(int)$item['id'],'Requested/authorized end passed before arrival. Original decision retained.',$db,'success',true))throw new RuntimeException('Expiry audit failed.');
        $db->commit();
    }catch(Throwable $error){$db->rollback();throw $error;}}
    $ids=$db->query('SELECT id FROM parking_requests WHERE actual_check_in IS NOT NULL AND actual_check_out IS NULL ORDER BY COALESCE(last_monitored_at,actual_check_in),id LIMIT 500')->fetch_all(MYSQLI_ASSOC);
    foreach($ids as$item){$db->begin_transaction();try{
        $s=$db->prepare('SELECT * FROM parking_requests WHERE id=? AND actual_check_in IS NOT NULL AND actual_check_out IS NULL FOR UPDATE');$s->bind_param('i',$item['id']);$s->execute();$row=$s->get_result()->fetch_assoc();if(!$row){$db->rollback();continue;}$counts['checked']++;$now=vpNow();$compliance=vpCompliance($row,$now);
        // Warn once during the last hour before the standard limit.
        if((empty($row['rate_policy_snapshot'])||json_decode($row['rate_policy_snapshot'],true,512,JSON_THROW_ON_ERROR)['extension_required'])&&vpMicros($now)-vpMicros($row['actual_check_in'])>=23*3600000000)vpNotify($db,(int)$row['id'],'reaching24','Visitor parking is reaching 24 hours. Parking beyond 24 hours requires management approval.');
        if($compliance!==$row['parking_compliance']){
            $s=$db->prepare('UPDATE parking_requests SET parking_compliance=? WHERE id=?');$s->bind_param('si',$compliance,$row['id']);$s->execute();$counts['changed']++;
            if(in_array($compliance,['unapproved_extended','overstayed_approved'],true)){vpNotify($db,(int)$row['id'],$compliance.':'.($row['authorized_until']??'standard'),'Visitor parking requires review: '.str_replace('_',' ',$compliance).'. The vehicle remains parked and fees continue.',true);if(!logAudit('overstay','visitor_parking',(int)$row['id'],$compliance,$db))throw new RuntimeException('Overstay audit failed.');}
        }
        $s=$db->prepare("SELECT id FROM parking_extensions WHERE parking_request_id=? AND status IN ('pending','approved') AND COALESCE(approved_until,requested_until)<? FOR UPDATE");$s->bind_param('is',$row['id'],$now);$s->execute();foreach($s->get_result()as$expired){$e=$db->prepare("UPDATE parking_extensions SET status='expired' WHERE id=?");$e->bind_param('i',$expired['id']);$e->execute();if(!logAudit('expire','parking_extension',(int)$expired['id'],'Authorized/requested end passed. Original decision retained.',$db))throw new RuntimeException('Expiry audit failed.');}
        $seen=$db->prepare('UPDATE parking_requests SET last_monitored_at=? WHERE id=?');$seen->bind_param('si',$now,$row['id']);$seen->execute();
        $db->commit();
    }catch(Throwable $error){$db->rollback();throw $error;}}
    return $counts;
}
