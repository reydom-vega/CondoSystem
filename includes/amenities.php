<?php
require_once __DIR__.'/amenity_catalog.php';

class AmenityScheduleConflict extends InvalidArgumentException {
    public function __construct(public bool $approval=false) {
        parent::__construct($approval ? 'Approval Failed — This time slot has already been reserved by another approved booking.' : 'The swimming pool is already reserved for the selected date and time. Please choose another available time slot or reservation date.');
    }
}

function ensureAmenityBookingSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    if (!ensureBookingsTable($db)) return false;
    $columns=[
        'attendees'=>'INT NOT NULL DEFAULT 1', 'unit_number'=>'VARCHAR(30) DEFAULT NULL',
        'end_time'=>'TIME DEFAULT NULL', 'duration_hours'=>'TINYINT UNSIGNED DEFAULT NULL',
        'hourly_rate'=>'DECIMAL(10,2) NOT NULL DEFAULT 0.00', 'total_fee'=>'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'payment_id'=>'INT DEFAULT NULL', 'approved_by'=>'INT DEFAULT NULL', 'approved_at'=>'DATETIME DEFAULT NULL',
        'rejection_reason'=>'VARCHAR(500) DEFAULT NULL', 'cancellation_note'=>'VARCHAR(500) DEFAULT NULL',
    ];
    $actual=[]; $result=$db->query('SHOW COLUMNS FROM bookings');
    while($row=$result->fetch_assoc()) $actual[$row['Field']]=$row;
    foreach($columns as $name=>$definition) if(!isset($actual[$name]) && !$db->query("ALTER TABLE bookings ADD $name $definition")) return false;
    if(!str_contains($actual['status']['Type'],"'approved'")) {
        if(!$db->query("ALTER TABLE bookings MODIFY status ENUM('pending','approved','confirmed','rejected','cancelled','completed') NOT NULL DEFAULT 'pending'")) return false;
    }
    // Legacy sessions retain their status and are never charged retroactively.
    $db->query("UPDATE bookings SET duration_hours=1,end_time=ADDTIME(booking_time,'01:00:00') WHERE amenity='Swimming Pool' AND duration_hours IS NULL");
    $db->query("UPDATE bookings SET end_time='21:00:00' WHERE amenity='Function Hall' AND end_time IS NULL");
    $db->query('UPDATE bookings b JOIN users u ON u.id=b.user_id SET b.unit_number=u.unit_number WHERE b.unit_number IS NULL');
    $indexes=[]; $result=$db->query('SHOW INDEX FROM bookings'); while($row=$result->fetch_assoc()) $indexes[$row['Key_name']]=true;
    if(!isset($indexes['ix_booking_schedule'])) $db->query('ALTER TABLE bookings ADD INDEX ix_booking_schedule (amenity,booking_date,status,booking_time,end_time)');
    if(!isset($indexes['ux_booking_payment'])) $db->query('ALTER TABLE bookings ADD UNIQUE INDEX ux_booking_payment (payment_id)');
    return true;
}

function amenitySchedule(string $amenity,string $date,string $time,int $duration,bool $future=true): array {
    if(!in_array($amenity,['Swimming Pool','Function Hall'],true) || !workflowDate($date) || !preg_match('/^(?:[01][0-9]|2[0-3]):00$/D',$time)) throw new InvalidArgumentException('Choose a valid amenity, date and starting time on the hour.');
    if($future && $date.' '.$time <= date('Y-m-d H:i')) throw new InvalidArgumentException('Choose a future booking time (Asia/Manila).');
    if($time<'06:00' || $time>'20:00') throw new InvalidArgumentException('Choose an hourly start time between 6 AM and 8 PM.');
    if($amenity==='Swimming Pool') {
        if($duration<1 || $duration>3) throw new InvalidArgumentException('Swimming Pool reservations must last 1, 2 or 3 hours.');
        $endHour=(int)substr($time,0,2)+$duration;
        if($endHour>21) throw new InvalidArgumentException('The reservation must end by the 9 PM closing time.');
        return ['end_time'=>sprintf('%02d:00:00',$endHour),'duration_hours'=>$duration,'hourly_rate'=>'300.00','total_fee'=>sprintf('%d.00',$duration*300)];
    }
    return ['end_time'=>'21:00:00','duration_hours'=>null,'hourly_rate'=>'0.00','total_fee'=>'3000.00'];
}

