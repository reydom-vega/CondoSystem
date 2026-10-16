<?php
function reportLoad(mysqli $db,string $reference,bool $management=false,bool $anonymous=false,bool $lock=false): array {
    $denied=$anonymous&&!$management?'The report reference or private access session is invalid, expired or revoked. Enter your private access code again.':'Report not found or access denied.';
    if(!preg_match('/^RPT-\d{4}-\d{6,12}$/D',$reference))throw new InvalidArgumentException($denied);
    if($management){if(!isLoggedIn())throw new InvalidArgumentException($denied);$where=reportReviewerPredicate($db,(int)$_SESSION['user_id']);}
    elseif($anonymous)$where="r.reporting_mode='anonymous'";
    else{$id=reportRequireResident($db);$where="r.reporting_mode='identified' AND EXISTS(SELECT 1 FROM report_identities ri WHERE ri.report_id=r.id AND ri.user_id=$id)";}
    $s=$db->prepare("SELECT r.* FROM community_reports r WHERE r.reference=? AND ($where)".($lock?' FOR UPDATE':''));$s->bind_param('s',$reference);$s->execute();$case=$s->get_result()->fetch_assoc();
    if(!$case)throw new InvalidArgumentException($denied);
    if($anonymous&&!$management){
        $grant=$_SESSION['anonymous_report_access'][$reference]??null;
        $s=$db->prepare('SELECT token_hash FROM report_access_credentials WHERE report_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');$s->bind_param('i',$case['id']);$s->execute();$credential=$s->get_result()->fetch_assoc();
        if(!$credential||!is_array($grant)||($grant['until']??0)<=time()||!hash_equals($credential['token_hash'],$grant['hash']??''))throw new InvalidArgumentException($denied);
    }
    if(!reportVerifyAudit($db,$case))throw new RuntimeException('The case audit history needs an integrity review.');return $case;
}
function reportTrackAnonymous(mysqli $db,string $reference,string $code): array {
    if(!allowAuthenticationRequest($db,'community-report-tracking',15))throw new InvalidArgumentException('Too many tracking attempts. Please try again later.');
    $s=$db->prepare("SELECT r.id,r.reference,c.token_hash,c.expires_at FROM community_reports r JOIN report_access_credentials c ON c.report_id=r.id WHERE r.reference=? AND r.reporting_mode='anonymous' AND c.revoked_at IS NULL AND c.expires_at>UTC_TIMESTAMP()");$s->bind_param('s',$reference);$s->execute();$row=$s->get_result()->fetch_assoc();
    if(!preg_match('/^[a-f0-9]{64}$/D',$code)||!$row||!hash_equals($row['token_hash'],hash('sha256',$code)))throw new InvalidArgumentException('The reference or private access code is invalid, expired or revoked. Lost codes cannot be recovered through an account lookup.');
    // This session capability contains no reporter account association.
    $_SESSION['anonymous_report_access'][$reference]=['hash'=>$row['token_hash'],'until'=>min(time()+1800,strtotime($row['expires_at'].' UTC'))];
    return ['reference'=>$reference];
}
function reportNotify(mysqli $db,array $case,int $event,bool $reporter): void {
    $contacts=[];
    if($reporter&&$case['reporting_mode']==='identified'){
        $s=$db->prepare('SELECT u.id,u.email FROM report_identities ri JOIN users u ON u.id=ri.user_id WHERE ri.report_id=?');$s->bind_param('i',$case['id']);$s->execute();foreach($s->get_result()as$row)$contacts[(int)$row['id']]=$row;
    }
    if(!$reporter)foreach($db->query("SELECT id,email FROM users WHERE role IN ('admin','superadmin') AND is_active=1 AND archived_at IS NULL AND is_verified=1 AND status='approved'")as$row)if(reportCanReview($db,(int)$case['id'],(int)$row['id']))$contacts[(int)$row['id']]=$row;
    foreach($contacts as$id=>$contact){
        $html=emailLayout('Private Community Report','A private case update is available','<p>There is an update for case '.htmlspecialchars($case['reference'],ENT_QUOTES,'UTF-8').'. Sign in to the secure Reports page to review it.</p>');
        if(!queueNotification($db,'community-report:'.$case['id'].':event:'.$event.':recipient:'.$id,'email',$contact['email'],'[The Celandine] Private report update',$html,$id,'community_report',(int)$case['id']))throw new RuntimeException('Unable to queue the private case update.');
    }
}
function reportNotificationRelevant(mysqli $db,array $job): bool {
    $id=(int)$job['entity_id'];$uid=(int)$job['user_id'];
    $s=$db->prepare("SELECT ri.user_id FROM community_reports r LEFT JOIN report_identities ri ON ri.report_id=r.id WHERE r.id=?");$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();
    return $row&&((int)($row['user_id']??0)===$uid||reportCanReview($db,$id,$uid));
}
function reportCreate(mysqli $db,array $input,array $uploads=[]): array {
    $user=reportRequireResident($db);if(!allowAuthenticationRequest($db,'community-report-submit',12))throw new InvalidArgumentException('Too many submissions. Please try again later.');
    $data=reportInput($input);$request=reportRequestHash((string)($input['request_key']??''));$digest=hash('sha256',json_encode(reportOriginalData($data),JSON_THROW_ON_ERROR));
    [$scope,$conflicts]=reportDetectConflicts($db,$data);$files=reportStageEvidence($uploads,$data['reporting_mode']==='anonymous');$committed=false;
    try{
        $db->begin_transaction();
        $s=$db->prepare('SELECT r.reference,r.submission_hash,r.reporting_mode,ri.user_id FROM community_reports r LEFT JOIN report_identities ri ON ri.report_id=r.id WHERE r.request_hash=? FOR UPDATE');$s->bind_param('s',$request);$s->execute();$prior=$s->get_result()->fetch_assoc();
        if($prior){if(!hash_equals($prior['submission_hash'],$digest)||($prior['reporting_mode']==='identified'&&(int)$prior['user_id']!==$user))throw new InvalidArgumentException('This form was already used. Start a new report.');$db->rollback();reportRemoveStaged($files);return ['reference'=>$prior['reference'],'duplicate'=>true,'access_code'=>null];}
        $time=$data['incident_time']?:null;$priority=$data['urgency'];
        $s=$db->prepare('INSERT INTO community_reports(reporting_mode,subject,category,other_category,incident_date,incident_time,location,location_details,unit_reference,description,person_kind,person_name,person_unit,person_position,person_details,urgency,priority,review_scope,request_hash,submission_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');
        $s->bind_param(str_repeat('s',20),$data['reporting_mode'],$data['subject'],$data['category'],$data['other_category'],$data['incident_date'],$time,$data['location'],$data['location_details'],$data['unit_reference'],$data['description'],$data['person_kind'],$data['person_name'],$data['person_unit'],$data['person_position'],$data['person_details'],$data['urgency'],$priority,$scope,$request,$digest);$s->execute();$id=(int)$db->insert_id;$reference='RPT-'.date('Y').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
        $s=$db->prepare('UPDATE community_reports SET reference=? WHERE id=?');$s->bind_param('si',$reference,$id);$s->execute();
        $case=['id'=>$id,'reference'=>$reference,'reporting_mode'=>$data['reporting_mode'],'audit_head'=>''];$code=null;
        if($data['reporting_mode']==='identified'){$s=$db->prepare('INSERT INTO report_identities(report_id,user_id) VALUES(?,?)');$s->bind_param('ii',$id,$user);$s->execute();}
        else{$code=bin2hex(random_bytes(32));$hash=hash('sha256',$code);$days=reportSettingInt('CONDO_REPORT_ANONYMOUS_ACCESS_DAYS',365,1,3650);$expires=gmdate('Y-m-d H:i:s',time()+$days*86400);$s=$db->prepare('INSERT INTO report_access_credentials(report_id,token_hash,expires_at) VALUES(?,?,?)');$s->bind_param('iss',$id,$hash,$expires);$s->execute();}
        foreach($conflicts as$conflict){$s=$db->prepare('INSERT INTO report_conflicts(report_id,user_id) VALUES(?,?)');$s->bind_param('ii',$id,$conflict);$s->execute();}
        $event=reportAppendEvent($db,$case,$request,'submitted','reporter',$code?'anonymous':'reporter',$code?null:$user,'Your report has been submitted successfully.',null,'submitted',['submission_hash'=>$digest,'evidence'=>array_column($files,'content_hash')]);reportAttachEvidence($db,$id,$event,$files);
        $created=$db->query('SELECT created_at FROM community_reports WHERE id='.$id)->fetch_assoc()['created_at'];
        reportNotify($db,$case,$event,true);reportNotify($db,$case,$event,false);$db->commit();$committed=true;
        if($code)$_SESSION['anonymous_report_access'][$reference]=['hash'=>hash('sha256',$code),'until'=>min(time()+1800,strtotime($expires.' UTC'))];
        return ['reference'=>$reference,'status'=>'submitted','submitted_at'=>$created,'access_code'=>$code,'duplicate'=>false];
    }catch(Throwable $error){$db->rollback();throw $error;}finally{if(!$committed)reportRemoveStaged($files);}
}
function reportEligibleReviewers(mysqli $db,array $case): array {
    $out=[];foreach($db->query("SELECT id,full_name FROM users WHERE role IN ('admin','superadmin') AND is_active=1 AND archived_at IS NULL AND is_verified=1 AND status='approved'")as$row)if(reportCanReview($db,(int)$case['id'],(int)$row['id']))$out[]=['id'=>(int)$row['id'],'name'=>$row['full_name']];return $out;
}
function reportAction(mysqli $db,array $input,array $uploads=[],bool $management=false,bool $anonymous=false): array {
    $reference=reportText($input,'reference',24,true);$request=reportRequestHash((string)($input['request_key']??''));$action=reportText($input,'operation',30,true);$body=reportText($input,'body',6000);
    if(!allowAuthenticationRequest($db,'community-report-action',60))throw new InvalidArgumentException('Too many case updates. Try again later.');
    $case=reportLoad($db,$reference,$management,$anonymous);$files=reportStageEvidence($uploads,$case['reporting_mode']==='anonymous');$committed=false;
    try{
        $db->begin_transaction();
        $case=reportLoad($db,$reference,$management,$anonymous,true);
        $s=$db->prepare('SELECT id FROM report_events WHERE report_id=? AND request_hash=?');$s->bind_param('is',$case['id'],$request);$s->execute();if($s->get_result()->fetch_assoc()){$db->rollback();reportRemoveStaged($files);return ['reference'=>$reference,'duplicate'=>true];}
        if((string)($input['version']??'')!==(string)$case['version'])throw new InvalidArgumentException('This case changed. Reload it before updating.');
        $old=$case['status'];$new=null;$type=$action;$visibility='reporter';$payload=[];$actor=$management?(int)$_SESSION['user_id']:($anonymous?null:(int)$_SESSION['user_id']);$kind=$management?'management':($anonymous?'anonymous':'reporter');
        if(!$management&&!in_array($action,['reply','withdraw','rotate_code'],true))throw new InvalidArgumentException('This action is unavailable to reporters.');
        if($action==='reply'||$action==='note'){
            if($body===''&&!$files)throw new InvalidArgumentException('Add a message or evidence.');
            if(!$management&&in_array($old,['closed','declined','withdrawn'],true))throw new InvalidArgumentException('This case is no longer accepting replies.');
            if($action==='note'){$visibility='internal';if(!$management)throw new InvalidArgumentException('Internal notes are management-only.');}
            $type=$action==='note'?'internal_note':($management?'management_update':'reporter_reply');
        }elseif($action==='status'){
            $new=reportText($input,'status',30,true);$transitions=reportStatusTransitions($old);
            if(!$management||!in_array($new,$transitions,true)||$body==='')throw new InvalidArgumentException('Choose a permitted status and provide a reporter-visible explanation.');$type='status_changed';
        }elseif($action==='withdraw'){
            if($management||!in_array($old,['submitted','under_review','awaiting_information'],true)||$body==='')throw new InvalidArgumentException('Withdrawal requires a reason and an eligible open status.');$new='withdrawn';$type='status_changed';
        }elseif($action==='priority'){
            $priority=reportText($input,'priority',15,true);if(!$management||!in_array($priority,['normal','important','urgent'],true)||$body==='')throw new InvalidArgumentException('Choose a priority and record an internal reason.');
            $s=$db->prepare('UPDATE community_reports SET priority=? WHERE id=?');$s->bind_param('si',$priority,$case['id']);$s->execute();$visibility='internal';$payload=['priority'=>$priority];
        }elseif($action==='assign'){
            $assignee=filter_var($input['reviewer_id']??'',FILTER_VALIDATE_INT);if(!$management||!$assignee||!reportCanReview($db,(int)$case['id'],$assignee)||$body==='')throw new InvalidArgumentException('Select an eligible independent reviewer and record a reason.');
            $s=$db->prepare('UPDATE community_reports SET assigned_reviewer_id=? WHERE id=?');$s->bind_param('ii',$assignee,$case['id']);$s->execute();$visibility='internal';$payload=['reviewer_id'=>$assignee];
        }elseif($action==='escalate'){
            $scope=reportText($input,'review_scope',20,true);$rank=['ordinary'=>0,'admin'=>1,'oversight'=>2];if(!$management||!isset($rank[$scope])||$rank[$scope]<=$rank[$case['review_scope']]||$body==='')throw new InvalidArgumentException('Escalation must increase the restriction and include an internal reason.');
            $s=$db->prepare('UPDATE community_reports SET review_scope=?,assigned_reviewer_id=NULL WHERE id=?');$s->bind_param('si',$scope,$case['id']);$s->execute();$visibility='internal';$payload=['review_scope'=>$scope];
        }elseif($action==='conflict'){
            $conflict=filter_var($input['conflict_user_id']??'',FILTER_VALIDATE_INT);if(!$management||!$conflict||$body==='')throw new InvalidArgumentException('Select a staff conflict and document the reason.');
            $s=$db->prepare("SELECT role FROM users WHERE id=? AND role<>'resident'");$s->bind_param('i',$conflict);$s->execute();$who=$s->get_result()->fetch_assoc();if(!$who)throw new InvalidArgumentException('Choose a staff account.');
            $s=$db->prepare('INSERT IGNORE INTO report_conflicts(report_id,user_id) VALUES(?,?)');$s->bind_param('ii',$case['id'],$conflict);$s->execute();
            $scope=$who['role']==='superadmin'?'oversight':($who['role']==='admin'&&$case['review_scope']==='ordinary'?'admin':$case['review_scope']);
            $s=$db->prepare('UPDATE community_reports SET review_scope=?,assigned_reviewer_id=NULL WHERE id=?');$s->bind_param('si',$scope,$case['id']);$s->execute();$visibility='internal';$payload=['conflict_user_id'=>$conflict,'review_scope'=>$scope];
        }elseif($action==='revoke_code'){
            if(!$management||$case['reporting_mode']!=='anonymous'||$body==='')throw new InvalidArgumentException('Provide an internal reason for revoking anonymous access.');
            $s=$db->prepare('UPDATE report_access_credentials SET revoked_at=UTC_TIMESTAMP() WHERE report_id=?');$s->bind_param('i',$case['id']);$s->execute();$visibility='internal';
        }elseif($action==='rotate_code'){
            if($management||!$anonymous||$case['reporting_mode']!=='anonymous')throw new InvalidArgumentException('Only the anonymous credential holder can renew their code.');
            $code=bin2hex(random_bytes(32));$hash=hash('sha256',$code);$expires=gmdate('Y-m-d H:i:s',time()+reportSettingInt('CONDO_REPORT_ANONYMOUS_ACCESS_DAYS',365,1,3650)*86400);
            $s=$db->prepare('UPDATE report_access_credentials SET token_hash=?,expires_at=?,revoked_at=NULL WHERE report_id=?');$s->bind_param('ssi',$hash,$expires,$case['id']);$s->execute();$body='Private access code renewed.';
        }else throw new InvalidArgumentException('Unknown report action.');
        if($files&&!in_array($action,['reply','note'],true))throw new InvalidArgumentException('Attach evidence to a message or internal note.');
        if($new!==null){$closed=in_array($new,['closed','declined','withdrawn'],true)?gmdate('Y-m-d H:i:s'):null;$s=$db->prepare('UPDATE community_reports SET status=?,closed_at=? WHERE id=?');$s->bind_param('ssi',$new,$closed,$case['id']);$s->execute();}
        $event=reportAppendEvent($db,$case,$request,$type,$visibility,$kind,$actor,$body,$new!==null?$old:null,$new,$payload+['evidence'=>array_column($files,'content_hash')]);reportAttachEvidence($db,(int)$case['id'],$event,$files);
        $s=$db->prepare('UPDATE community_reports SET version=version+1 WHERE id=?');$s->bind_param('i',$case['id']);$s->execute();
        if($management&&$visibility==='reporter')reportNotify($db,$case,$event,true);if(!$management||in_array($action,['assign','escalate','conflict'],true))reportNotify($db,$case,$event,false);
        $db->commit();$committed=true;
        if(isset($code))$_SESSION['anonymous_report_access'][$reference]=['hash'=>$hash,'until'=>time()+1800];
        return ['reference'=>$reference,'access_code'=>$code??null,'still_authorized'=>!$management||reportCanReview($db,(int)$case['id'],(int)$_SESSION['user_id'])];
    }catch(Throwable $error){$db->rollback();throw $error;}finally{if(!$committed)reportRemoveStaged($files);}
}
