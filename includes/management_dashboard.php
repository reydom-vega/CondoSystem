<?php
/** Read-only operational snapshot shared by Admin and Super Admin. */
function managementDashboardData(mysqli $db, int $actorId, ?DateTimeImmutable $now = null): array {
    $statement = $db->prepare('SELECT role,is_active,is_verified,session_version FROM users WHERE id=?');
    $statement->bind_param('i', $actorId); $statement->execute(); $actor = $statement->get_result()->fetch_assoc();
    if (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0) !== $actorId || !$actor
        || !in_array($actor['role'], ['admin','superadmin'], true) || ($_SESSION['role'] ?? '') !== $actor['role']
        || !$actor['is_active'] || !$actor['is_verified']
        || (int)($_SESSION['session_version'] ?? -1) !== (int)$actor['session_version']) {
        throw new DomainException('Access denied.', 403);
    }
    $role = $actor['role']; $zone = new DateTimeZone('Asia/Manila');
    $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $today = $now->format('Y-m-d'); $through = $now->modify('+7 days')->format('Y-m-d');
    $query = static function(string $sql, array $params = []) use ($db): mysqli_result {
        $stmt = $db->prepare($sql);
        if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute(); return $stmt->get_result();
    };
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $queues = [];
        $queue = static function(string $key, string $title, string $icon, string $capability, string $path, string $sql, array $params = []) use (&$queues, $query, $role): void {
            if (!roleHasCapability($role, $capability)) return;
            $row = $query($sql, $params)->fetch_assoc();
            $queues[] = ['key'=>$key,'title'=>$title,'icon'=>$icon,'count'=>(int)$row['total'],'oldest'=>$row['oldest'] ?? null,'path'=>$path];
        };
        $queue('maintenance','Maintenance to assign','maintenance','maintenance.review','superadmin/maintenancerequests.php',"SELECT COUNT(*) total,MIN(created_at) oldest FROM maintenance_requests WHERE status IN ('pending','reopened')");
        $queue('bookings','Amenity bookings','calendar','bookings.review','superadmin/bookingrequest.php?status=pending#bookingRequests',"SELECT COUNT(*) total,MIN(created_at) oldest FROM bookings WHERE status='pending'");
        $queue('visitors','Visitor registrations','visitors','visitors.review','superadmin/service_requests.php?kind=visitor&tab=requests',"SELECT COUNT(*) total,MIN(created_at) oldest FROM resident_service_requests WHERE request_kind='visitor' AND status='pending'");
        $queue('permits','Permit requests','clipboard-list','permits.review','superadmin/service_requests.php?kind=permit&tab=requests',"SELECT COUNT(*) total,MIN(created_at) oldest FROM resident_service_requests WHERE request_kind='permit' AND status='pending'");
        $queue('parking','Parking requests','parking','parking.review','superadmin/parking.php?tab=visitors',"SELECT COUNT(*) total,MIN(created_at) oldest FROM parking_requests WHERE status='pending'");
        $queue('extensions','Extended parking','clock','parking.extensions.review','superadmin/parking.php?tab=extensions',"SELECT COUNT(*) total,MIN(created_at) oldest FROM parking_extensions WHERE status='pending'");
        $queue('vehicles','Vehicle registrations','parking','vehicles.review','superadmin/parking.php?tab=vehicles',"SELECT COUNT(*) total,MIN(v.created_at) oldest FROM vehicles v JOIN users u ON u.id=v.user_id WHERE v.status='pending' AND v.authorization_revoked_at IS NULL AND u.is_active=1 AND u.archived_at IS NULL");
        $queue('stickers','Paid stickers to issue','parking','stickers.issue','superadmin/parking.php?tab=stickers',"SELECT COUNT(*) total,MIN(o.created_at) oldest FROM parking_sticker_orders o JOIN payments p ON p.id=o.bill_payment_id AND p.user_id=o.user_id JOIN users u ON u.id=o.user_id WHERE u.is_active=1 AND u.archived_at IS NULL AND o.authorization_revoked_at IS NULL AND p.status='paid' AND o.status<>'cancelled' AND o.claim_status<>'issued' AND p.amount=o.amount");
        $queue('violations','Violation reviews / disputes','violations','violations.review','superadmin/violations.php?tab=history',"SELECT COUNT(*) total,MIN(created_at) oldest FROM violations WHERE review_status='pending' OR (review_status IN ('legacy','approved') AND status='disputed')");
        $queue('accounts','Verified account applications','pending','accounts.review','superadmin/pending_accounts.php',"SELECT COUNT(*) total,MIN(created_at) oldest FROM users WHERE role='resident' AND status='pending' AND is_active=1 AND archived_at IS NULL AND is_verified=1");
        $queue('staff_reports','Staff reports to review','clipboard-list','jobs.review','superadmin/staff.php?tab=reports',"SELECT COUNT(*) total,MIN(filed_at) oldest FROM job_reports WHERE status='submitted' AND user_id<>?", [$actorId]);
        $pendingTotal = array_sum(array_column($queues, 'count'));
        $maintenance = $query("SELECT COUNT(*) active,COALESCE(SUM(urgency='urgent'),0) urgent FROM maintenance_requests WHERE status IN ('pending','approved','in_progress','reopened')")->fetch_assoc();
        $maintenanceRows = $query("SELECT m.id,m.issue_type,m.location,m.urgency,m.status,m.created_at,CONCAT(u.full_name,IF(u.archived_at IS NULL,'',' (archived account)')) full_name,COALESCE(m.unit_number_snapshot,u.unit_number) unit_number FROM maintenance_requests m JOIN users u ON u.id=m.user_id WHERE m.status IN ('pending','approved','in_progress','reopened') ORDER BY m.urgency='urgent' DESC,m.created_at,m.id LIMIT 4")->fetch_all(MYSQLI_ASSOC);
        $messages = $query("SELECT COUNT(*) messages,COUNT(DISTINCT user_id) conversations FROM messages m JOIN users u ON u.id=m.user_id WHERE m.sender_role='resident' AND m.is_read=0 AND u.is_active=1 AND u.archived_at IS NULL")->fetch_assoc();
        $conversations = $query("SELECT u.id,u.full_name,u.unit_number,COUNT(*) unread,MIN(m.created_at) oldest FROM messages m JOIN users u ON u.id=m.user_id WHERE m.sender_role='resident' AND m.is_read=0 AND u.is_active=1 AND u.archived_at IS NULL GROUP BY u.id,u.full_name,u.unit_number ORDER BY oldest,u.id LIMIT 4")->fetch_all(MYSQLI_ASSOC);
        $bookings = $query("SELECT b.id,b.amenity,b.booking_date,b.booking_time,b.end_time,b.status,u.full_name,COALESCE(b.unit_number,u.unit_number) unit_number FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.status IN ('approved','confirmed') AND b.booking_date>=? AND b.booking_date<? ORDER BY b.booking_date,b.booking_time,b.id LIMIT 5", [$today,$through])->fetch_all(MYSQLI_ASSOC);
        $recentResidents = $query("SELECT id,full_name,username,unit_number,status,is_active,is_verified FROM users WHERE role='resident' AND is_active=1 AND archived_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 4")->fetch_all(MYSQLI_ASSOC);
        $residentTotals = $query("SELECT COUNT(*) total,COALESCE(SUM(status='approved' AND is_active=1 AND is_verified=1),0) approved FROM users WHERE role='resident' AND is_active=1 AND archived_at IS NULL")->fetch_assoc();
        $inventory = [];
        foreach (loadUnitInventory() as $unit) $inventory[normalizeUnitNumber($unit['unit_number'])] = true;
        $occupied = [];
        foreach (array_keys(activeUnitPopulation($db)) as $unitNumber) {
            $row=['unit_number'=>$unitNumber];
            $unit = normalizeUnitNumber($row['unit_number']); if (isset($inventory[$unit])) $occupied[$unit] = true;
        }
        $unitTotal = count($inventory); $occupiedCount = count($occupied);
        $inside = $query("SELECT COUNT(*) visits,COALESCE(SUM(visitor_count),0) people FROM visitor_logs WHERE status='checked_in' AND time_out IS NULL")->fetch_assoc();
        $announcements = $query('SELECT COUNT(*) total FROM announcements WHERE is_active=1 AND (expires_at IS NULL OR expires_at>?)', [$now->format('Y-m-d H:i:s')])->fetch_assoc();
        $unresolved = (int)$query("SELECT COUNT(*) total FROM violations WHERE review_status IN ('legacy','approved') AND status IN ('unpaid','disputed')")->fetch_assoc()['total'];
        $data = ['role'=>$role,'pending_total'=>$pendingTotal,'queues'=>$queues,
            'maintenance'=>['active'=>(int)$maintenance['active'],'urgent'=>(int)$maintenance['urgent'],'rows'=>$maintenanceRows],
            'messages'=>['messages'=>(int)$messages['messages'],'conversations'=>(int)$messages['conversations'],'rows'=>$conversations],
            'units'=>['total'=>$unitTotal,'occupied'=>$occupiedCount,'unassigned'=>$unitTotal-$occupiedCount,'percent'=>$unitTotal ? round($occupiedCount/$unitTotal*100,1) : null],
            'residents'=>['total'=>(int)$residentTotals['total'],'approved'=>(int)$residentTotals['approved'],'rows'=>$recentResidents],
            'visitors'=>['inside'=>(int)$inside['people'],'visits'=>(int)$inside['visits']],
            'announcements'=>(int)$announcements['total'],'unresolved_violations'=>$unresolved,'bookings'=>$bookings,
            'today'=>$today,'through'=>$now->modify('+6 days')->format('Y-m-d'),'updated_at'=>$now->format('M j, Y g:i:s A').' (Manila)'];
        if (roleHasCapability($role, 'billing.manage')) {
            // paid_at uses the existing local billing convention, not the bill creation date.
            $start = $now->modify('first day of this month')->setTime(0,0)->format('Y-m-d H:i:s');
            $end = $now->modify('first day of next month')->setTime(0,0)->format('Y-m-d H:i:s');
            $finance = $query("SELECT COALESCE(ROUND(SUM(CASE WHEN status='paid' AND paid_at>=? AND paid_at<? THEN amount ELSE 0 END)*100),0) collected_cents,
                COALESCE(ROUND(SUM(CASE WHEN status IN ('pending','overdue') THEN amount ELSE 0 END)*100),0) outstanding_cents,
                COALESCE(ROUND(SUM(CASE WHEN status IN ('pending','overdue') AND due_date<? THEN amount ELSE 0 END)*100),0) past_due_cents,
                COALESCE(SUM(status IN ('pending','overdue') AND due_date<?),0) past_due_count,
                COALESCE(SUM(status='paid' AND paid_at IS NULL),0) undated_paid_count FROM payments", [$start,$end,$today,$today])->fetch_assoc();
            foreach ($finance as &$value) $value = (int)$value; unset($value);
            $data['finance'] = $finance + ['month'=>$now->format('F Y')];
        }
        if (roleHasCapability($role, 'notifications.manage')) $data['failed_notifications'] = (int)$query("SELECT COUNT(*) total FROM notification_outbox WHERE status='failed'")->fetch_assoc()['total'];
        $db->commit(); return $data;
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}

function managementDashboardMoney(int $cents): string { return '₱'.number_format($cents / 100, 2); }
