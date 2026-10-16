<?php
/** Private complaints; never creates a violation, penalty or payment. */
const REPORT_STATUSES=['submitted'=>'Submitted','under_review'=>'Under Review','awaiting_information'=>'Awaiting Information','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed','declined'=>'Declined','withdrawn'=>'Withdrawn'];
const REPORT_PERSON_KINDS=['Resident / Neighbor','Tenant','Security Personnel','Maintenance Staff','Administrative Staff','Other Staff','Visitor','Unknown Person','Multiple Persons','No Specific Person'];
const REPORT_LOCATIONS=['Unit','Hallway','Elevator','Lobby','Swimming Pool','Parking Area','Common Area','Other'];
function reportStatusTransitions(string $status): array {
    return [
        'submitted'=>['under_review','awaiting_information','in_progress','declined','closed'],
        'under_review'=>['awaiting_information','in_progress','resolved','declined','closed'],
        'awaiting_information'=>['under_review','in_progress','resolved','declined','closed'],
        'in_progress'=>['under_review','awaiting_information','resolved','declined','closed'],
        'resolved'=>['in_progress','closed'], 'closed'=>['under_review'],
        'declined'=>['under_review'], 'withdrawn'=>['under_review'],
    ][$status] ?? [];
}
function reportCategories(): array {
    $defaults=['Neighbor Complaint','Staff Misconduct','Security Concern','Noise Disturbance','Harassment or Intimidation','Parking Complaint','Cleanliness and Sanitation','Facility or Common Area Concern','Pet-Related Complaint','Smoking Violation','Unauthorized Renovation','Property Rule Violation','Property Damage','Safety Hazard','Privacy Concern','Other'];
    $custom=json_decode(appSetting('CONDO_REPORT_CATEGORIES','[]'),true);
    if(!is_array($custom)||count($custom)>40) return $defaults;
    foreach($custom as $name) if(!is_string($name)||trim($name)===''||mb_strlen($name)>80) return $defaults;
    return $custom ? array_values(array_unique([...$custom,'Other'])) : $defaults;
}
function reportSettingInt(string $name,int $default,int $min,int $max): int {
    $value=filter_var(appSetting($name,(string)$default),FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if($value===false) throw new RuntimeException('Invalid community-report configuration.');return $value;
}
function reportStorageDirectory(bool $create=false): string {
    $app=dirname(__DIR__);$dir=appSetting('CONDO_REPORT_STORAGE') ?: dirname($app,2).'/condo_private/'.substr(hash('sha256',$app),0,16).'/reports';
    if(!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$dir)||in_array('..',explode('/',str_replace('\\','/',$dir)),true)) throw new RuntimeException('Report storage must be an absolute private path.');
    $parent=$dir;while(!file_exists($parent)&&dirname($parent)!==$parent)$parent=dirname($parent);
    foreach([$app,dirname($app),$_SERVER['DOCUMENT_ROOT']??'']as$public)if($public&&realpath($public)&&realpath($parent)&&visitorPathWithin(realpath($parent),realpath($public)))throw new RuntimeException('Report storage must be outside the document root.');
    if($create&&!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private evidence storage is unavailable.');
    if(!realpath($dir))throw new RuntimeException('Run the community reports migration to prepare private storage.');return realpath($dir);
}
function ensureCommunityReportSchema(mysqli $db): bool {
    if(!schemaMutationAllowed())return true;
    $sql=preg_replace('/--[^\r\n]*/','',file_get_contents(__DIR__.'/../migrations/2026_10_14_community_reports.sql'));
    foreach(explode(';',$sql)as$statement)if(trim($statement)!=='')$db->query($statement);
    $db->query("CREATE TABLE IF NOT EXISTS access_signing_keys (key_name VARCHAR(30) PRIMARY KEY, key_value CHAR(64) NOT NULL) ENGINE=InnoDB");
    $key=bin2hex(random_bytes(32));$s=$db->prepare("INSERT IGNORE INTO access_signing_keys(key_name,key_value) VALUES('report-audit',?)");$s->bind_param('s',$key);$s->execute();
    reportStorageDirectory(true);return true;
}
function reportAuditKey(mysqli $db): string {
    $key=appSetting('CONDO_REPORT_AUDIT_KEY');
    if($key==='')$key=$db->query("SELECT key_value FROM access_signing_keys WHERE key_name='report-audit'")->fetch_assoc()['key_value']??'';
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Report audit signing key is not configured.');return hex2bin($key);
}
function reportReviewer(mysqli $db,int $id): ?array {
    $s=$db->prepare("SELECT id,role,full_name,email FROM users WHERE id=? AND role IN ('admin','superadmin') AND is_active=1 AND archived_at IS NULL AND is_verified=1 AND status='approved'");$s->bind_param('i',$id);$s->execute();return $s->get_result()->fetch_assoc()?:null;
}
function reportIndependentIds(): array {
    $value=appSetting('CONDO_REPORT_INDEPENDENT_REVIEWER_IDS');
    if($value===''||!preg_match('/^\d+(?:\s*,\s*\d+)*$/D',$value))return [];
    return array_values(array_unique(array_filter(array_map('intval',explode(',',$value)),static fn(int $id):bool=>$id>0)));
}
/** SQL predicate is reused by detail, list, stats, notifications and evidence. */
function reportReviewerPredicate(mysqli $db,int $id,string $alias='r'): string {
    $actor=reportReviewer($db,$id);if(!$actor)return '0=1';
    $scopes=["'ordinary'"];if($actor['role']==='superadmin')$scopes[]="'admin'";
    if(in_array($id,reportIndependentIds(),true))$scopes[]="'oversight'";
    return "$alias.review_scope IN (".implode(',',$scopes).") AND NOT EXISTS(SELECT 1 FROM report_conflicts rc WHERE rc.report_id=$alias.id AND rc.user_id=".(int)$id.')';
}
function reportCanReview(mysqli $db,int $reportId,int $userId): bool {
    $where=reportReviewerPredicate($db,$userId);$s=$db->prepare("SELECT r.id FROM community_reports r WHERE r.id=? AND ($where)");$s->bind_param('i',$reportId);$s->execute();return (bool)$s->get_result()->fetch_assoc();
}
function reportRequireResident(mysqli $db): int {
    if(!isLoggedIn()||!residentUserHasPermission($db,(int)($_SESSION['user_id']??0),'resident.reports.use'))throw new InvalidArgumentException('An approved residential account is required.');return (int)$_SESSION['user_id'];
}
function reportText(array $input,string $name,int $limit,bool $required=false): string {
    if(!is_string($input[$name]??''))throw new InvalidArgumentException('Invalid report field.');$value=trim($input[$name]??'');
    if(($required&&$value==='')||mb_strlen($value)>$limit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new InvalidArgumentException(ucwords(str_replace('_',' ',$name)).' is required or exceeds its length limit.');return $value;
}
function reportInput(array $input): array {
    $out=[];foreach(['reporting_mode'=>20,'subject'=>160,'category'=>80,'other_category'=>160,'incident_date'=>10,'incident_time'=>5,'location'=>60,'location_details'=>200,'unit_reference'=>60,'description'=>12000,'person_kind'=>40,'person_name'=>160,'person_unit'=>60,'person_position'=>100,'person_details'=>1000,'urgency'=>15,'staff_scope'=>15]as$name=>$limit)$out[$name]=reportText($input,$name,$limit,in_array($name,['reporting_mode','subject','category','incident_date','location','description','person_kind','urgency'],true));
    if(!in_array($out['reporting_mode'],['identified','anonymous'],true)||!in_array($out['category'],reportCategories(),true)||!in_array($out['location'],REPORT_LOCATIONS,true)||!in_array($out['person_kind'],REPORT_PERSON_KINDS,true)||!in_array($out['urgency'],['normal','important','urgent'],true))throw new InvalidArgumentException('Choose valid report options.');
    if($out['category']==='Other'&&$out['other_category']==='')throw new InvalidArgumentException('Clarify the Other category.');
    if($out['location']==='Other'&&$out['location_details']==='')throw new InvalidArgumentException('Describe the incident location.');
    if(!workflowDate($out['incident_date'])||$out['incident_date']>date('Y-m-d'))throw new InvalidArgumentException('Choose an incident date today or in the past.');
    if($out['incident_time']!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$out['incident_time']))throw new InvalidArgumentException('Choose a valid incident time or Unknown.');
    if(($input['confirm_accuracy']??'')!=='1')throw new InvalidArgumentException('Confirm the information is accurate to the best of your knowledge.');
    if(!in_array($out['staff_scope'],['','staff','admin','superadmin','unsure'],true))throw new InvalidArgumentException('Choose a valid staff concern scope.');
    if($out['person_kind']==='Administrative Staff'&&!in_array($out['staff_scope'],['admin','superadmin','unsure'],true))throw new InvalidArgumentException('Specify whether this concerns an Admin, Super Admin or an unknown administrative role.');
    return $out;
}
function reportRequestHash(string $key): string {
    if(!preg_match('/^[a-f0-9-]{32,64}$/D',$key))throw new InvalidArgumentException('Refresh the form before submitting.');return hash('sha256',$key);
}
function reportOriginalData(array $data): array {
    $fields=['reporting_mode','subject','category','other_category','incident_date','incident_time','location','location_details','unit_reference','description','person_kind','person_name','person_unit','person_position','person_details','urgency'];$out=[];
    foreach($fields as$field)$out[$field]=(string)($data[$field]??'');if($out['incident_time']!=='')$out['incident_time']=substr($out['incident_time'],0,5);return $out;
}
function reportDetectConflicts(mysqli $db,array $data): array {
    $scope=in_array($data['staff_scope'],['superadmin','unsure'],true)?'oversight':($data['staff_scope']==='admin'?'admin':'ordinary');
    if($data['category']==='Staff Misconduct'&&!in_array($data['person_kind'],['Security Personnel','Maintenance Staff'],true)&&!in_array($data['staff_scope'],['admin','superadmin'],true))$scope='oversight';
    if(in_array($data['person_kind'],['Other Staff','Multiple Persons'],true)&&$data['staff_scope']==='')$scope='oversight';
    $text=mb_strtolower(implode(' ',[$data['person_name'],$data['person_position'],$data['person_details'],$data['subject'],$data['description']]));$conflicts=[];
    foreach($db->query("SELECT id,username,full_name,role FROM users WHERE role IN ('admin','superadmin','security','maintenance','treasurer') AND is_active=1 AND archived_at IS NULL")as$staff){
        foreach([$staff['username'],$staff['full_name']]as$name){$name=mb_strtolower(trim($name));if($name!==''&&preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($name,'/').'(?![\p{L}\p{N}_])/u',$text)){
            $conflicts[]=(int)$staff['id'];if($staff['role']==='superadmin')$scope='oversight';elseif($staff['role']==='admin'&&$scope==='ordinary')$scope='admin';break;
        }}
    }
    return [$scope,array_unique($conflicts)];
}
function reportEventHash(mysqli $db,array $event): string {
    $fields=['report_id','request_hash','event_type','visibility','actor_kind','actor_user_id','old_status','new_status','body','payload','previous_hash','created_at'];$canonical=[];
    foreach($fields as$field)$canonical[$field]=$event[$field]===null?null:(string)$event[$field];
    return hash_hmac('sha256',json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),reportAuditKey($db));
}
/** Caller holds the case row FOR UPDATE; no API permits editing/deleting events. */
function reportAppendEvent(mysqli $db,array &$case,string $request,string $type,string $visibility,string $actorKind,?int $actor,string $body,?string $old=null,?string $new=null,array $payload=[]): int {
    $payload['state_digest']=reportStateDigest($db,(int)$case['id']);
    $created=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $event=['report_id'=>(int)$case['id'],'request_hash'=>$request,'event_type'=>$type,'visibility'=>$visibility,'actor_kind'=>$actorKind,'actor_user_id'=>$actor,'old_status'=>$old,'new_status'=>$new,'body'=>$body,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'previous_hash'=>$case['audit_head'],'created_at'=>$created];$hash=reportEventHash($db,$event);
    $s=$db->prepare('INSERT INTO report_events(report_id,request_hash,event_type,visibility,actor_kind,actor_user_id,old_status,new_status,body,payload,previous_hash,event_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->bind_param('issssisssssss',$event['report_id'],$request,$type,$visibility,$actorKind,$actor,$old,$new,$body,$event['payload'],$case['audit_head'],$hash,$created);$s->execute();$id=(int)$db->insert_id;
    $s=$db->prepare('UPDATE community_reports SET audit_head=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?');$s->bind_param('si',$hash,$case['id']);$s->execute();$case['audit_head']=$hash;return $id;
}
function reportStateDigest(mysqli $db,int $id): string {
    $s=$db->prepare('SELECT status,priority,review_scope,assigned_reviewer_id,closed_at FROM community_reports WHERE id=?');$s->bind_param('i',$id);$s->execute();$state=$s->get_result()->fetch_assoc();
    $s=$db->prepare('SELECT user_id FROM report_conflicts WHERE report_id=? ORDER BY user_id');$s->bind_param('i',$id);$s->execute();$state['conflicts']=array_map('intval',array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'user_id'));
    $s=$db->prepare('SELECT user_id FROM report_identities WHERE report_id=?');$s->bind_param('i',$id);$s->execute();$state['identity']=$s->get_result()->fetch_assoc();
    $s=$db->prepare('SELECT token_hash,expires_at,revoked_at FROM report_access_credentials WHERE report_id=?');$s->bind_param('i',$id);$s->execute();$state['credential']=$s->get_result()->fetch_assoc();
    return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}
function reportVerifyAudit(mysqli $db,array $case): bool {
    if(!hash_equals($case['submission_hash'],hash('sha256',json_encode(reportOriginalData($case),JSON_THROW_ON_ERROR))))return false;
    $s=$db->prepare('SELECT * FROM report_events WHERE report_id=? ORDER BY id');$s->bind_param('i',$case['id']);$s->execute();$head='';$first=true;$payload=[];
    foreach($s->get_result()as$event){
        $payload=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);
        if($first){if(($payload['submission_hash']??'')!==$case['submission_hash'])return false;$first=false;}
        if(!hash_equals($head,$event['previous_hash'])||!hash_equals(reportEventHash($db,$event),$event['event_hash']))return false;
        $head=$event['event_hash'];
        $a=$db->prepare('SELECT content_hash FROM report_attachments WHERE report_id=? AND event_id=? ORDER BY id');$a->bind_param('ii',$case['id'],$event['id']);$a->execute();
        if(array_column($a->get_result()->fetch_all(MYSQLI_ASSOC),'content_hash')!==($payload['evidence']??[]))return false;
    }
    return !$first&&hash_equals($head,$case['audit_head'])&&hash_equals($payload['state_digest']??'',reportStateDigest($db,(int)$case['id']));
}
