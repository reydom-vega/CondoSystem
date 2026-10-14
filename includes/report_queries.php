<?php
function reportDetail(mysqli $db,string $reference,bool $management=false,bool $anonymous=false): array {
    $case=reportLoad($db,$reference,$management,$anonymous);$id=(int)$case['id'];
    $result=reportOriginalData($case)+['id'=>$id,'reference'=>$case['reference'],'status'=>$case['status'],'priority'=>$case['priority'],'created_at'=>$case['created_at'],'updated_at'=>$case['updated_at'],'version'=>(int)$case['version'],'reporter'=>['name'=>'Anonymous Reporter']];
    if($case['reporting_mode']==='identified'){
        $s=$db->prepare('SELECT u.full_name,u.unit_number,u.role,u.account_type FROM report_identities ri JOIN users u ON u.id=ri.user_id WHERE ri.report_id=?');$s->bind_param('i',$id);$s->execute();$identity=$s->get_result()->fetch_assoc();$result['reporter']=['name'=>$identity['full_name'],'unit'=>$identity['unit_number'],'role'=>digitalIdRoleLabel($identity)??'Resident'];
    }
    $visibility=$management?'1=1':"e.visibility='reporter'";
    // Reporter responses never select staff actor IDs, internal payloads or notes.
    $s=$db->prepare("SELECT e.id,e.event_type,e.visibility,e.actor_kind,e.old_status,e.new_status,e.body,e.created_at".($management?',u.full_name AS staff_name':'')." FROM report_events e ".($management?'LEFT JOIN users u ON u.id=e.actor_user_id':'')." WHERE e.report_id=? AND ($visibility) ORDER BY e.id");$s->bind_param('i',$id);$s->execute();$result['events']=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s=$db->prepare("SELECT a.id,a.event_id,a.display_name,a.mime,a.byte_size,a.deleted_at FROM report_attachments a JOIN report_events e ON e.id=a.event_id WHERE a.report_id=? AND ($visibility) ORDER BY a.id");$s->bind_param('i',$id);$s->execute();$result['attachments']=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($result['attachments']as&$attachment)$attachment['url']=buildUrl('report_evidence.php?id='.(int)$attachment['id'].'&reference='.rawurlencode($reference).($management?'&management=1':($anonymous?'&anonymous=1':'')));
    if($management){
        $result['allowed_statuses']=reportStatusTransitions($case['status']);
        $result['review_scope']=$case['review_scope'];$result['assigned_reviewer_id']=$case['assigned_reviewer_id'];$result['reviewers']=reportEligibleReviewers($db,$case);
        $result['staff_accounts']=$db->query("SELECT id,full_name,role FROM users WHERE role<>'resident' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
        $s=$db->prepare('SELECT rc.user_id,u.full_name FROM report_conflicts rc JOIN users u ON u.id=rc.user_id WHERE rc.report_id=?');$s->bind_param('i',$id);$s->execute();$result['conflicts']=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    }elseif(!$anonymous){$last=(int)max(array_column($result['events'],'id')?:[0]);$s=$db->prepare('UPDATE report_identities SET last_read_event_id=GREATEST(last_read_event_id,?) WHERE report_id=? AND user_id=?');$s->bind_param('iii',$last,$id,$_SESSION['user_id']);$s->execute();}
    return $result;
}
function reportList(mysqli $db,array $input,bool $management): array {
    if($management){if(!isLoggedIn())throw new InvalidArgumentException('Access denied.');$where=reportReviewerPredicate($db,(int)$_SESSION['user_id']);if(!reportReviewer($db,(int)$_SESSION['user_id']))throw new InvalidArgumentException('Access denied.');}
    else{$user=reportRequireResident($db);$where="EXISTS(SELECT 1 FROM report_identities ri WHERE ri.report_id=r.id AND ri.user_id=$user) AND r.reporting_mode='identified'";}
    $stats=['total'=>0,'submitted'=>0,'under_review'=>0,'awaiting_information'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0,'declined'=>0,'withdrawn'=>0,'urgent'=>0];
    foreach($db->query("SELECT r.status,COUNT(*) n,SUM(r.priority='urgent') urgent FROM community_reports r WHERE ($where) GROUP BY r.status")as$row){$stats[$row['status']]=(int)$row['n'];$stats['total']+=(int)$row['n'];$stats['urgent']+=(int)$row['urgent'];}
    $search=reportText($input,'search',160);$status=reportText($input,'status',30);$category=reportText($input,'category',80);$priority=reportText($input,'priority',15);$mode=reportText($input,'mode',20);$date=reportText($input,'date',10);$reviewer=$management?reportText($input,'reviewer',12):'';$history=($input['history']??'0')==='1';
    if(($status!==''&&!isset(REPORT_STATUSES[$status]))||($category!==''&&!in_array($category,reportCategories(),true))||($priority!==''&&!in_array($priority,['normal','important','urgent'],true))||($mode!==''&&!in_array($mode,['anonymous','identified'],true))||($date!==''&&!workflowDate($date))||($reviewer!==''&&!ctype_digit($reviewer)))throw new InvalidArgumentException('Choose valid report filters.');
    $page=max(1,min(10000,(int)($input['page']??1)));$offset=($page-1)*20;$like='%'.$search.'%';$terminal="'resolved','closed','declined','withdrawn'";
    $localDate="DATE(CONVERT_TZ(r.created_at,'+00:00','".date('P')."'))";
    $filter="($where) AND (?='' OR r.status=?) AND (?='' OR r.category=?) AND (?='' OR r.priority=?) AND (?='' OR r.reporting_mode=?) AND (?='' OR $localDate=?) AND (?='' OR r.assigned_reviewer_id=?) AND (?='' OR r.reference LIKE ? OR r.subject LIKE ? OR r.location LIKE ?)";
    if(!$management)$filter.=$history?" AND r.status IN ($terminal)":" AND r.status NOT IN ($terminal)";
    $sql="SELECT r.reference,r.subject,r.category,r.reporting_mode,r.location,r.created_at,r.priority,r.status,r.version".($management?',u.full_name AS assigned_reviewer':'')." FROM community_reports r ".($management?'LEFT JOIN users u ON u.id=r.assigned_reviewer_id':'')." WHERE $filter ORDER BY r.created_at DESC,r.id DESC LIMIT 20 OFFSET $offset";
    $s=$db->prepare($sql);$s->bind_param(str_repeat('s',16),$status,$status,$category,$category,$priority,$priority,$mode,$mode,$date,$date,$reviewer,$reviewer,$search,$like,$like,$like);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    return ['records'=>$rows,'stats'=>$stats,'page'=>$page,'has_more'=>count($rows)===20];
}
function reportUnreadCount(mysqli $db,int $userId,bool $management): int {
    if($management){$where=reportReviewerPredicate($db,$userId);return (int)$db->query("SELECT COUNT(*) n FROM community_reports r WHERE ($where) AND r.status IN ('submitted','under_review','awaiting_information','in_progress')")->fetch_assoc()['n'];}
    $s=$db->prepare("SELECT COUNT(*) n FROM report_identities ri WHERE ri.user_id=? AND EXISTS(SELECT 1 FROM report_events e WHERE e.report_id=ri.report_id AND e.visibility='reporter' AND e.actor_kind IN ('management','system') AND e.id>ri.last_read_event_id)");$s->bind_param('i',$userId);$s->execute();return (int)$s->get_result()->fetch_assoc()['n'];
}
