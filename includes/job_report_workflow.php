<?php
function jrDraft(mysqli $db,array $actor,array $period): array {
    $row=jrQuery($db,'SELECT notes,revision,updated_at FROM job_report_drafts WHERE user_id=? AND report_type=? AND period_start=?',[$actor['id'],$period['type'],$period['start']])->get_result()->fetch_assoc();
    return $row?['notes'=>jrNotes(json_decode($row['notes'],true,512,JSON_THROW_ON_ERROR)),'revision'=>(int)$row['revision'],'updated_at'=>$row['updated_at']]:['notes'=>jrNotes([]),'revision'=>0,'updated_at'=>null];
}
function jrLatestPeriod(mysqli $db,array $actor,array $period): ?array {
    $r=jrQuery($db,'SELECT id,reference,status,version,filed_at,snapshot,snapshot_hash FROM job_reports WHERE user_id=? AND report_type=? AND period_start=? ORDER BY version DESC LIMIT 1',[$actor['id'],$period['type'],$period['start']])->get_result()->fetch_assoc();
    if(!$r)return null;if(!hash_equals($r['snapshot_hash'],hash('sha256',$r['snapshot'])))throw new RuntimeException('Report integrity verification failed.');
    $data=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR);$r['notes']=$data['notes'];unset($r['snapshot'],$r['snapshot_hash']);return $r;
}
function jrSaveDraft(mysqli $db,array $input): array {
    $a=jrActor($db);$p=jrPeriod($input);$notes=jrNotes($input['notes']??[]);$json=jrJson($notes);$revision=(int)($input['revision']??0);
    $db->begin_transaction();try{
        jrQuery($db,'SELECT id FROM users WHERE id=? FOR UPDATE',[$a['id']]);jrActor($db,false,true);$existing=jrDraft($db,$a,$p);
        if($existing['revision']!==$revision)throw new DomainException('This draft changed in another window. Reload it before saving.');
        if($revision===0)jrQuery($db,'INSERT INTO job_report_drafts(user_id,report_type,period_start,notes,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$a['id'],$p['type'],$p['start'],$json]);
        else jrQuery($db,'UPDATE job_report_drafts SET notes=?,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE user_id=? AND report_type=? AND period_start=?',[$json,$a['id'],$p['type'],$p['start']]);
        $saved=jrDraft($db,$a,$p);$db->commit();return $saved;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function jrCapture(mysqli $db,array $a,array $p,array $notes): array {
    $events=jrActivities($db,$a['id'],$p,[],true);
    return ['format_version'=>1,'staff'=>$a,'period'=>$p,'summary'=>['total'=>$events['total'],'completed'=>$events['completed'],'updated'=>$events['updated'],'modules'=>$events['modules']],'activities'=>$events['rows'],'metrics'=>jrRoleMetrics($db,$a['id'],$p,$a['role']),'notes'=>$notes,'notes_label'=>'Staff-Reported','activity_label'=>'System-Recorded','definitions'=>'Total Activities counts reportable recorded actions, including documented rejections and refused verifications. Completed Actions counts finalized actions, decisions and successful verifications. Updated Records counts recorded updates, not distinct records. QR verification repeats for the same pass and outcome within 60 seconds are suppressed. Login, navigation and unclassifiable legacy audit records are excluded. All times are Asia/Manila.'];
}
function jrPreview(mysqli $db,array $input): array {
    $a=jrActor($db);$p=jrPeriod($input);$notes=jrNotes($input['notes']??[]);
    $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);try{$snapshot=jrCapture($db,$a,$p,$notes);$hash=hash('sha256',jrJson($snapshot));$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
    $total=count($snapshot['activities']);$page=min(max(1,(int)($input['page']??1)),max(1,(int)ceil($total/50)));$snapshot['activities']=array_slice($snapshot['activities'],($page-1)*50,50);
    return ['snapshot'=>$snapshot,'preview_hash'=>$hash,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/50)),'generated_at'=>gmdate('Y-m-d H:i:s')];
}
function jrNotify(mysqli $db,array $report,bool $submission): void {
    $recipients=$submission?jrQuery($db,"SELECT id,email FROM users WHERE role='superadmin' AND is_active=1 AND archived_at IS NULL AND is_verified=1")->get_result()->fetch_all(MYSQLI_ASSOC):jrQuery($db,'SELECT id,email FROM users WHERE id=? AND is_active=1 AND archived_at IS NULL AND is_verified=1',[(int)$report['user_id']])->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($recipients as $recipient){
        if(!filter_var($recipient['email'],FILTER_VALIDATE_EMAIL))continue;
        $kind=$submission?'job_report_submitted':'job_report_review';$id=(int)$report['id'];$target=$submission?'superadmin/staff.php?tab=reports&job_report='.$id:'job_report.php?job_report='.$id;
        $subject=$submission?'Staff job report submitted':'Your job report has a review update';
        $body='<p>'.htmlspecialchars($subject,ENT_QUOTES,'UTF-8').'</p><p>Reference: '.htmlspecialchars($report['reference'],ENT_QUOTES,'UTF-8').'</p><p>Sign in to view the report and any private review feedback.</p><p><a href="'.htmlspecialchars(buildUrl($target),ENT_QUOTES,'UTF-8').'">View job report</a></p>';
        if(!queueNotification($db,'jr:'.$kind.':'.$id.':'.$report['status'].':'.$recipient['id'],'email',$recipient['email'],$subject,$body,(int)$recipient['id'],$kind,$id))throw new RuntimeException('Unable to queue the job report notification.');
    }
}
function jrFile(mysqli $db,array $input): array {
    $a=jrActor($db);$p=jrPeriod($input);$notes=jrNotes($input['notes']??[]);$previous=(int)($input['previous_id']??0);$key=jrKey((string)($input['request_key']??''),$a['id'],'file');
    if(($input['confirmed']??'')!=='1')throw new DomainException('Preview and confirm your report before filing.');
    $digest=hash('sha256',jrJson([$p['type'],$p['period'],$notes,$previous,(string)($input['preview_hash']??'')]));
    $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try{
        jrQuery($db,'SELECT id FROM users WHERE id=? FOR UPDATE',[$a['id']]);$a=jrActor($db,false,true);
        $prior=jrQuery($db,'SELECT id,reference,request_digest FROM job_reports WHERE request_key=? FOR UPDATE',[$key])->get_result()->fetch_assoc();
        if($prior){if(!hash_equals($prior['request_digest'],$digest))throw new DomainException('This request key was already used for different report content.');$db->commit();return ['id'=>(int)$prior['id'],'reference'=>$prior['reference'],'duplicate'=>true];}
        $latest=jrQuery($db,'SELECT id,status,version FROM job_reports WHERE user_id=? AND report_type=? AND period_start=? ORDER BY version DESC LIMIT 1 FOR UPDATE',[$a['id'],$p['type'],$p['start']])->get_result()->fetch_assoc();
        if($latest&&($latest['status']!=='returned'||$previous!==(int)$latest['id']))throw new DomainException('This period already has a filed report. Only the latest returned version can be corrected.');
        if(!$latest&&$previous)throw new DomainException('The original returned report could not be found.');
        $snapshot=jrCapture($db,$a,$p,$notes);$previewHash=hash('sha256',jrJson($snapshot));
        if(!hash_equals($previewHash,(string)($input['preview_hash']??'')))throw new DomainException('Activity or report information changed after preview. Preview the report again before filing.');
        $snapshot['generated_at']=gmdate('Y-m-d H:i:s');$json=jrJson($snapshot);$hash=hash('sha256',$json);$version=$latest?(int)$latest['version']+1:1;$previousId=$previous?:null;
        $reference='JR-'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(7)));
        jrQuery($db,"INSERT INTO job_reports(reference,user_id,staff_id,staff_name,staff_role,report_type,period_start,period_end,version,previous_report_id,request_key,request_digest,snapshot,snapshot_hash,filed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$reference,$a['id'],$a['staff_id'],$a['name'],$a['role'],$p['type'],$p['start'],$p['end'],$version,$previousId,$key,$digest,$json,$hash]);
        $id=(int)$db->insert_id;
        jrQuery($db,"INSERT INTO job_report_reviews(report_id,actor_id,actor_name,action,feedback,created_at,request_key) VALUES(?,?,?,'submitted','',UTC_TIMESTAMP(6),?)",[$id,$a['id'],$a['name'],hash('sha256','submitted:'.$key)]);
        if(!logAudit('file','job_report',$id,'Staff job report filed.',$db))throw new RuntimeException('Unable to record report filing.');
        jrNotify($db,['id'=>$id,'reference'=>$reference,'user_id'=>$a['id'],'status'=>'submitted'],true);$db->commit();return ['id'=>$id,'reference'=>$reference,'duplicate'=>false];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function jrLoad(mysqli $db,int $id,bool $management=false): array {
    $a=jrActor($db,$management);$row=jrQuery($db,'SELECT * FROM job_reports WHERE id=?'.($management?'':' AND user_id=?'),$management?[$id]:[$id,$a['id']])->get_result()->fetch_assoc();
    if(!$row)throw new DomainException('Report not found or access denied.');
    if(!hash_equals($row['snapshot_hash'],hash('sha256',$row['snapshot'])))throw new RuntimeException('Report integrity verification failed.');
    $row['data']=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);unset($row['snapshot'],$row['request_digest'],$row['request_key']);
    $row['reviews']=jrQuery($db,'SELECT actor_name,action,feedback,created_at FROM job_report_reviews WHERE report_id=? ORDER BY id',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
    $row['can_review']=$management&&$a['id']!==(int)$row['user_id']&&$row['status']==='submitted';
    $latest=jrQuery($db,'SELECT id FROM job_reports WHERE user_id=? AND report_type=? AND period_start=? ORDER BY version DESC LIMIT 1',[(int)$row['user_id'],$row['report_type'],$row['period_start']])->get_result()->fetch_assoc();
    $row['can_correct']=!$management&&$row['status']==='returned'&&(int)$latest['id']===$id;$row['self_filed']=$management&&$a['id']===(int)$row['user_id'];return $row;
}
function jrReview(mysqli $db,array $input): array {
    $a=jrActor($db,true);$id=(int)($input['id']??0);$action=(string)($input['decision']??'');$feedback=trim((string)($input['feedback']??''));
    if(!in_array($action,['reviewed','returned'],true))throw new DomainException('Choose a valid review decision.');
    if(mb_strlen($feedback)>4000||!mb_check_encoding($feedback,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$feedback)||($action==='returned'&&mb_strlen(preg_replace('/\s+/u','',$feedback))<10))throw new DomainException('A return requires meaningful feedback of at least 10 non-space characters (maximum 4,000).');
    $key=jrKey((string)($input['request_key']??''),$a['id'],'review');
    $db->begin_transaction();try{
        jrQuery($db,'SELECT id FROM users WHERE id=? FOR UPDATE',[$a['id']]);jrActor($db,true,true);
        $row=jrQuery($db,'SELECT id,user_id,status,reference FROM job_reports WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc();
        if(!$row||$a['id']===(int)$row['user_id'])throw new DomainException('An independent Super Admin must review this report. Self-filed reports remain Submitted until another authorized Super Admin reviews them.');
        $prior=jrQuery($db,'SELECT report_id,action,feedback FROM job_report_reviews WHERE request_key=?',[$key])->get_result()->fetch_assoc();
        if($prior){if((int)$prior['report_id']!==$id||$prior['action']!==$action||$prior['feedback']!==$feedback)throw new DomainException('This review request key was already used.');$db->commit();return ['id'=>$id,'duplicate'=>true];}
        if($row['status']!=='submitted')throw new DomainException('Only a submitted report may be reviewed or returned.');
        jrQuery($db,'INSERT INTO job_report_reviews(report_id,actor_id,actor_name,action,feedback,created_at,request_key) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6),?)',[$id,$a['id'],$a['name'],$action,$feedback,$key]);
        jrQuery($db,"UPDATE job_reports SET status=? WHERE id=? AND status='submitted'",[$action,$id]);
        if(!logAudit($action,'job_report',$id,'Staff job report review recorded.',$db))throw new RuntimeException('Unable to record report review.');
        $row['status']=$action;jrNotify($db,$row,false);$db->commit();return ['id'=>$id,'duplicate'=>false];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function jrList(mysqli $db,array $f,bool $management=false): array {
    $a=jrActor($db,$management);$where=' WHERE 1=1';$params=[];
    if(!$management){$where.=' AND user_id=?';$params[]=$a['id'];}
    foreach(['role'=>'staff_role','type'=>'report_type','status'=>'status']as$key=>$col)if(($f[$key]??'')!==''){
        $value=(string)$f[$key];$allowed=$key==='role'?JR_ROLES:($key==='type'?['daily','monthly']:array_keys(JR_STATUSES));if(!in_array($value,$allowed,true))throw new DomainException('Invalid report filter.');$where.=" AND $col=?";$params[]=$value;
    }
    if(trim($f['q']??'')!==''){$q=trim((string)$f['q']);if(mb_strlen($q)>120)throw new DomainException('Search must be 120 characters or fewer.');$where.=' AND (LOWER(staff_id) LIKE LOWER(?) OR LOWER(staff_name) LIKE LOWER(?) OR LOWER(reference) LIKE LOWER(?))';$q='%'.addcslashes($q,'%_\\').'%';array_push($params,$q,$q,$q);}
    foreach(['day','month','submitted_date']as$key)if(($f[$key]??'')!==''){
        $p=jrPeriod(['type'=>$key==='month'?'monthly':'daily','period'=>(string)$f[$key]]);
        if($key==='submitted_date'){$where.=' AND filed_at>=? AND filed_at<?';array_push($params,$p['utc_start'],$p['utc_end']);}
        else{$where.=' AND period_start<? AND period_end>?';array_push($params,$p['end'],$p['start']);}
    }
    $stats=jrQuery($db,"SELECT COUNT(*) total,COALESCE(SUM(status='submitted'),0) submitted,COALESCE(SUM(status='reviewed'),0) reviewed,COALESCE(SUM(status='returned'),0) returned,COALESCE(SUM(report_type='daily'),0) daily,COALESCE(SUM(report_type='monthly'),0) monthly FROM job_reports$where",$params)->get_result()->fetch_assoc();
    $page=max(1,(int)($f['page']??1));$pages=max(1,(int)ceil((int)$stats['total']/20));$page=min($page,$pages);$offset=($page-1)*20;$sort=($f['sort']??'newest')==='oldest'?'ASC':'DESC';
    $rows=jrQuery($db,"SELECT id,reference,user_id,staff_id,staff_name,staff_role,report_type,period_start,period_end,version,previous_report_id,status,filed_at FROM job_reports$where ORDER BY filed_at $sort,id $sort LIMIT 20 OFFSET $offset",$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($rows as &$r)$r['self_filed']=$management&&$a['id']===(int)$r['user_id'];unset($r);return ['rows'=>$rows,'stats'=>$stats,'page'=>$page,'pages'=>$pages,'total'=>(int)$stats['total']];
}
function jrNotificationRelevant(mysqli $db,array $job): bool {
    $row=jrQuery($db,'SELECT r.user_id,r.status,u.role,u.is_active FROM job_reports r JOIN users u ON u.id=? WHERE r.id=?',[(int)$job['user_id'],(int)$job['entity_id']])->get_result()->fetch_assoc();
    if(!$row||!(int)$row['is_active'])return false;
    if($job['event_kind']==='job_report_review'&&$row['status']==='returned'&&jrQuery($db,'SELECT id FROM job_reports WHERE previous_report_id=?',[(int)$job['entity_id']])->get_result()->num_rows)return false;
    return $job['event_kind']==='job_report_submitted'?$row['role']==='superadmin'&&$row['status']==='submitted':in_array($row['role'],JR_ROLES,true)&&(int)$row['user_id']===(int)$job['user_id']&&in_array($row['status'],['reviewed','returned'],true);
}