/** Same database/amenity/date mutex held through the transaction's commit. */
function withAmenityScheduleLock(mysqli $db,string $amenity,string $date,callable $action): mixed {
    $database=(string)$db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
    $name='amenity:'.sha1($database.':'.$amenity.':'.$date);
    $lock=$db->prepare('SELECT GET_LOCK(?,10) AS acquired'); $lock->bind_param('s',$name); $lock->execute();
    if((int)$lock->get_result()->fetch_assoc()['acquired']!==1) throw new InvalidArgumentException('The booking schedule is busy. Please try again.');
    try { return $action(); }
    finally { $release=$db->prepare('SELECT RELEASE_LOCK(?)'); $release->bind_param('s',$name); $release->execute(); }
}

/** Stored relationships supply the authorized unit and its billing account. */
function amenityResidentContext(mysqli $db,int $userId,bool $lock=true): array {
    $context=residentContext($db,$userId);
    if($lock && $context) {
        $ids=array_unique([$userId,(int)$context['billing_user_id']]); sort($ids);
        foreach($ids as $id) { if($id<1) continue; $query=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $query->bind_param('i',$id); $query->execute(); $query->get_result()->fetch_assoc(); }
        $context=residentContext($db,$userId);
    }
    if(!$context || !$context['approved'] || !residentUserHasPermission($db,$userId,'resident.amenities.book')) throw new InvalidArgumentException('An active approved resident or tenant with an authorized unit and booking permission is required.');
    return $context;
}

function amenityHasConflict(mysqli $db,string $amenity,string $date,string $start,string $end,int $exclude=0,bool $hallPending=true): bool {
    $statuses=$amenity==='Function Hall' && $hallPending ? "('pending','approved','confirmed','completed')" : "('approved','confirmed','completed')";
    $sql="SELECT id FROM bookings WHERE amenity=? AND booking_date=? AND status IN $statuses AND id<>?";
    if($amenity==='Swimming Pool') $sql.=" AND booking_time<? AND COALESCE(end_time,ADDTIME(booking_time,'01:00:00'))>?";
    $sql.=' LIMIT 1 FOR UPDATE';
    $query=$db->prepare($sql);
    if($amenity==='Swimming Pool') $query->bind_param('ssiss',$amenity,$date,$exclude,$end,$start); else $query->bind_param('ssi',$amenity,$date,$exclude);
    $query->execute(); return (bool)$query->get_result()->fetch_assoc();
}

