<?php
/** Read-only financial overview; payment confirmation and billing remain in their existing modules. */
function treasurerDashboardData(mysqli $db, int $actorId, ?string $month = null, ?DateTimeImmutable $now = null): array {
    $actor = $db->prepare('SELECT role,is_active,is_verified,session_version FROM users WHERE id=?');
    $actor->bind_param('i', $actorId); $actor->execute(); $account = $actor->get_result()->fetch_assoc();
    if (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0) !== $actorId || ($_SESSION['role'] ?? '') !== 'treasurer'
        || !$account || $account['role'] !== 'treasurer' || !$account['is_active'] || !$account['is_verified']
        || (int)($_SESSION['session_version'] ?? -1) !== (int)$account['session_version']) {
        throw new DomainException('Access denied.', 403);
    }
    // Staff account status is the resident approval field; active/verified staff may retain 'pending'.
    $zone = new DateTimeZone('Asia/Manila');
    $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $month ??= $now->format('Y-m');
    $period = DateTimeImmutable::createFromFormat('!Y-m', $month, $zone);
    if (!preg_match('/^(?:19|20)\d{2}-(?:0[1-9]|1[0-2])$/D', $month) || !$period || $period->format('Y-m') !== $month) {
        throw new InvalidArgumentException('Choose a valid month.');
    }
    $start = $period->format('Y-m-d H:i:s');
    $end = $period->modify('+1 month')->format('Y-m-d H:i:s');
    $today = $now->format('Y-m-d');
    $soon = $now->modify('+7 days')->format('Y-m-d');
    $trendStart = $period->modify('-5 months')->format('Y-m-d H:i:s');
    $query = static function (string $sql, array $params = []) use ($db): mysqli_result {
        $stmt = $db->prepare($sql);
        if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute(); return $stmt->get_result();
    };
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $totals = $query("SELECT COUNT(*) record_count,
            COALESCE(ROUND(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END)*100),0) paid_cents,
            SUM(CASE WHEN status='paid' AND paid_at IS NULL THEN 1 ELSE 0 END) undated_paid_count,
            COALESCE(ROUND(SUM(CASE WHEN status='paid' AND paid_at>=? AND paid_at<? THEN amount ELSE 0 END)*100),0) collected_cents,
            SUM(CASE WHEN status='paid' AND paid_at>=? AND paid_at<? THEN 1 ELSE 0 END) collected_count,
            COALESCE(ROUND(SUM(CASE WHEN status IN ('pending','overdue') THEN amount ELSE 0 END)*100),0) outstanding_cents,
            SUM(CASE WHEN status IN ('pending','overdue') THEN 1 ELSE 0 END) outstanding_count,
            COALESCE(ROUND(SUM(CASE WHEN status IN ('pending','overdue') AND due_date<? THEN amount ELSE 0 END)*100),0) past_due_cents,
            SUM(CASE WHEN status IN ('pending','overdue') AND due_date<? THEN 1 ELSE 0 END) past_due_count,
            COALESCE(ROUND(SUM(CASE WHEN status IN ('pending','overdue') AND due_date>=? AND due_date<? THEN amount ELSE 0 END)*100),0) due_soon_cents,
            SUM(CASE WHEN status IN ('pending','overdue') AND due_date>=? AND due_date<? THEN 1 ELSE 0 END) due_soon_count
            FROM payments", [$start,$end,$start,$end,$today,$today,$today,$soon,$today,$soon])->fetch_assoc();
        foreach ($totals as &$value) $value = (int)$value;
        unset($value);
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $period->modify('-'.$i.' months');
            $trend[$date->format('Y-m')] = ['month'=>$date->format('M Y'),'cents'=>0];
        }
        foreach ($query("SELECT DATE_FORMAT(paid_at,'%Y-%m') month,ROUND(SUM(amount)*100) cents
            FROM payments WHERE status='paid' AND paid_at>=? AND paid_at<? GROUP BY DATE_FORMAT(paid_at,'%Y-%m')", [$trendStart,$end]) as $row) {
            $trend[$row['month']]['cents'] = (int)$row['cents'];
        }
        $aging = [
            'current'=>['label'=>'Not yet past due','cents'=>0,'count'=>0],
            '1_30'=>['label'=>'1–30 days past due','cents'=>0,'count'=>0],
            '31_60'=>['label'=>'31–60 days past due','cents'=>0,'count'=>0],
            '61_plus'=>['label'=>'61+ days past due','cents'=>0,'count'=>0],
        ];
        foreach ($query("SELECT CASE WHEN due_date>=? THEN 'current' WHEN due_date>=DATE_SUB(?,INTERVAL 30 DAY) THEN '1_30'
            WHEN due_date>=DATE_SUB(?,INTERVAL 60 DAY) THEN '31_60' ELSE '61_plus' END bucket,
            ROUND(SUM(amount)*100) cents,COUNT(*) invoice_count FROM payments
            WHERE status IN ('pending','overdue') GROUP BY bucket", [$today,$today,$today]) as $row) {
            $aging[$row['bucket']]['cents'] = (int)$row['cents']; $aging[$row['bucket']]['count'] = (int)$row['invoice_count'];
        }
        $recent = $query("SELECT p.id,ROUND(p.amount*100) cents,p.paid_at,p.payment_channel,p.payment_method,
            u.full_name,u.username,u.unit_number FROM payments p JOIN users u ON u.id=p.user_id
            WHERE p.status='paid' ORDER BY p.paid_at IS NULL,p.paid_at DESC,p.id DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
        $followUps = $query("SELECT p.id,ROUND(p.amount*100) cents,p.due_date,u.full_name,u.username,u.unit_number
            FROM payments p JOIN users u ON u.id=p.user_id WHERE p.status IN ('pending','overdue') AND p.amount>0 AND p.due_date<?
            ORDER BY p.due_date,p.amount DESC,p.id LIMIT 6", [$soon])->fetch_all(MYSQLI_ASSOC);
        $db->commit();
        return ['month'=>$month,'month_label'=>$period->format('F Y'),'today'=>$today,'totals'=>$totals,
            'trend'=>array_values($trend),'aging'=>array_values($aging),'recent'=>$recent,'follow_ups'=>$followUps,
            'updated_at'=>$now->format('M j, Y g:i A').' · Manila'];
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}

function treasurerDashboardMoney(int $cents): string {
    return '₱'.number_format($cents / 100, 2);
}
