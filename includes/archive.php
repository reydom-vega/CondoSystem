<?php
/** Transactional lifecycle operations; no provider I/O or historical deletions. */
const ARCHIVE_REASONS=['moved_out'=>'Resident Moved Out','turnover'=>'Unit Turnover','relationship_ended'=>'Ownership / Account Relationship Ended','staff_removed'=>'Staff Account Removed','correction'=>'Administrative Correction','other'=>'Other'];

function archiveActor(mysqli $db): array {
    if (!isLoggedIn() || !isSuperAdmin()) throw new DomainException('Archive Management requires Super Admin access.',403);
    $actor=lifecycleQuery($db,'SELECT id,full_name,role,is_active,is_verified,archived_at,session_version FROM users WHERE id=?',[(int)$_SESSION['user_id']])->get_result()->fetch_assoc();
    if (!$actor || $actor['role']!=='superadmin' || !accountRoutineEligible($db,(int)$actor['id']) || (int)$actor['session_version']!==(int)$_SESSION['session_version']) throw new DomainException('Archive access denied.',403);
    return $actor;
}

function archiveUser(mysqli $db,int $id,bool $lock=false): ?array {
    return lifecycleQuery($db,'SELECT id,staff_id,resident_id,full_name,username,email,contact_number,role,account_type,unit_number,unit_owner_id,status,is_active,is_verified,archived_at,archived_by,archive_reason,session_version,created_at FROM users WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->get_result()->fetch_assoc() ?: null;
}

function archiveCents(string $value): int {
    if (!preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/D',$value,$m)) throw new LogicException('Invalid balance.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function archiveMoney(int $cents): string { return sprintf('%d.%02d',intdiv($cents,100),$cents%100); }

function archiveUnitMembers(mysqli $db,string $unit,bool $lock=false): array {
    $members=[];
    foreach ($db->query("SELECT id,unit_number FROM users WHERE role='resident' AND status='approved' AND is_active=1 AND archived_at IS NULL ORDER BY id".($lock?' FOR UPDATE':'')) as $row) {
        if (normalizeUnitNumber($row['unit_number'])===$unit) $members[(int)$row['id']]=archiveUser($db,(int)$row['id'],$lock);
    }
    foreach (lifecycleQuery($db,"SELECT r.user_id FROM account_unit_relationships r JOIN users u ON u.id=r.user_id WHERE r.unit_number=? AND r.ended_at IS NULL AND u.is_active=1 AND u.archived_at IS NULL ORDER BY r.user_id".($lock?' FOR UPDATE':''),[$unit])->get_result() as $row) $members[(int)$row['user_id']]=archiveUser($db,(int)$row['user_id'],$lock);
    return array_values($members);
}

function archiveImpactForUser(mysqli $db,array $user,string $unit,bool $lock=false): array {
    $id=(int)$user['id'];$suffix=$lock?' FOR UPDATE':'';
    $fetch=static fn(string $sql,array $params=[])=>lifecycleQuery($db,$sql.$suffix,$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    $relationships=$fetch('SELECT id,user_id,unit_number,relationship_kind,owner_id,started_at,occupancy_ended_at,ended_at FROM account_unit_relationships WHERE user_id=? ORDER BY id',[$id]);
    $vehicles=$fetch("SELECT id,plate_number,make,model,status,parking_slot_id,authorization_revoked_at,unit_number_snapshot FROM vehicles WHERE user_id=? AND COALESCE(unit_number_snapshot,?)=? ORDER BY id",[$id,normalizeUnitNumber($user['unit_number']),$unit]);
    $slots=$fetch('SELECT id,slot_code,slot_type,status,assigned_user_id,assigned_unit,vehicle_plate FROM parking_slots WHERE assigned_user_id=? ORDER BY id',[$id]);
    // An untracked physical occupancy can never be guessed to have left.
    $activeParking=$fetch('SELECT id,vehicle_plate,slot_id,active_parking_slot,actual_check_in,actual_check_out,billing_user_id,visitor_registration_id FROM parking_requests WHERE user_id=? AND actual_check_in IS NOT NULL AND actual_check_out IS NULL ORDER BY id',[$id]);
    $services=$fetch("SELECT id,request_kind,visitor_name,status,start_date,end_date,visitor_log_id FROM resident_service_requests WHERE user_id=? AND COALESCE(unit_number_snapshot,?)=? AND status IN ('pending','approved','changes_requested','checked_in') ORDER BY id",[$id,normalizeUnitNumber($user['unit_number']),$unit]);
    $parking=$fetch("SELECT id,request_type,status,vehicle_plate,slot_id,actual_check_in FROM parking_requests WHERE user_id=? AND COALESCE(sponsor_unit_snapshot,?)=? AND status IN ('pending','approved') ORDER BY id",[$id,normalizeUnitNumber($user['unit_number']),$unit]);
    $bookings=$fetch("SELECT id,amenity,booking_date,status,payment_id FROM bookings WHERE user_id=? AND COALESCE(unit_number,?)=? AND booking_date>=CURRENT_DATE() AND status IN ('pending','approved','confirmed') ORDER BY id",[$id,normalizeUnitNumber($user['unit_number']),$unit]);
    $maintenance=$fetch("SELECT id,issue_type,status FROM maintenance_requests WHERE user_id=? AND status IN ('pending','approved','in_progress','reopened') ORDER BY id",[$id]);
    $balance=lifecycleQuery($db,"SELECT COALESCE(SUM(amount),0) amount,COUNT(*) bills FROM payments WHERE user_id=? AND status IN ('pending','overdue')",[$id])->get_result()->fetch_assoc();
    $reports=lifecycleQuery($db,'SELECT COUNT(*) n FROM job_reports WHERE user_id=?',[$id])->get_result()->fetch_assoc();
    // Community case identities/content are intentionally not part of this summary.
    $work=lifecycleQuery($db,"SELECT COUNT(*) n FROM community_reports WHERE assigned_reviewer_id=? AND status NOT IN ('resolved','closed','rejected')",[$id])->get_result()->fetch_assoc();
    $pending=count(array_filter($services,static fn($r)=>$r['status']!=='checked_in'))+count(array_filter($parking,static fn($r)=>$r['actual_check_in']===null))+count($bookings);
    return ['account'=>$user,'unit'=>$unit,'relationships'=>$relationships,'vehicles'=>$vehicles,'slots'=>$slots,'active_parking'=>$activeParking,'services'=>$services,'parking'=>$parking,'bookings'=>$bookings,'maintenance'=>$maintenance,'unpaid_balance'=>(string)$balance['amount'],'unpaid_bills'=>(int)$balance['bills'],'filed_reports'=>(int)$reports['n'],'assigned_cases'=>(int)$work['n'],'unused_requests'=>$pending];
}

function archivePreview(mysqli $db,array $input,bool $lock=false): array {
    archiveActor($db);
    $mode=(string)($input['mode']??'');$id=(int)($input['user_id']??0);
    if (!in_array($mode,['vacate_unit','end_occupancy','archive_account','archive_staff'],true)) throw new InvalidArgumentException('Choose a valid archive action.');
    $user=archiveUser($db,$id,$lock);if (!$user) throw new InvalidArgumentException('Account not found.');
    if ($user['role']==='superadmin' || $id===(int)$_SESSION['user_id']) throw new InvalidArgumentException('Super Admin accounts require a separate account-transfer process and cannot be removed here.');
    $unit=normalizeUnitNumber(is_string($input['unit_number']??null)?$input['unit_number']:($user['unit_number']??''));
    if ($mode==='archive_staff' && $user['role']==='resident') throw new InvalidArgumentException('Choose a staff account.');
    if ($mode!=='archive_staff' && $user['role']!=='resident') throw new InvalidArgumentException('Choose a resident relationship.');
    $kind=residentAccountKind($user['account_type']);
    if ($mode==='end_occupancy' && !in_array($kind,['tenant','occupant'],true)) throw new InvalidArgumentException('Ending a tenancy must not remove the unit owner.');
    if ($mode!=='archive_staff' && $unit==='') throw new InvalidArgumentException('This account has no unit relationship to end.');
    if($mode!=='archive_staff'&&normalizeUnitNumber($user['unit_number'])!==$unit&&!lifecycleQuery($db,'SELECT id FROM account_unit_relationships WHERE user_id=? AND unit_number=? AND ended_at IS NULL',[$id,$unit])->get_result()->num_rows)throw new InvalidArgumentException('The selected account has no active relationship with this unit.');
    $targets=($mode==='vacate_unit'||($mode==='archive_account'&&$kind==='owner'))?archiveUnitMembers($db,$unit,$lock):[$user];
    if($mode==='archive_account'&&$kind==='owner')$targets=array_values(array_filter($targets,static fn($row)=>(int)$row['id']===$id||(int)$row['unit_owner_id']===$id));
    if($mode==='vacate_unit'&&!array_filter($targets,static fn($row)=>accountOccupiesUnit($db,(int)$row['id'],$unit)))throw new InvalidArgumentException('This unit is already vacant. Its ended occupancy remains in Archive.');
    if ($mode!=='archive_staff' && !array_filter($targets,static fn($r)=>(int)$r['id']===$id)) throw new InvalidArgumentException('The selected account is not associated with this unit.');
    if ($mode==='vacate_unit' && count(array_filter($targets,static fn($r)=>residentAccountKind($r['account_type'])==='owner'))>1) throw new InvalidArgumentException('Resolve this unit’s ambiguous owner records before marking it vacant.');
    $impacts=[];$totals=['accounts'=>0,'vehicles'=>0,'parking_slots'=>0,'active_visits'=>0,'active_parking'=>0,'unused_requests'=>0,'maintenance'=>0,'unpaid_cents'=>0,'unpaid_bills'=>0,'filed_reports'=>0,'assigned_cases'=>0];
    foreach ($targets as $target) {
        if ($mode==='vacate_unit' && !accountOccupiesUnit($db,(int)$target['id'],$unit) && residentAccountKind($target['account_type'])!=='owner') continue;
        if ($mode!=='archive_staff' && !accountHasUnitEntitlement($db,(int)$target['id'],$unit)) continue;
        $impact=archiveImpactForUser($db,$target,$unit,$lock);$impact['retain_owner']=$mode==='vacate_unit' && residentAccountKind($target['account_type'])==='owner';
        $impacts[]=$impact;$totals['accounts']++;$totals['vehicles']+=count($impact['vehicles']);$totals['parking_slots']+=count($impact['slots']);$totals['active_visits']+=count(array_filter($impact['services'],static fn($r)=>$r['status']==='checked_in'));$totals['active_parking']+=count($impact['active_parking']);$totals['unused_requests']+=$impact['unused_requests'];$totals['maintenance']+=count($impact['maintenance']);$totals['unpaid_cents']+=archiveCents($impact['unpaid_balance']);$totals['unpaid_bills']+=$impact['unpaid_bills'];$totals['filed_reports']+=$impact['filed_reports'];$totals['assigned_cases']+=$impact['assigned_cases'];
    }
    if (!$impacts) throw new InvalidArgumentException('This occupancy has already ended. Review its Archive record.');
    $result=['mode'=>$mode,'unit'=>$unit,'targets'=>$impacts,'totals'=>$totals];
    $result['preview_hash']=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));return $result;
}

function archiveRevokeCredentials(mysqli $db,int $id): void {
    lifecycleQuery($db,'UPDATE users SET session_version=session_version+1,reset_token=NULL,reset_expires=NULL,verification_token=NULL,verification_expires=NULL,phone_otp=NULL,phone_otp_expires=NULL WHERE id=?',[$id]);
    lifecycleQuery($db,'DELETE FROM remember_tokens WHERE user_id=?',[$id]);
    lifecycleQuery($db,'UPDATE digital_id_credentials SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6)) WHERE user_id=?',[$id]);
}

function archiveRevokeOccupancyResources(mysqli $db,array $impact,string $reason,bool $parkingCleared): void {
    $user=$impact['account'];$id=(int)$user['id'];$unit=$impact['unit'];$primary=normalizeUnitNumber($user['unit_number']);
    // Explicitly confirmed cancellation affects unused authorizations only.
    lifecycleQuery($db,"UPDATE resident_service_requests SET status='cancelled' WHERE user_id=? AND COALESCE(unit_number_snapshot,?)=? AND status IN ('pending','approved','changes_requested')",[$id,$primary,$unit]);
    lifecycleQuery($db,"UPDATE parking_requests SET status='cancelled' WHERE user_id=? AND COALESCE(sponsor_unit_snapshot,?)=? AND status IN ('pending','approved') AND actual_check_in IS NULL",[$id,$primary,$unit]);
    foreach ($impact['parking'] as $parking) if ($parking['actual_check_in']===null) vpCancelPendingExtensions($db,(int)$parking['id'],'Occupancy ended: '.mb_substr($reason,0,300));
    // Do not change any payment status, gateway result or recorded amount.
    foreach ($impact['bookings'] as $booking) lifecycleQuery($db,"UPDATE bookings SET status='cancelled',cancellation_note=COALESCE(cancellation_note,?) WHERE id=? AND status IN ('pending','approved','confirmed')",['Occupancy ended: '.mb_substr($reason,0,300).'. Financial history retained; any charge requires review.',$booking['id']]);
    if ($impact['retain_owner']) return; // Contractual owner parking rights survive physical vacancy.
    $vehicleIds=array_column($impact['vehicles'],'id');
    foreach ($vehicleIds as $vehicle) {
        $revoked=lifecycleQuery($db,'UPDATE vehicles SET authorization_revoked_at=UTC_TIMESTAMP(6),authorization_revocation_reason=? WHERE id=? AND authorization_revoked_at IS NULL',[$reason,$vehicle]);
        if($revoked->affected_rows && !logAudit('revoke','vehicle',(int)$vehicle,'Vehicle authorization ended with account #'.$id.' / unit '.$unit.'. Reason: '.mb_substr($reason,0,200),$db))throw new RuntimeException('Vehicle revocation could not be audited.');
        $stickers=lifecycleQuery($db,'UPDATE parking_sticker_vehicles SET revoked_at=UTC_TIMESTAMP(6),revocation_reason=? WHERE vehicle_id=? AND revoked_at IS NULL',[$reason,$vehicle]);
        if($stickers->affected_rows && !logAudit('revoke','parking_sticker',(int)$vehicle,'Sticker authorization revoked for vehicle #'.$vehicle.' / account #'.$id.'. Reason: '.mb_substr($reason,0,200),$db))throw new RuntimeException('Sticker revocation could not be audited.');
    }
    lifecycleQuery($db,"UPDATE parking_sticker_orders o SET authorization_revoked_at=UTC_TIMESTAMP(6),authorization_revocation_reason=? WHERE o.authorization_revoked_at IS NULL AND (o.user_id=? OR EXISTS(SELECT 1 FROM parking_sticker_vehicles v WHERE v.order_id=o.id AND v.revoked_at IS NOT NULL)) AND NOT EXISTS(SELECT 1 FROM parking_sticker_vehicles v WHERE v.order_id=o.id AND v.revoked_at IS NULL)",[$reason,$id]);
    foreach ($impact['slots'] as $slot) {
        if (normalizeUnitNumber($slot['assigned_unit'])!==$unit) continue;
        $active=lifecycleQuery($db,'SELECT id FROM parking_requests WHERE (slot_id=? OR active_parking_slot=?) AND actual_check_in IS NOT NULL AND actual_check_out IS NULL LIMIT 1 FOR UPDATE',[$slot['id'],$slot['id']])->get_result()->num_rows;
        if ($active) continue; // A real checkout is still required; never fabricate an exit.
        $other=lifecycleQuery($db,'SELECT id FROM vehicles WHERE parking_slot_id=? AND user_id<>? AND authorization_revoked_at IS NULL LIMIT 1 FOR UPDATE',[$slot['id'],$id])->get_result()->num_rows;
        if ($other) continue;
        if ($slot['status']==='occupied' && !$parkingCleared) throw new InvalidArgumentException('Inspect the assigned resident slots and confirm they are physically empty before releasing them. Active visitor sessions will remain occupied until actual checkout.');
        lifecycleQuery($db,"UPDATE parking_slots SET assigned_user_id=NULL,assigned_unit=NULL,vehicle_plate=NULL,status=IF(status='maintenance','maintenance','available') WHERE id=? AND assigned_user_id=?",[$slot['id'],$id]);
        lifecycleQuery($db,'UPDATE vehicles SET parking_slot_id=NULL WHERE user_id=? AND parking_slot_id=?',[$id,$slot['id']]);
        if(!logAudit('release','parking_slot',(int)$slot['id'],'Ended assignment for account #'.$id.' / unit '.$unit.'; no active parked vehicle. Reason: '.mb_substr($reason,0,200),$db))throw new RuntimeException('Parking release could not be audited.');
    }
}

function archiveApply(mysqli $db,array $input): array {
    $actor=archiveActor($db);$key=(string)($input['request_key']??'');
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)) throw new InvalidArgumentException('Refresh the confirmation before trying again.');
    $reasonCode=(string)($input['reason_code']??'');$reason=is_string($input['reason']??null)?trim($input['reason']):'';
    if (!isset(ARCHIVE_REASONS[$reasonCode]) || mb_strlen($reason)<3 || mb_strlen($reason)>1000 || ($input['confirmed']??'')!=='1') throw new InvalidArgumentException('Choose an archive reason, provide a short explanation, and confirm the impact summary.');
    $db->begin_transaction();
    try {
        $existing=lifecycleQuery($db,'SELECT id,user_id,event_kind,former_unit,snapshot FROM account_archive_events WHERE request_key=?',[$key])->get_result()->fetch_assoc();
        if ($existing) {
            $saved=json_decode($existing['snapshot'],true,512,JSON_THROW_ON_ERROR);
            if ((int)$existing['user_id']!==(int)($input['user_id']??0) || $existing['event_kind']==='restore' || ($saved['archive_mode']??'')!==($input['mode']??'') || normalizeUnitNumber($existing['former_unit'])!==normalizeUnitNumber($input['unit_number']??'')) throw new InvalidArgumentException('This confirmation was already used for another action or relationship.');
            $db->commit();return ['event_id'=>(int)$existing['id'],'repeated'=>true];
        }
        $target=archiveUser($db,(int)($input['user_id']??0),true);
        if ($target && $target['archived_at']!==null) {
            $previous=lifecycleQuery($db,"SELECT id FROM account_archive_events WHERE user_id=? AND event_kind='archive' ORDER BY id DESC LIMIT 1",[$target['id']])->get_result()->fetch_assoc();
            if ($previous) {$db->commit();return ['event_id'=>(int)$previous['id'],'repeated'=>true];}
        }
        $preview=archivePreview($db,$input,true);
        if (!is_string($input['preview_hash']??null) || !hash_equals($preview['preview_hash'],$input['preview_hash'])) throw new InvalidArgumentException('Linked records changed. Refresh the impact summary and confirm again.');
        if ($preview['totals']['unused_requests']>0 && ($input['cancel_unused']??'')!=='1') throw new InvalidArgumentException('Confirm cancellation of unused visitor/permit/parking authorizations and future bookings. Maintenance, visits in progress and financial records remain for staff review.');
        $events=[];$primaryEvent=null;
        foreach ($preview['targets'] as $index=>$impact) {
            $user=$impact['account'];$id=(int)$user['id'];$unit=$impact['unit'];
            recordAccountUnitSnapshots($db,$id,normalizeUnitNumber($user['unit_number']));
            if ($preview['mode']!=='archive_staff') {
                archiveEnsureLegacyRelationship($db,$user);
                $relationship=lifecycleQuery($db,'SELECT id FROM account_unit_relationships WHERE user_id=? AND unit_number=? AND ended_at IS NULL FOR UPDATE',[$id,$unit])->get_result()->fetch_assoc();
                if (!$relationship) throw new InvalidArgumentException('The unit relationship changed. Refresh the account before continuing.');
                $relationshipId=(int)$relationship['id'];
                lifecycleQuery($db,'UPDATE account_unit_relationships SET occupancy_ended_at=COALESCE(occupancy_ended_at,UTC_TIMESTAMP(6)),ended_at='.($impact['retain_owner']?'ended_at':'UTC_TIMESTAMP(6)').',ended_by=?,end_reason=? WHERE id=?',[$actor['id'],$reason,$relationshipId]);
                archiveRevokeOccupancyResources($db,$impact,$reason,($input['parking_cleared']??'')==='1');
                $remaining=lifecycleQuery($db,'SELECT unit_number,relationship_kind,owner_id FROM account_unit_relationships WHERE user_id=? AND ended_at IS NULL ORDER BY (unit_number=?) DESC,id FOR UPDATE',[$id,normalizeUnitNumber($user['unit_number'])])->get_result()->fetch_all(MYSQLI_ASSOC);
            } else {$relationshipId=null;$remaining=[];}
            $archived=!$remaining;
            if ($archived) {
                lifecycleQuery($db,'UPDATE users SET is_active=0,archived_at=UTC_TIMESTAMP(6),archived_by=?,archive_reason=?,password_hash=? WHERE id=?',[$actor['id'],$reason,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$id]);
                lifecycleQuery($db,"UPDATE notification_outbox SET status='cancelled',last_error='Suppressed: account archived',locked_at=NULL WHERE user_id=? AND status IN ('pending','failed')",[$id]);
            } else {
                $first=$remaining[0];$type=['owner'=>'Resident Owner','tenant'=>'Tenant','occupant'=>'Family/Relative of the Owner'][$first['relationship_kind']];
                lifecycleQuery($db,'UPDATE users SET unit_number=?,resident_id=?,unit_owner_id=?,account_type=? WHERE id=?',[$first['unit_number'],$first['unit_number'],$first['relationship_kind']==='owner'?null:$first['owner_id'],$type,$id]);
            }
            archiveRevokeCredentials($db,$id);
            $category=$user['role']!=='resident'?'staff':(residentAccountKind($user['account_type'])==='tenant'?'tenants':'residents');
            $eventKey=$id===(int)$input['user_id']?$key:hash('sha256',$key.':'.$id);
            $eventKind=$archived?'archive':'occupancy_ended';
            $snapshot=$impact;unset($snapshot['account']['session_version']);$snapshot['archive_mode']=$preview['mode'];$snapshot['outcome']=$archived?'Account archived':($impact['retain_owner']?'Occupancy ended; legal ownership and billing responsibility retained':'Ended unit relationship; other active entitlement retained');
            lifecycleQuery($db,'INSERT INTO account_archive_events(user_id,relationship_id,category,event_kind,account_archived,former_unit,former_role,reason_code,reason,actor_id,actor_name,request_key,snapshot,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$relationshipId,$category,$eventKind,$archived?1:0,$unit?:null,$user['role'],$reasonCode,$reason,$actor['id'],$actor['full_name'],$eventKey,json_encode($snapshot,JSON_THROW_ON_ERROR)]);
            $event=(int)$db->insert_id;$events[]=$event;if($id===(int)$input['user_id'])$primaryEvent=$event;
            $details='Archive #'.$event.'; previous active='.(int)$user['is_active'].', unit='.$unit.', role='.$user['role'].'; new '.($archived?'archived':'active with ended occupancy').'; ownership retained='.(int)$impact['retain_owner'].'; active visits retained='.count(array_filter($impact['services'],static fn($r)=>$r['status']==='checked_in')).'; parking inspected empty='.(int)(($input['parking_cleared']??'')==='1').'; balance='.$impact['unpaid_balance'].'; reason='.mb_substr($reason,0,180);
            if (!logAudit($archived?'archive':'end_occupancy','account_archive',$id,$details,$db)) throw new RuntimeException('The archive action could not be audited.');
        }
        if ($preview['mode']==='vacate_unit' && !logAudit('mark_vacant','unit',(int)$input['user_id'],'Unit '.$preview['unit'].' occupancy ended. Ownership, financial records and active visits preserved. Reason: '.mb_substr($reason,0,300),$db)) throw new RuntimeException('Unit vacancy audit failed.');
        $db->commit();
        archiveNotifyStaff($db,$preview,$primaryEvent??$events[0]);
        return ['event_id'=>$primaryEvent??$events[0],'event_ids'=>$events,'repeated'=>false];
    } catch (Throwable $error) {$db->rollback();throw $error;}
}

/** Post-commit follow-up alerts contain no case identities, balances or credentials. */
function archiveNotifyStaff(mysqli $db,array $preview,int $event): void {
    try{
        $roles=['superadmin'];$t=$preview['totals'];
        if($t['active_visits']||$t['active_parking'])array_push($roles,'admin','security');
        if($t['maintenance'])array_push($roles,'admin','maintenance');
        if($t['unpaid_bills']||array_filter($preview['targets'],static fn($target)=>(bool)$target['bookings']))$roles[]='treasurer';
        if(!$t['active_visits']&&!$t['active_parking']&&!$t['maintenance']&&!$t['unpaid_bills']&&!$t['assigned_cases']&&!$t['filed_reports']&&!array_filter($preview['targets'],static fn($target)=>(bool)$target['bookings']))return;
        $roles=array_unique($roles);$placeholders=implode(',',array_fill(0,count($roles),'?'));
        foreach(lifecycleQuery($db,"SELECT id,email,role FROM users WHERE role IN ($placeholders) AND is_active=1 AND is_verified=1 AND archived_at IS NULL",$roles)->get_result() as $recipient){
            $link=buildUrl(dashboardPathForRole($recipient['role']));
            $html=emailLayout('Management follow-up','Account lifecycle review required','<p>A confirmed occupancy or account change has linked work or obligations requiring review. Existing visits must receive actual checkout; unfinished work and financial history remain preserved.</p><p>Sign in to review the records available to your role.</p><p><a href="'.htmlspecialchars($link,ENT_QUOTES,'UTF-8').'">Open your dashboard</a></p>');
            queueNotification($db,'archive-review:'.$event.':staff:'.$recipient['id'],'email',$recipient['email'],'[The Celandine] Account lifecycle follow-up',$html,(int)$recipient['id'],'archive_review',$event);
        }
    }catch(Throwable $error){error_log('Archive committed; follow-up notification could not be queued: '.get_class($error));}
}

function archiveList(mysqli $db,array $input): array {
    archiveActor($db);
    $category=in_array($input['tab']??'',['residents','tenants','staff'],true)?$input['tab']:'residents';
    $search=is_string($input['search']??null)?trim($input['search']):'';
    if (mb_strlen($search)>120) throw new InvalidArgumentException('Search must be 120 characters or fewer.');
    $where="a.category=? AND a.event_kind<>'restore'";$parameters=[$category];
    if ($search!=='') {
        $where.=" AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR CAST(a.user_id AS CHAR) LIKE ? OR u.staff_id LIKE ? OR u.resident_id LIKE ? OR a.former_unit LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.account.full_name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.account.email')) LIKE ? OR CONCAT('Account #',a.user_id) LIKE ? OR CAST(a.id AS CHAR) LIKE ? OR EXISTS(SELECT 1 FROM vehicles v WHERE v.user_id=a.user_id AND v.plate_number LIKE ?))";
        $like='%'.addcslashes($search,'%_\\').'%';for($i=0;$i<12;$i++)$parameters[]=$like;
    }
    foreach (['reason_code'=>'a.reason_code','role'=>'a.former_role','archived_by'=>'a.actor_id','unit'=>'a.former_unit'] as $key=>$column) {
        $value=$input[$key]??'';if(!is_string($value)||strlen($value)>60)throw new InvalidArgumentException('Invalid archive filter.');
        if($value!==''){$where.=" AND $column=?";$parameters[]=$key==='unit'?normalizeUnitNumber($value):$value;}
    }
    foreach (['from'=>'>=','to'=>'<'] as $key=>$operator) {
        $value=$input[$key]??'';if($value==='')continue;
        if(!is_string($value)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value))throw new InvalidArgumentException('Choose valid archive dates.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Manila'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Choose valid archive dates.');
        if($key==='to')$date=$date->modify('+1 day');
        $where.=" AND a.created_at $operator ?";$parameters[]=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    $total=(int)lifecycleQuery($db,"SELECT COUNT(*) n FROM account_archive_events a JOIN users u ON u.id=a.user_id WHERE $where",$parameters)->get_result()->fetch_assoc()['n'];
    $pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($input['page']??1)));$offset=($page-1)*25;
    $rows=lifecycleQuery($db,"SELECT a.id,a.user_id,a.category,a.event_kind,a.account_archived,a.former_unit,a.former_role,a.reason_code,a.reason,a.actor_name,a.created_at,a.snapshot,u.archived_at AS current_archived_at,u.is_active AS current_active,r.started_at,r.occupancy_ended_at,former_owner.full_name AS former_owner_name FROM account_archive_events a JOIN users u ON u.id=a.user_id LEFT JOIN account_unit_relationships r ON r.id=a.relationship_id LEFT JOIN users former_owner ON former_owner.id=r.owner_id WHERE $where ORDER BY a.created_at DESC,a.id DESC LIMIT 25 OFFSET $offset",$parameters)->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($rows as &$row){$snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$row['account']=$snapshot['account'];$row['outcome']=$snapshot['outcome']??'';unset($row['snapshot']);}unset($row);
    $counts=['residents'=>0,'tenants'=>0,'staff'=>0];
    foreach($db->query("SELECT IF(u.role<>'resident','staff',IF(LOWER(TRIM(COALESCE(u.account_type,'')))='tenant','tenants','residents')) category,COUNT(*) n FROM users u WHERE u.archived_at IS NOT NULL GROUP BY category") as $row)$counts[$row['category']]=(int)$row['n'];
    return ['category'=>$category,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'counts'=>$counts];
}