function createAmenityBooking(mysqli $db,int $userId,string $amenity,string $date,string $time,int $attendees,int $duration=1): bool {
    if(!isLoggedIn() || (int)$_SESSION['user_id']!==$userId || ($_SESSION['role'] ?? '')!=='resident') throw new InvalidArgumentException('Only the authenticated resident can request a reservation.');
    if(!ensureAmenityBookingSchema($db)) throw new RuntimeException('Booking storage is unavailable.');
    $schedule=amenitySchedule($amenity,$date,$time,$duration);
    $capacity=$amenity==='Swimming Pool'?20:100;
    if($attendees<1 || $attendees>$capacity) throw new InvalidArgumentException('Guest count must be from 1 to '.$capacity.'.');
    return withAmenityScheduleLock($db,$amenity,$date,function() use($db,$userId,$amenity,$date,$time,$attendees,$schedule):bool {
        $db->begin_transaction();
        try {
            $context=amenityResidentContext($db,$userId);
            if(amenityHasConflict($db,$amenity,$date,$time,$schedule['end_time'])) {
                if($amenity==='Swimming Pool') throw new AmenityScheduleConflict();
                throw new InvalidArgumentException('This period is fully booked. Choose another date or time.');
            }
            $own=$db->prepare("SELECT id FROM bookings WHERE user_id=? AND amenity=? AND booking_date=? AND booking_time=? AND status='pending' LIMIT 1 FOR UPDATE");
            $own->bind_param('isss',$userId,$amenity,$date,$time); $own->execute();
            if($own->get_result()->fetch_assoc()) throw new InvalidArgumentException('You already have a pending request for this starting time.');
            $insert=$db->prepare('INSERT INTO bookings (user_id,amenity,booking_date,booking_time,attendees,unit_number,end_time,duration_hours,hourly_rate,total_fee) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $insert->bind_param('isssississ',$userId,$amenity,$date,$time,$attendees,$context['unit_number'],$schedule['end_time'],$schedule['duration_hours'],$schedule['hourly_rate'],$schedule['total_fee']);
            $insert->execute(); $db->commit(); return true;
        } catch(Throwable $error) { $db->rollback(); throw $error; }
    });
}

/** Availability exposes only times, never residents, units or payment details. */
function poolAvailability(mysqli $db,string $date,int $duration): array {
    if(!workflowDate($date) || $duration<1 || $duration>3) throw new InvalidArgumentException('Choose a valid date and duration of 1–3 hours.');
    $query=$db->prepare("SELECT booking_time,COALESCE(end_time,ADDTIME(booking_time,'01:00:00')) AS end_time FROM bookings WHERE amenity='Swimming Pool' AND booking_date=? AND status IN ('approved','confirmed','completed') ORDER BY booking_time");
    $query->bind_param('s',$date); $query->execute(); $booked=$query->get_result()->fetch_all(MYSQLI_ASSOC);
    $slots=[]; $available=0;
    for($hour=6;$hour+$duration<=21;$hour++) {
        $start=sprintf('%02d:00:00',$hour); $end=sprintf('%02d:00:00',$hour+$duration); $conflict=false;
        foreach($booked as $period) if($start<$period['end_time'] && $end>$period['booking_time']) { $conflict=true; break; }
        $past=$date.' '.$start<=date('Y-m-d H:i:s');
        $state=$conflict?'booked':($past?'past':'available'); if($state==='available') $available++;
        $slots[]=['start'=>substr($start,0,5),'end'=>substr($end,0,5),'status'=>$state];
    }
    return ['success'=>true,'date'=>$date,'duration'=>$duration,'hourly_rate'=>300,'total_fee'=>$duration*300,'timezone'=>'Asia/Manila','slots'=>$slots,'booked'=>$booked,'fully_booked'=>$available===0 && count(array_filter($slots,fn($slot)=>$slot['status']==='booked'))===count($slots)];
}

/** Decision and bill share a transaction; approvals also share the schedule mutex. */
function decideAmenityBooking(mysqli $db,int $id,string $action,string $reason='',bool $pmoReview=false): bool {
    if(!isLoggedIn()) throw new InvalidArgumentException('Sign in before changing a booking.');
    $residentCancel=$action==='cancelled' && ($_SESSION['role'] ?? '')==='resident';
    if(!$residentCancel && !canAccess('bookings.review')) throw new InvalidArgumentException('Booking review permission is required.');
    if(!in_array($action,['approved','confirmed','rejected','cancelled','completed'],true) || $id<1) throw new InvalidArgumentException('Invalid booking update.');
    if(!ensureAmenityBookingSchema($db)) throw new RuntimeException('Booking storage is unavailable.');
    ensurePaymentsTable($db); ensureBillingTables($db); ensurePaymongoColumns($db); ensureAuditLogTable($db);
    $find=$db->prepare('SELECT * FROM bookings WHERE id=?'); $find->bind_param('i',$id); $find->execute(); $initial=$find->get_result()->fetch_assoc();
    if(!$initial || ($residentCancel && (int)$initial['user_id']!==(int)$_SESSION['user_id'])) throw new InvalidArgumentException('This booking is not available for your account.');
    return withAmenityScheduleLock($db,$initial['amenity'],$initial['booking_date'],function() use($db,$id,$action,$reason,$pmoReview,$residentCancel,$initial):bool {
        $db->begin_transaction();
        try {
            $context=$action==='approved' || ($action==='confirmed' && $initial['amenity']==='Function Hall') ? amenityResidentContext($db,(int)$initial['user_id']) : null;
            // Payment confirmation also locks payment before its linked booking.
            $bill=null;
            if($initial['payment_id']) { $payment=$db->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE'); $payment->bind_param('i',$initial['payment_id']); $payment->execute(); $bill=$payment->get_result()->fetch_assoc(); }
            $find=$db->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE'); $find->bind_param('i',$id); $find->execute(); $booking=$find->get_result()->fetch_assoc();
            if((string)$booking['payment_id']!==(string)$initial['payment_id']) throw new InvalidArgumentException('This booking changed. Refresh and try again.');
            $old=$booking['status']; $target=$action; $reason=trim($reason);
            if(strlen($reason)>500) throw new InvalidArgumentException('Keep the reason within 500 characters.');
            $pool=$booking['amenity']==='Swimming Pool';
            if($pool && $action==='confirmed') throw new InvalidArgumentException('Pool payment is confirmed only by verified payment processing. Approve the request first.');
            if(in_array($action,['approved','confirmed'],true)) {
                if(in_array($old,['approved','confirmed','completed'],true)) { $db->rollback(); return true; }
                if($old!=='pending') throw new InvalidArgumentException('Only pending requests can be approved.');
                $schedule=amenitySchedule($booking['amenity'],$booking['booking_date'],substr($booking['booking_time'],0,5),(int)($booking['duration_hours'] ?? 1));
                if($context['unit_number']!==normalizeUnitNumber($booking['unit_number'])) throw new InvalidArgumentException('The requesting resident is no longer authorized for the booking unit.');
                if(amenityHasConflict($db,$booking['amenity'],$booking['booking_date'],$booking['booking_time'],$schedule['end_time'],$id,false)) throw new AmenityScheduleConflict(true);
                $target=$pool?'approved':'confirmed';
                if($pool) {
                    if($booking['hourly_rate']!==$schedule['hourly_rate'] || $booking['total_fee']!==$schedule['total_fee']) throw new InvalidArgumentException('This legacy request needs cancellation and resubmission with the current pool pricing.');
                    $paymentId=createAmenityReservationBill($db,$booking,(int)$context['billing_user_id']);
                    $link=$db->prepare('UPDATE bookings SET payment_id=? WHERE id=? AND payment_id IS NULL'); $link->bind_param('ii',$paymentId,$id); $link->execute();
                    if($link->affected_rows!==1) throw new RuntimeException('Could not link reservation billing.');
                }
                $actor=(int)$_SESSION['user_id'];
                $approve=$db->prepare('UPDATE bookings SET approved_by=?,approved_at=NOW() WHERE id=?'); $approve->bind_param('ii',$actor,$id); $approve->execute();
            } elseif($action==='rejected') {
                if($old!=='pending' || $reason==='') throw new InvalidArgumentException('Only pending requests can be rejected. Enter a rejection reason.');
                $reject=$db->prepare('UPDATE bookings SET rejection_reason=? WHERE id=?'); $reject->bind_param('si',$reason,$id); $reject->execute();
            } elseif($action==='cancelled') {
                if(!in_array($old,['pending','approved','confirmed'],true) || $booking['booking_date'].' '.$booking['booking_time']<=date('Y-m-d H:i:s')) throw new InvalidArgumentException('This booking can no longer be cancelled.');
                if($residentCancel) amenityResidentContext($db,(int)$booking['user_id'],false);
                $needsReview=$bill && ($bill['status']==='paid' || !empty($bill['paymongo_checkout_id']) || !in_array($bill['status'],['pending','overdue'],true));
                if($needsReview && ($residentCancel || !canManageBilling() || !$pmoReview || $reason==='')) throw new InvalidArgumentException('PMO review is required for paid reservations or reservations with an online checkout. Contact PMO; the reservation remains active.');
                if($bill && !$needsReview) {
                    $void=$db->prepare("UPDATE payments SET status='rejected',gateway_status='booking_cancelled' WHERE id=? AND status IN ('pending','overdue') AND paymongo_checkout_id IS NULL");
                    $void->bind_param('i',$bill['id']); $void->execute();
                }
                $note=$needsReview?'PMO cancellation; financial review required. '.$reason:($reason ?: 'Cancelled before start; unpaid charge voided if present.');
                $cancel=$db->prepare('UPDATE bookings SET cancellation_note=? WHERE id=?'); $cancel->bind_param('si',$note,$id); $cancel->execute();
            } elseif($action==='completed') {
                if(!in_array($old,['approved','confirmed'],true) || ($pool && $booking['payment_id'] && (!$bill || $bill['status']!=='paid')) || $booking['booking_date'].' '.$booking['end_time']>date('Y-m-d H:i:s')) throw new InvalidArgumentException('Complete a reservation only after its end time and required payment.');
            }
            $update=$db->prepare('UPDATE bookings SET status=? WHERE id=?'); $update->bind_param('si',$target,$id); $update->execute();
            if(!logAudit($target,'booking',$id,$reason ?: 'Booking status set to '.$target,$db)) throw new RuntimeException('Could not audit booking decision.');
            $db->commit(); return true;
        } catch(Throwable $error) { $db->rollback(); throw $error; }
    });
}

/** Residency revocation releases access; paid/checkout bills stay for PMO review. */
function cancelAmenityBookingsForResidency(mysqli $db,int $userId): void {
    $void=$db->prepare("UPDATE payments p JOIN bookings b ON b.payment_id=p.id SET p.status='rejected',p.gateway_status='booking_cancelled' WHERE b.user_id=? AND b.booking_date>=CURRENT_DATE() AND b.status IN ('pending','approved','confirmed') AND p.status IN ('pending','overdue') AND p.paymongo_checkout_id IS NULL");
    $void->bind_param('i',$userId); $void->execute();
    $cancel=$db->prepare("UPDATE bookings SET status='cancelled',cancellation_note='Residency revoked. PMO must review any paid charge or online checkout; financial history retained.' WHERE user_id=? AND booking_date>=CURRENT_DATE() AND status IN ('pending','approved','confirmed')");
    $cancel->bind_param('i',$userId); $cancel->execute();
}
