<?php
/** Read-only gate overview. Approval queues and private incident narratives are excluded. */
function securityDashboardData(mysqli $db,int $actorId): array {
    $actor=$db->prepare('SELECT role,is_active,is_verified,session_version FROM users WHERE id=?');
    $actor->bind_param('i',$actorId);$actor->execute();$account=$actor->get_result()->fetch_assoc();
    // Staff login uses active/verified state. users.status is the resident
    // approval field and legacy staff accounts legitimately retain its default.
    if(!isLoggedIn() || (int)($_SESSION['user_id']??0)!==$actorId || ($_SESSION['role']??'')!=='security' || !$account || $account['role']!=='security' || !$account['is_active'] || !$account['is_verified'] || (isset($_SESSION['session_version']) && (int)$_SESSION['session_version']!==(int)$account['session_version']))throw new DomainException('Access denied.',403);
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));$start=$now->setTime(0,0);$end=$start->modify('+1 day');
    $from=$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$until=$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $inside=$db->query("SELECT COUNT(*) visits,COALESCE(SUM(visitor_count),0) people FROM visitor_logs WHERE status='checked_in' AND time_out IS NULL")->fetch_assoc();
        $pending=$db->prepare("SELECT COUNT(*) total FROM violations WHERE issued_by=? AND review_status='pending'");$pending->bind_param('i',$actorId);$pending->execute();$pendingCount=(int)$pending->get_result()->fetch_assoc()['total'];
        $checks=$db->prepare("SELECT COUNT(*) total FROM qr_scan_logs WHERE user_id=? AND scan_action='verification' AND event_time_utc>=? AND event_time_utc<?");$checks->bind_param('iss',$actorId,$from,$until);$checks->execute();$checkCount=(int)$checks->get_result()->fetch_assoc()['total'];
        $reports=$db->prepare('SELECT id,violation_type,review_status,status,created_at FROM violations WHERE issued_by=? ORDER BY id DESC LIMIT 5');$reports->bind_param('i',$actorId);$reports->execute();
        $recentReports=[];foreach($reports->get_result()as$r)$recentReports[]=['reference'=>'VR-'.$r['id'],'title'=>$r['violation_type'],'status'=>$r['review_status']==='legacy'?str_replace('_',' ',(string)$r['status']):$r['review_status'],'time'=>date('M j, Y g:i A',strtotime($r['created_at']))];
        $visitors=[];foreach($db->query("SELECT visitor_name,unit_number,visitor_count,time_in,time_out FROM visitor_logs ORDER BY COALESCE(time_out,time_in) DESC,id DESC LIMIT 5")as$r)$visitors[]=['title'=>$r['visitor_name'],'unit'=>$r['unit_number'],'people'=>(int)$r['visitor_count'],'status'=>$r['time_out']?'Checked out':'Inside','time'=>date('M j, Y g:i A',strtotime($r['time_out']?:$r['time_in']))];
        $result=['visitors_inside'=>(int)$inside['people'],'visits_inside'=>(int)$inside['visits'],'pending_reports'=>$pendingCount,'qr_checks_today'=>$checkCount,'recent_reports'=>$recentReports,'recent_visitors'=>$visitors,'updated_at'=>$now->format('M j, Y g:i:s A').' (Asia/Manila)'];
        $db->commit();return $result;
    } catch(Throwable $error){$db->rollback();throw $error;}
}
