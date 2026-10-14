<?php
/** Staff reports reference genuine activity; snapshots contain only sanitized fields. */
const JR_ROLES = ['superadmin','admin','maintenance','treasurer','security'];
const JR_STATUSES = ['submitted'=>'Submitted','reviewed'=>'Reviewed','returned'=>'Returned for Clarification'];
const JR_NOTES = ['accomplished'=>'Work accomplished','additional'=>'Additional tasks','issues'=>'Issues encountered','actions'=>'Actions taken','pending'=>'Pending tasks','remarks'=>'Remarks / recommendations'];

function jrQuery(mysqli $db, string $sql, array $params=[]): mysqli_stmt {
    $s=$db->prepare($sql);
    if($params){$types='';foreach($params as $v)$types.=is_int($v)?'i':'s';$s->bind_param($types,...$params);}
    $s->execute();return $s;
}
function ensureJobReportSchema(mysqli $db): bool {
    if(!schemaMutationAllowed())return true;
    $sql=preg_replace('/--[^\r\n]*/','',file_get_contents(__DIR__.'/../migrations/2026_10_14_job_reports.sql'));
    foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->query($statement);
    foreach(['audit_logs'=>['jr_actor_time','admin_id,created_at'],'qr_scan_logs'=>['jr_actor_time','user_id,event_time_utc'],'report_events'=>['jr_actor_time','actor_user_id,created_at']] as $table=>[$index,$columns]){
        if(!$db->query("SHOW INDEX FROM `$table` WHERE Key_name='$index'")->num_rows)$db->query("ALTER TABLE `$table` ADD INDEX `$index`($columns)");
    }
    $triggers=[];
    $immutable=['reference','user_id','staff_id','staff_name','staff_role','report_type','period_start','period_end','version','previous_report_id','request_key','request_digest','snapshot','snapshot_hash','filed_at'];
    $changes=implode(' OR ',array_map(static fn($c)=>"NOT(NEW.`$c` <=> OLD.`$c`)",$immutable));
    $triggers['jr_snapshot_immutable']="BEFORE UPDATE ON job_reports FOR EACH ROW BEGIN IF $changes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Filed report content is immutable'; END IF; IF NEW.status NOT IN ('submitted','reviewed','returned') OR (NEW.status<>OLD.status AND (OLD.status<>'submitted' OR NEW.status NOT IN ('reviewed','returned'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid report status transition'; END IF; END";
    $triggers['jr_no_delete']='BEFORE DELETE ON job_reports FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'Filed reports cannot be deleted\'';
    foreach(['UPDATE','DELETE']as$verb)$triggers['jr_review_no_'.strtolower($verb)]="BEFORE $verb ON job_report_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Report review history is immutable'";
    foreach($triggers as $name=>$body)if(!jrQuery($db,'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?',[$name])->get_result()->num_rows)$db->query("CREATE TRIGGER `$name` $body");
    return true;
}
function jrActor(mysqli $db, bool $management=false, bool $lock=false): array {
    if(!isLoggedIn())throw new DomainException('Sign in to access job reports.',401);
    $u=jrQuery($db,'SELECT id,staff_id,full_name,username,role,is_active,is_verified,session_version FROM users WHERE id=?'.($lock?' FOR UPDATE':''),[(int)($_SESSION['user_id']??0)])->get_result()->fetch_assoc();
    if(!$u||!in_array($u['role'],JR_ROLES,true)||$u['role']!==($_SESSION['role']??'')||!(int)$u['is_active']||!(int)$u['is_verified']||(isset($_SESSION['session_version'])&&(int)$_SESSION['session_version']!==(int)$u['session_version'])||($management&&$u['role']!=='superadmin'))throw new DomainException('You are not authorized to access these reports.',403);
    if(!$u['staff_id'])throw new DomainException('Your existing staff ID must be assigned by Staff Management before filing.');
    return ['id'=>(int)$u['id'],'staff_id'=>$u['staff_id'],'name'=>$u['full_name']?:$u['username'],'role'=>$u['role']];
}
function jrPeriod(array $input): array {
    $type=(string)($input['type']??'daily');$tz=new DateTimeZone('Asia/Manila');$today=new DateTimeImmutable('today',$tz);
    if(!in_array($type,['daily','monthly'],true))throw new DomainException('Choose a daily or monthly report.');
    $value=(string)($input['period']??$today->format($type==='daily'?'Y-m-d':'Y-m'));
    $format=$type==='daily'?'Y-m-d':'Y-m';$date=DateTimeImmutable::createFromFormat('!'.$format,$value,$tz);
    if(!$date||$date->format($format)!==$value||$date>$today||$date->format('Y')<'2000')throw new DomainException('Choose a valid current or previous reporting period.');
    $end=$date->modify($type==='daily'?'+1 day':'+1 month');
    return ['type'=>$type,'period'=>$value,'start'=>$date->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'local_start'=>$date->format('Y-m-d H:i:s'),'local_end'=>$end->format('Y-m-d H:i:s'),'utc_start'=>$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'utc_end'=>$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'label'=>$date->format($type==='daily'?'F j, Y':'F Y'),'timezone'=>'Asia/Manila'];
}
function jrNotes(array $input): array {
    $notes=[];foreach(JR_NOTES as $key=>$label){$v=$input[$key]??'';if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>4000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v))throw new DomainException($label.' must be plain text of 4,000 characters or fewer.');$notes[$key]=trim($v);}return $notes;
}
function jrLimit(): int {return max(100,min(5000,(int)appSetting('CONDO_JOB_REPORT_MAX_ACTIVITIES','2000')));}
function jrJson(array $data): string {return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function jrKey(string $key, int $actor, string $action): string {if(!preg_match('/\A[a-f0-9]{32,64}\z/D',$key))throw new DomainException('A valid request key is required.');return hash('sha256',"$actor|$action|$key");}
require_once __DIR__.'/job_report_activity.php';
require_once __DIR__.'/job_report_workflow.php';