function archiveDetails(mysqli $db,int $eventId,array $input=[]): array {
    archiveActor($db);
    $event=lifecycleQuery($db,"SELECT id,user_id,relationship_id,category,event_kind,account_archived,former_unit,former_role,reason_code,reason,actor_id,actor_name,created_at,snapshot FROM account_archive_events WHERE id=?",[$eventId])->get_result()->fetch_assoc();
    if(!$event)throw new InvalidArgumentException('Archive record not found.');
    $event['snapshot']=json_decode($event['snapshot'],true,512,JSON_THROW_ON_ERROR);$id=(int)$event['user_id'];
    $event['account']=archiveUser($db,$id);unset($event['account']['session_version']);
    $queries=[
        'relationships'=>['SELECT id,unit_number,relationship_kind,owner_id,started_at,recorded_at,occupancy_ended_at,ended_at,end_reason FROM account_unit_relationships WHERE user_id=?','account_unit_relationships','user_id'],
        'vehicles'=>['SELECT id,plate_number,make,model,color,year,status,unit_number_snapshot,authorization_revoked_at,authorization_revocation_reason FROM vehicles WHERE user_id=?','vehicles','user_id'],
        'parking'=>['SELECT pr.id,pr.vehicle_plate,pr.request_type,pr.status,ps.slot_code,pr.created_at,pr.actual_check_in,pr.actual_check_out,pr.parking_fee,pr.fee_state,pr.payment_id,pr.billing_user_id,pr.sponsor_unit_snapshot FROM parking_requests pr LEFT JOIN parking_slots ps ON ps.id=pr.slot_id WHERE pr.user_id=?','parking_requests','user_id'],
        'stickers'=>['SELECT id,quantity,amount,bill_payment_id,status,claim_status,created_at,issued_at,authorization_revoked_at,authorization_revocation_reason FROM parking_sticker_orders WHERE user_id=?','parking_sticker_orders','user_id'],
        'visitors'=>["SELECT id,request_kind,visitor_name,visitor_count,permit_type,start_date,end_date,status,unit_number_snapshot,created_at FROM resident_service_requests WHERE user_id=?",'resident_service_requests','user_id'],
        'maintenance'=>['SELECT id,issue_type,location,status,urgency,unit_number_snapshot,created_at FROM maintenance_requests WHERE user_id=?','maintenance_requests','user_id'],
        'messages'=>['SELECT id,sender_role,body,attachment_name,created_at FROM messages WHERE user_id=?','messages','user_id'],
        'bookings'=>['SELECT id,amenity,booking_date,booking_time,end_time,status,unit_number,payment_id,cancellation_note FROM bookings WHERE user_id=?','bookings','user_id'],
        'billing'=>['SELECT id,amount,status,billing_scope,unit_number_snapshot,due_date,paid_at,payment_method,paymongo_payment_id FROM payments WHERE user_id=?','payments','user_id'],
        'violations'=>['SELECT id,violation_type,status,review_status,fine_amount,payment_id,unit_snapshot,created_at FROM violations WHERE user_id=?','violations','user_id'],
        'job_reports'=>['SELECT id,reference,staff_id,staff_name,staff_role,report_type,period_start,period_end,version,status,filed_at FROM job_reports WHERE user_id=?','job_reports','user_id'],
        'audit'=>["SELECT id,admin_name,admin_role,action,entity_type,entity_id,outcome,created_at FROM audit_logs WHERE admin_id=? OR (entity_id=? AND entity_type IN ('user','staff','account_archive'))",null,null],
        'events'=>['SELECT id,event_kind,former_unit,former_role,reason,actor_name,created_at FROM account_archive_events WHERE user_id=?','account_archive_events','user_id'],
    ];
    $history=[];
    foreach($queries as $key=>[$sql,$table,$column]) {
        $parameters=$key==='audit'?[$id,$id]:[$id];
        $total=(int)lifecycleQuery($db,'SELECT COUNT(*) n FROM ('.$sql.') archive_rows',$parameters)->get_result()->fetch_assoc()['n'];
        if(!$total)continue;
        $pages=max(1,(int)ceil($total/25));$page=($input['section']??'')===$key?min($pages,max(1,(int)($input['history_page']??1))):1;$offset=($page-1)*25;
        $history[$key]=['rows'=>lifecycleQuery($db,$sql.' ORDER BY id DESC LIMIT 25 OFFSET '.$offset,$parameters)->get_result()->fetch_all(MYSQLI_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    $event['history']=$history;
    $event['balance']=lifecycleQuery($db,"SELECT COALESCE(SUM(amount),0) outstanding,COUNT(*) bills FROM payments WHERE user_id=? AND status IN ('pending','overdue')",[$id])->get_result()->fetch_assoc();
    $event['active_visits']=lifecycleQuery($db,"SELECT id,visitor_name,visitor_log_id FROM resident_service_requests WHERE user_id=? AND status='checked_in'",[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
    return $event;
}

function archiveRestore(mysqli $db,array $input): array {
    $actor=archiveActor($db);$id=(int)($input['user_id']??0);$reason=is_string($input['reason']??null)?trim($input['reason']):'';
    $key=is_string($input['request_key']??null)?$input['request_key']:'';$password=is_string($input['new_password']??null)?$input['new_password']:'';
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)||mb_strlen($reason)<3||mb_strlen($reason)>1000||($input['confirmed']??'')!=='1'||!isPasswordStrong($password))throw new InvalidArgumentException('Confirm restoration, provide a reason and set a strong new password. Old sessions and credentials will remain revoked.');
    $unit=normalizeUnitNumber(is_string($input['unit_number']??null)?$input['unit_number']:'');
    $lockName='condo_unit_'.substr(hash('sha256',$db->query('SELECT DATABASE() n')->fetch_assoc()['n'].':'.$unit),0,40);
    if((int)lifecycleQuery($db,'SELECT GET_LOCK(?,10) acquired',[$lockName])->get_result()->fetch_assoc()['acquired']!==1)throw new RuntimeException('Unit assignment is busy. Try again.');
    try{
        $db->begin_transaction();
        $existing=lifecycleQuery($db,"SELECT id,user_id,event_kind FROM account_archive_events WHERE request_key=?",[$key])->get_result()->fetch_assoc();
        if($existing){if((int)$existing['user_id']!==$id||$existing['event_kind']!=='restore')throw new InvalidArgumentException('This confirmation has already been used.');$db->commit();return ['event_id'=>(int)$existing['id'],'repeated'=>true];}
        $user=archiveUser($db,$id,true);
        $resumeOwner=$user&&$user['archived_at']===null&&(int)$user['is_active']===1&&$user['role']==='resident'&&residentAccountKind($user['account_type'])==='owner'&&lifecycleQuery($db,"SELECT id FROM account_unit_relationships WHERE user_id=? AND unit_number=? AND relationship_kind='owner' AND ended_at IS NULL AND occupancy_ended_at IS NOT NULL FOR UPDATE",[$id,$unit])->get_result()->num_rows>0;
        if(!$user||($user['archived_at']===null&&!$resumeOwner)||$user['role']==='superadmin'||(int)$user['is_verified']!==1)throw new InvalidArgumentException('Choose an archived, verified account or a retained owner whose occupancy has ended.');
        $duplicate=lifecycleQuery($db,'SELECT id FROM users WHERE id<>? AND (username=? OR email=?) LIMIT 1 FOR UPDATE',[$id,$user['username'],$user['email']])->get_result()->num_rows;
        if($duplicate)throw new InvalidArgumentException('Resolve the identity conflict before restoring this account.');
        $relationshipId=null;$ownerId=null;$role=$user['role'];
        if($role==='resident'){
            if(!in_array($unit,array_column(loadUnitInventory(),'unit_number'),true))throw new InvalidArgumentException('Choose a valid unit from the property inventory.');
            $kind=residentAccountKind($user['account_type']);if($kind==='unknown')throw new InvalidArgumentException('Review the account relationship before restoring.');
            $members=archiveUnitMembers($db,$unit,true);
            $owners=array_values(array_filter($members,static fn($r)=>residentAccountKind($r['account_type'])==='owner'));
            if($kind==='owner' && $members && (!$resumeOwner||array_filter($members,static fn($member)=>(int)$member['id']!==$id)))throw new InvalidArgumentException('This unit already has an active ownership or occupancy relationship. Restoration cannot overwrite it.');
            if($resumeOwner){
                if(isset(activeUnitPopulation($db)[$unit]))throw new InvalidArgumentException('Current occupants must not be overwritten when resuming the owner’s occupancy.');
                lifecycleQuery($db,"UPDATE account_unit_relationships SET ended_at=UTC_TIMESTAMP(6),ended_by=?,end_reason=? WHERE user_id=? AND unit_number=? AND relationship_kind='owner' AND ended_at IS NULL",[$actor['id'],'Ownership carried forward into a new occupancy period. '.mb_substr($reason,0,900),$id,$unit]);
            }
            if($kind!=='owner'){
                if(count($owners)!==1 || count(array_filter($members,static fn($r)=>residentAccountKind($r['account_type'])!=='owner'))>0)throw new InvalidArgumentException('A tenant requires one active owner and an available tenancy. Current occupants will not be replaced.');
                $ownerId=(int)$owners[0]['id'];
            }
            lifecycleQuery($db,'INSERT INTO account_unit_relationships(user_id,unit_number,relationship_kind,owner_id,started_at,recorded_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$id,$unit,$kind,$kind==='owner'?$id:$ownerId]);$relationshipId=(int)$db->insert_id;
        }else{
            $role=is_string($input['role']??null)?$input['role']:'';
            if(!in_array($role,['admin','security','maintenance','treasurer'],true))throw new InvalidArgumentException('Explicitly approve a supported staff role. Super Admin access cannot be restored through this process.');
            $unit=$user['unit_number'];
        }
        recordAccountUnitSnapshots($db,$id,normalizeUnitNumber($user['unit_number']));
        archiveRevokeCredentials($db,$id);
        lifecycleQuery($db,"UPDATE users SET is_active=1,archived_at=NULL,archived_by=NULL,archive_reason=NULL,role=?,status='approved',unit_number=?,resident_id=IF(?='resident',?,resident_id),unit_owner_id=?,password_hash=?,failed_login_attempts=0,locked_until=NULL WHERE id=?",[$role,$unit,$role,$unit,$ownerId,password_hash($password,PASSWORD_DEFAULT),$id]);
        $snapshot=['account'=>array_diff_key($user,['session_version'=>true]),'outcome'=>'Restored with explicitly approved role/relationship. Fresh password required; old QR codes, parking stickers and sessions remain revoked.','approved_role'=>$role,'approved_unit'=>$unit];
        $category=$user['role']!=='resident'?'staff':(residentAccountKind($user['account_type'])==='tenant'?'tenants':'residents');
        lifecycleQuery($db,"INSERT INTO account_archive_events(user_id,relationship_id,category,event_kind,account_archived,former_unit,former_role,reason_code,reason,actor_id,actor_name,request_key,snapshot,created_at) VALUES(?,?,?,'restore',0,?,?,'correction',?,?,?,?,?,UTC_TIMESTAMP(6))",[$id,$relationshipId,$category,$user['unit_number'],$user['role'],$reason,$actor['id'],$actor['full_name'],$key,json_encode($snapshot,JSON_THROW_ON_ERROR)]);
        $event=(int)$db->insert_id;
        if(!logAudit('restore','account_archive',$id,'Restored account. Explicit role '.$role.'; unit '.$unit.'; old credentials remain revoked. Reason: '.mb_substr($reason,0,300),$db))throw new RuntimeException('Restoration could not be audited.');
        $db->commit();return ['event_id'=>$event,'repeated'=>false];
    }catch(Throwable $error){$db->rollback();throw $error;}
    finally{lifecycleQuery($db,'SELECT RELEASE_LOCK(?)',[$lockName]);}
}
