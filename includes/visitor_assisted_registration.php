<?php
/** Staff can prepare a visit, but cannot consent to charges for the sponsor. */
function vpRegisterAssistedVisitor(mysqli $db,array $input,array $files): int {
    if(!canAccess('visitors.review'))throw new InvalidArgumentException('Authorized visitor staff access is required.');
    $sponsor=filter_var($input['sponsor_id']??'',FILTER_VALIDATE_INT);if(!$sponsor)throw new InvalidArgumentException('Select an approved sponsoring account.');
    $payer=vpResponsibleAccount($db,$sponsor);$authority=vpText($input,'registration_authority',1000,true);$actor=(int)$_SESSION['user_id'];
    $identity=visitorIdentityInput($input);$upload=storeVisitorId($files['valid_id']??[]);
    try{$db->begin_transaction();$visitor=insertResidentServiceRequest($db,$sponsor,'visitor',$input,$identity+$upload);
        if(($input['needs_parking']??'')==='1'){
            $rate=vpCurrentRate($db);if(($input['parking_fee_ack']??'')!=='1'||(int)($input['parking_rate_version']??0)!==$rate['id'])throw new InvalidArgumentException('Review and acknowledge the displayed rates as the staff registrar. Sponsor consent is still required separately.');
            $type=vpText($input,'vehicle_type',30,true);if(!in_array($type,['Car','Motorcycle','Van','SUV','Pickup','Other'],true))throw new InvalidArgumentException('Choose a vehicle type.');
            $model=vpText($input,'vehicle_model',60);$color=vpText($input,'vehicle_color',30);$slot=(int)($input['requested_slot_id']??0);if($slot){$s=$db->prepare("SELECT id FROM parking_slots WHERE id=? AND slot_type='visitor' AND status<>'maintenance'");$s->bind_param('i',$slot);$s->execute();if(!$s->get_result()->fetch_assoc())throw new InvalidArgumentException('Select a valid visitor slot.');}
            $slot=$slot?:null;
            $id=insertLinkedVisitorParking($db,$sponsor,$visitor,vpText($input,'vehicle_plate',20,true),vpText($input,'vehicle_description',120),$input['start_date'],$input['start_date'],getParkingPolicy($db));
            $start=vpUtcInput($input['start_date'].'T00:00');$end=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s.u');
            $s=$db->prepare("UPDATE parking_requests SET fee_policy_version=2,vehicle_type=?,vehicle_model=?,vehicle_color=?,reservation_start_utc=?,reservation_end_utc=?,requested_slot_id=?,registration_actor_id=?,registration_authority=?,fee_state='awaiting_sponsor',billing_user_id=?,billing_scope_snapshot=?,sponsor_unit_snapshot=? WHERE id=?");$s->bind_param('sssssiisissi',$type,$model,$color,$start,$end,$slot,$actor,$authority,$payer['id'],$payer['scope'],$payer['unit'],$id);$s->execute();
            vpNotify($db,$id,'sponsor-consent-required','Staff prepared your visitor parking registration. Open Parking & Vehicles > Requests, review the rates, and acknowledge the charges before vehicle entry.');
        }
        if(!logAudit('register_assisted','resident_service_request',$visitor,'Staff-assisted visitor registration; sponsoring account #'.$sponsor.'; authorization: '.$authority,$db))throw new RuntimeException('Assisted registration audit failed.');$db->commit();return $visitor;
    }catch(Throwable $error){$db->rollback();removeVisitorIdFile($upload['name']);throw $error;}
}
