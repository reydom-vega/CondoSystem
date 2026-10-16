<?php
require_once __DIR__.'/visitor_parking_pricing.php';
require_once __DIR__.'/visitor_parking_billing.php';
/** Actual visitor vehicle movements extend parking_requests; no parallel parking ledger. */
function visitorParkingFeeColumns(): array {
    return [
        'fee_policy_version'=>'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'reservation_start_utc'=>'DATETIME(6) DEFAULT NULL',
        'fee_ack_rate_id'=>'INT DEFAULT NULL','fee_acknowledged_by'=>'INT DEFAULT NULL','registration_actor_id'=>'INT DEFAULT NULL',
        'registration_authority'=>'VARCHAR(1000) DEFAULT NULL',
        'rate_version_id'=>'INT DEFAULT NULL','rate_policy_snapshot'=>'LONGTEXT DEFAULT NULL','calculation_snapshot'=>'LONGTEXT DEFAULT NULL',
        'fee_state'=>"VARCHAR(30) NOT NULL DEFAULT 'legacy'",'rate_reviewed_by'=>'INT DEFAULT NULL','rate_reviewed_at'=>'DATETIME(6) DEFAULT NULL','rate_review_reason'=>'VARCHAR(1000) DEFAULT NULL',
        'vehicle_type'=>"VARCHAR(30) DEFAULT NULL",'vehicle_model'=>'VARCHAR(60) DEFAULT NULL','vehicle_color'=>'VARCHAR(30) DEFAULT NULL',
        'expected_arrival_utc'=>'DATETIME(6) DEFAULT NULL','expected_departure_utc'=>'DATETIME(6) DEFAULT NULL','reservation_end_utc'=>'DATETIME(6) DEFAULT NULL',
        'requested_slot_id'=>'INT DEFAULT NULL','fee_acknowledged_at'=>'DATETIME(6) DEFAULT NULL',
        'actual_check_in'=>'DATETIME(6) DEFAULT NULL','actual_check_out'=>'DATETIME(6) DEFAULT NULL',
        'rate_snapshot'=>'DECIMAL(10,2) DEFAULT NULL','billing_user_id'=>'INT DEFAULT NULL','billing_scope_snapshot'=>'VARCHAR(30) DEFAULT NULL',
        'sponsor_unit_snapshot'=>'VARCHAR(30) DEFAULT NULL','visitor_name_snapshot'=>'VARCHAR(120) DEFAULT NULL',
        'authorized_until'=>'DATETIME(6) DEFAULT NULL','parking_compliance'=>"VARCHAR(30) NOT NULL DEFAULT 'normal'",
        'billable_seconds'=>'BIGINT UNSIGNED DEFAULT NULL','billable_hours'=>'BIGINT UNSIGNED DEFAULT NULL','parking_fee'=>'DECIMAL(10,2) DEFAULT NULL',
        'last_monitored_at'=>'DATETIME(6) DEFAULT NULL','payment_id'=>'INT DEFAULT NULL','checked_in_by'=>'INT DEFAULT NULL','checked_out_by'=>'INT DEFAULT NULL',
        'active_parking_plate'=>"VARCHAR(20) GENERATED ALWAYS AS (IF(actual_check_in IS NOT NULL AND actual_check_out IS NULL,REGEXP_REPLACE(UPPER(vehicle_plate),'[^A-Z0-9]',''),NULL)) STORED",
        'active_parking_slot'=>'INT GENERATED ALWAYS AS (IF(actual_check_in IS NOT NULL AND actual_check_out IS NULL,slot_id,NULL)) STORED',
    ];
}
function ensureVisitorParkingFeeSchema(mysqli $db): bool {
    if(!schemaMutationAllowed())return true;
    ensureParkingTables($db);ensurePaymentsTable($db);ensureBillingTables($db);ensureNotificationOutboxTable($db);ensureAuditLogTable($db);
    foreach(visitorParkingFeeColumns()as$name=>$definition)if(!$db->query("SHOW COLUMNS FROM parking_requests LIKE '$name'")->num_rows)$db->query("ALTER TABLE parking_requests ADD COLUMN $name $definition");
    foreach(['payment_id'=>'ux_visitor_parking_bill','active_parking_plate'=>'ux_active_visitor_plate','active_parking_slot'=>'ux_active_visitor_slot']as$column=>$index)if(!$db->query("SHOW INDEX FROM parking_requests WHERE Key_name='$index'")->num_rows)$db->query("ALTER TABLE parking_requests ADD UNIQUE INDEX $index ($column)");
    foreach(['fk_visitor_parking_bill'=>['payment_id','payments'],'fk_visitor_parking_payer'=>['billing_user_id','users']]as$name=>[$column,$table]){
        $found=$db->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='parking_requests' AND CONSTRAINT_NAME='$name'");
        if(!$found->num_rows)$db->query("ALTER TABLE parking_requests ADD CONSTRAINT $name FOREIGN KEY ($column) REFERENCES $table(id) ON DELETE RESTRICT");
    }
    $db->query("CREATE TABLE IF NOT EXISTS parking_extensions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parking_request_id INT NOT NULL,requested_by INT NOT NULL,
        expected_start_utc DATETIME(6) NOT NULL,requested_until DATETIME(6) NOT NULL,approved_until DATETIME(6) DEFAULT NULL,
        reason VARCHAR(1000) NOT NULL,status ENUM('pending','approved','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
        decision_note VARCHAR(1000) DEFAULT NULL,decided_by INT DEFAULT NULL,decided_at DATETIME(6) DEFAULT NULL,
        request_key CHAR(64) NOT NULL UNIQUE,created_at DATETIME(6) NOT NULL,
        pending_request INT GENERATED ALWAYS AS (IF(status='pending',parking_request_id,NULL)) STORED,
        UNIQUE KEY ux_pending_parking_extension(pending_request),INDEX ix_parking_extension(parking_request_id,status),
        CONSTRAINT fk_extension_parking FOREIGN KEY(parking_request_id) REFERENCES parking_requests(id) ON DELETE RESTRICT,
        CONSTRAINT fk_extension_actor FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureVisitorParkingRateSchema($db);
    return true;
}
function visitorParkingFeesReady(mysqli $db): bool {
    if(!$db->query("SHOW TABLES LIKE 'parking_requests'")->num_rows)return false;
    return $db->query("SHOW COLUMNS FROM parking_requests LIKE 'fee_policy_version'")->num_rows>0;
}
function vpNow(): string {return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
function vpMicros(string $utc): int {$date=new DateTimeImmutable($utc,new DateTimeZone('UTC'));return (int)$date->format('U')*1000000+(int)$date->format('u');}
function vpLocal(string $utc): string {return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');}
function vpUtcInput(string $value): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('Asia/Manila'));
    if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException('Choose a valid arrival and departure date/time.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}
function vpQuote(string $start,string $end,?string $rate=null): array {
    if($rate===null)return vpCalculate($start,$end);
    if(!preg_match('/^\d{1,6}\.\d{2}$/D',$rate))throw new InvalidArgumentException('Invalid parking rate.');
    $delta=vpMicros($end)-vpMicros($start);if($delta<0)throw new InvalidArgumentException('Departure must follow arrival.');
    $seconds=intdiv($delta+999999,1000000);$hours=intdiv($seconds+3599,3600);$cents=$hours*(int)str_replace('.','',$rate);
    if($cents>9999999999)throw new InvalidArgumentException('Parking fee exceeds the billing limit. Contact management.');
    return ['seconds'=>$seconds,'hours'=>$hours,'amount'=>sprintf('%d.%02d',intdiv($cents,100),$cents%100),'rate'=>$rate,'extended'=>$delta>86400000000];
}
function vpDuration(int $seconds): string {return intdiv($seconds,86400).'d '.intdiv($seconds%86400,3600).'h '.intdiv($seconds%3600,60).'m '.($seconds%60).'s';}
function vpText(array $input,string $key,int $limit,bool $required=false): string {
    if(!is_string($input[$key]??''))throw new InvalidArgumentException('Invalid parking field.');$text=trim($input[$key]??'');
    $controls=$limit===1000?'/[\x00-\x08\x0b\x0c\x0e-\x1f]/':'/[\x00-\x1f]/';
    if(($required&&$text==='')||mb_strlen($text)>$limit||preg_match($controls,$text))throw new InvalidArgumentException('Check the '.str_replace('_',' ',$key).' field.');return $text;
}
function vpPlanInput(mysqli $db,int $user,array $input): array {
    if(!residentUserHasPermission($db,$user,'resident.parking.request'))throw new InvalidArgumentException('Parking is unavailable for this account.');
    if(($input['parking_fee_ack']??'')!=='1')throw new InvalidArgumentException('Acknowledge the visitor parking fee policy.');
    $date=vpText($input,'start_date',10,true);$last=vpText($input,'end_date',10)?:$date;
    if(!workflowDate($date)||!workflowDate($last)||$date<date('Y-m-d')||$last<$date)throw new InvalidArgumentException('Choose a valid visit date.');
    // Date-only authorization windows are separate from actual movement timestamps.
    $start=vpUtcInput($date.'T00:00');$end=(new DateTimeImmutable(vpUtcInput($last.'T00:00'),new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s.u');
    $rate=vpCurrentRate($db);if((string)($input['parking_rate_version']??'')!==(string)$rate['id'])throw new InvalidArgumentException('Parking rates changed or were not acknowledged. Reload and review the current rates.');
    $type=vpText($input,'vehicle_type',30,true);if(!in_array($type,['Car','Motorcycle','Van','SUV','Pickup','Other'],true))throw new InvalidArgumentException('Choose a vehicle type.');
    $quote=['extended'=>false];$reason='';
    $slot=(int)($input['requested_slot_id']??0);if($slot){$s=$db->prepare("SELECT id FROM parking_slots WHERE id=? AND slot_type='visitor' AND status<>'maintenance'");$s->bind_param('i',$slot);$s->execute();if(!$s->get_result()->fetch_assoc())throw new InvalidArgumentException('Choose a valid visitor parking slot.');}
    return ['start'=>$start,'end'=>$end,'type'=>$type,'model'=>vpText($input,'vehicle_model',60),'color'=>vpText($input,'vehicle_color',30),'slot'=>$slot?:null,'quote'=>$quote,'reason'=>$reason];
}
function vpResponsibleAccount(mysqli $db,int $sponsor): array {
    $context=residentContext($db,$sponsor);if(!$context||!$context['approved'])throw new InvalidArgumentException('Resolve the sponsoring account and unit before parking entry.');
    $payer=$context['account_kind']==='tenant'?$sponsor:(int)$context['billing_user_id'];
    $payerContext=residentContext($db,$payer);if(!$payerContext||!$payerContext['approved']||!in_array($payerContext['account_kind'],['owner','tenant'],true))throw new InvalidArgumentException('Billing responsibility must be resolved by management first.');
    return ['id'=>$payer,'scope'=>$context['account_kind']==='tenant'?'personal_parking':'visitor_parking','unit'=>$context['unit_number']];
}
/** Caller owns the reservation transaction. */
function vpApplyPlan(mysqli $db,int $id,int $user,array $input): void {
    $s=$db->prepare('SELECT start_date,end_date FROM parking_requests WHERE id=? AND user_id=? AND actual_check_in IS NULL FOR UPDATE');$s->bind_param('ii',$id,$user);$s->execute();$dates=$s->get_result()->fetch_assoc();if(!$dates)throw new InvalidArgumentException('This parking request can no longer be changed.');
    $plan=vpPlanInput($db,$user,array_merge($input,$dates));$rate=vpCurrentRate($db);$payer=vpResponsibleAccount($db,$user);$ack=vpNow();$actor=(int)$_SESSION['user_id'];
    if($actor!==$user)throw new InvalidArgumentException('The sponsoring account must acknowledge its own parking charges.');
    $s=$db->prepare("UPDATE parking_requests SET fee_policy_version=2,vehicle_type=?,vehicle_model=?,vehicle_color=?,expected_arrival_utc=NULL,expected_departure_utc=NULL,reservation_start_utc=?,reservation_end_utc=?,requested_slot_id=?,fee_acknowledged_at=?,fee_ack_rate_id=?,fee_acknowledged_by=?,billing_user_id=?,billing_scope_snapshot=?,sponsor_unit_snapshot=?,registration_actor_id=COALESCE(registration_actor_id,?),fee_state='not_started' WHERE id=? AND user_id=? AND actual_check_in IS NULL");
    $s->bind_param('sssssisiiissiii',$plan['type'],$plan['model'],$plan['color'],$plan['start'],$plan['end'],$plan['slot'],$ack,$rate['id'],$actor,$payer['id'],$payer['scope'],$payer['unit'],$actor,$id,$user);$s->execute();
    if($s->affected_rows!==1)throw new InvalidArgumentException('This reservation can no longer be changed.');
    if(!logAudit('acknowledge','visitor_parking',$id,'Sponsor acknowledged parking rate version '.$rate['id'],$db))throw new RuntimeException('Parking consent audit failed.');
    vpNotify($db,$id,'registered-rate:'.$rate['id'],'Visitor parking registered. The first '.intdiv($rate['grace_seconds'],60).' minutes are free; the first '.($rate['base_seconds']/3600).' hours cost PHP '.vpMoney($rate['base_cents']).'. Applicable fees are finalized after Security-confirmed checkout.');
}

function vpCompliance(array $row,string $at): string {
    if(empty($row['actual_check_in']))return 'normal';
    if(!empty($row['rate_policy_snapshot'])&&!json_decode($row['rate_policy_snapshot'],true,512,JSON_THROW_ON_ERROR)['extension_required'])return 'normal';
    $elapsed=vpMicros($at)-vpMicros($row['actual_check_in']);if($elapsed<86400000000)return 'normal';if($elapsed===86400000000)return 'at_24_hours';
    $base=vpMicros($row['actual_check_in'])+86400000000;$authorized=!empty($row['authorized_until'])?vpMicros($row['authorized_until']):$base;
    if($authorized>$base)return vpMicros($at)<=$authorized?'approved_extended':'overstayed_approved';return 'unapproved_extended';
}
/** No provider I/O. Outbox uniqueness makes repeated monitoring safe. */
function vpNotify(mysqli $db,int $id,string $event,string $message,bool $staff=false): void {
    $s=$db->prepare('SELECT pr.user_id,u.full_name,u.email,u.contact_number FROM parking_requests pr JOIN users u ON u.id=pr.user_id WHERE pr.id=?');$s->bind_param('i',$id);$s->execute();$host=$s->get_result()->fetch_assoc();if(!$host)return;
    $recipients=[(int)$host['user_id']=>$host];if($staff)foreach($db->query("SELECT id AS user_id,full_name,email,contact_number FROM users WHERE role IN ('admin','superadmin','security') AND is_active=1 AND archived_at IS NULL AND is_verified=1 AND status='approved'")as$recipient)$recipients[(int)$recipient['user_id']]=$recipient;
    foreach($recipients as$user=>$contact){$html=emailLayout('Visitor Parking','Parking update','<p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p>Parking reference VP-'.str_pad((string)$id,8,'0',STR_PAD_LEFT).'. Review the authorized Parking &amp; Vehicles or Parking Management page.</p>');if(filter_var($contact['email'],FILTER_VALIDATE_EMAIL)&&!queueNotification($db,'visitor-parking:'.$id.':'.$event.':'.$user,'email',$contact['email'],'[The Celandine] Visitor Parking Update',$html,$user,'visitor_parking',$id))throw new RuntimeException('Parking notification could not be queued.');}
}
/** Called only within the existing visitor entry transaction. */
function vpStartForVisitor(mysqli $db,array $visitor,int $logId): void {
    if(!visitorParkingFeesReady($db))return;
    $s=$db->prepare("SELECT * FROM parking_requests WHERE visitor_registration_id=? AND request_type='visitor' AND status='approved' ORDER BY id DESC FOR UPDATE");$s->bind_param('i',$visitor['id']);$s->execute();$row=$s->get_result()->fetch_assoc();if(!$row)return;
    if(!canAccess('parking.gate'))throw new InvalidArgumentException('Only Security may confirm vehicle entry.');
    if((int)$row['fee_policy_version']<2||!$row['fee_acknowledged_at'])throw new InvalidArgumentException('The sponsoring resident must acknowledge the parking fee policy before vehicle entry.');
    if($row['actual_check_in'])throw new InvalidArgumentException('This vehicle parking session has already started.');
    $s=$db->prepare("SELECT * FROM parking_slots WHERE id=? AND slot_type='visitor' AND status='available' AND assigned_user_id IS NULL FOR UPDATE");$s->bind_param('i',$row['slot_id']);$s->execute();$slot=$s->get_result()->fetch_assoc();if(!$slot)throw new InvalidArgumentException('The assigned visitor slot is not available for physical entry.');
    $payer=vpResponsibleAccount($db,(int)$row['user_id']);$now=vpNow();$rate=vpCurrentRate($db,$now);if((int)$row['fee_ack_rate_id']!==(int)$rate['id']||(int)$row['fee_acknowledged_by']!==(int)$row['user_id'])throw new InvalidArgumentException('The sponsoring resident must acknowledge the current parking rate before entry.');$snapshot=json_encode($rate,JSON_THROW_ON_ERROR);$authorized=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d H:i:s.u');
    $reservationEnd=$row['reservation_end_utc']>$now?$row['reservation_end_utc']:$authorized;
    if(vpSlotConflict($db,(int)$row['slot_id'],(int)$row['id'],$now,$reservationEnd))throw new InvalidArgumentException('The vehicle arrival conflicts with another reservation. Ask management to reassign an available slot.');
    $s=$db->prepare("SELECT MAX(approved_until) until_time FROM parking_extensions WHERE parking_request_id=? AND status='approved' AND approved_until>?");$s->bind_param('is',$row['id'],$authorized);$s->execute();$extension=$s->get_result()->fetch_assoc()['until_time'];if($extension)$authorized=$extension;
    $actor=(int)$_SESSION['user_id'];$s=$db->prepare("UPDATE parking_requests SET actual_check_in=?,rate_snapshot=NULL,rate_version_id=?,rate_policy_snapshot=?,fee_state='active',billing_user_id=?,billing_scope_snapshot=?,sponsor_unit_snapshot=?,visitor_name_snapshot=?,authorized_until=?,checked_in_by=? WHERE id=? AND actual_check_in IS NULL");$s->bind_param('sisissssii',$now,$rate['id'],$snapshot,$payer['id'],$payer['scope'],$payer['unit'],$visitor['visitor_name'],$authorized,$actor,$row['id']);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('Vehicle entry changed.');
    $s=$db->prepare("UPDATE parking_slots SET status='occupied',assigned_user_id=?,assigned_unit=?,vehicle_plate=? WHERE id=? AND status='available' AND assigned_user_id IS NULL");$s->bind_param('issi',$row['user_id'],$payer['unit'],$row['vehicle_plate'],$row['slot_id']);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('Parking slot changed.');
    if(!logAudit('check_in','visitor_parking',(int)$row['id'],'Vehicle entry; parking rate version '.$rate['id'],$db,'success'))throw new RuntimeException('Vehicle entry audit failed.');
    vpNotify($db,(int)$row['id'],'checkin','Security confirmed actual vehicle entry. The parking timer and free grace period have started.');
}
/** Caller owns visitor checkout transaction; charge and slot release are atomic. */
function vpFinishForVisitor(mysqli $db,int $visitorId): ?array {
    if(!visitorParkingFeesReady($db))return null;
    $s=$db->prepare('SELECT * FROM parking_requests WHERE visitor_registration_id=? AND actual_check_in IS NOT NULL ORDER BY id DESC FOR UPDATE');$s->bind_param('i',$visitorId);$s->execute();$row=$s->get_result()->fetch_assoc();if(!$row)return null;
    if(!canAccess('parking.gate'))throw new InvalidArgumentException('Only Security may confirm vehicle checkout.');
    if($row['actual_check_out'])return $row;
    $now=vpNow();$quote=vpSessionQuote($row,$now);$compliance=vpCompliance($row,$now);$payment=null;
    $state=!empty($quote['needs_rate_review'])?'pending_rate_review':($quote['amount']==='0.00'?'free':'billed');
    if($state==='billed'&&(!(int)$row['billing_user_id']||!in_array($row['billing_scope_snapshot'],['visitor_parking','personal_parking'],true)))$state='pending_account_review';
    if($state==='billed')$payment=vpPostFinalCharge($db,$row,$quote);
    $fee=in_array($state,['pending_rate_review','pending_account_review'],true)?null:$quote['amount'];$snapshot=json_encode($quote,JSON_THROW_ON_ERROR);
    $actor=(int)$_SESSION['user_id'];$s=$db->prepare('UPDATE parking_requests SET actual_check_out=?,billable_seconds=?,billable_hours=?,parking_fee=?,parking_compliance=?,calculation_snapshot=?,fee_state=?,checked_out_by=? WHERE id=? AND actual_check_out IS NULL');$s->bind_param('siissssii',$now,$quote['seconds'],$quote['hours'],$fee,$compliance,$snapshot,$state,$actor,$row['id']);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('Vehicle checkout changed.');
    $s=$db->prepare("UPDATE parking_slots SET status=IF(status='maintenance','maintenance','available'),assigned_user_id=NULL,assigned_unit=NULL,vehicle_plate=NULL WHERE id=? AND assigned_user_id=? AND vehicle_plate=?");$s->bind_param('iis',$row['slot_id'],$row['user_id'],$row['vehicle_plate']);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('Occupied slot does not match this vehicle.');
    $s=$db->prepare("SELECT id FROM parking_extensions WHERE parking_request_id=? AND status='pending' FOR UPDATE");$s->bind_param('i',$row['id']);$s->execute();$pending=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s=$db->prepare("UPDATE parking_extensions SET status='cancelled',decision_note='Vehicle checked out before this pending request was decided.',decided_by=?,decided_at=UTC_TIMESTAMP(6) WHERE parking_request_id=? AND status='pending'");$s->bind_param('ii',$actor,$row['id']);$s->execute();
    foreach($pending as$extension)if(!logAudit('cancel','parking_extension',(int)$extension['id'],'Vehicle checked out; pending extension cancelled.',$db))throw new RuntimeException('Extension checkout audit failed.');
    if(!logAudit($state==='free'?'free_checkout':'check_out','visitor_parking',(int)$row['id'],'Official vehicle checkout; '.$state.'; '.$compliance,$db,'success'))throw new RuntimeException('Parking checkout audit failed.');
    vpNotify($db,(int)$row['id'],'checkout',$state==='free'?'Free parking: vehicle checked out within its grace period. No bill was created.':($state==='billed'?'Visitor checked out. Parking charge PHP '.$quote['amount'].' was added to the responsible billing account.':'Vehicle checked out and slot released. The charge is pending authorized review and is not payable.'),str_starts_with($state,'pending_'));
    return $row+['final_quote'=>$quote,'bill_id'=>$payment];
}
