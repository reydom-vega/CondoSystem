<?php
/** Read-only authorized projection of the existing central ledger. Never select raw details. */
function auditReportActor(mysqli $db): array {
    if (!isLoggedIn()) throw new DomainException('Sign in to access the Audit Log.',401);
    $s=$db->prepare('SELECT id,full_name,username,role,is_active,is_verified,status,session_version FROM users WHERE id=?');$id=(int)$_SESSION['user_id'];$s->bind_param('i',$id);$s->execute();$u=$s->get_result()->fetch_assoc();
    if (!$u || !roleHasCapability($u['role'],'audit.read') || $u['role']!==($_SESSION['role']??'') || !$u['is_active'] || !$u['is_verified'] || $u['status']!=='approved' || (isset($_SESSION['session_version']) && (int)$u['session_version']!==(int)$_SESSION['session_version'])) throw new DomainException('Access denied.',403);
    return ['id'=>$id,'name'=>$u['full_name']?:$u['username'],'role'=>$u['role']];
}
function auditReportModules(): array {
    return ['authentication'=>'Authentication','staff'=>'Staff Management','user'=>'Accounts','unit'=>'Unit Management','visitor'=>'Visitor Management','resident_service_request'=>'Visitor / Permit Requests','visitor_identity'=>'Visitor Registration','qr_scan'=>'Visitor QR Scanner','visitor_parking'=>'Parking Management','parking_request'=>'Parking Management','parking_extension'=>'Parking Management','parking_slot'=>'Parking Management','parking_sticker'=>'Parking Management','parking_inventory'=>'Parking Management','parking_policy'=>'Parking Management','parking_rate'=>'Parking Management','vehicle'=>'Registered Vehicles','maintenance_request'=>'Maintenance Requests','payment'=>'Billing & Payments','booking'=>'Amenity Booking','violation'=>'Violations','announcement'=>'Announcements','message'=>'Messages','community_report'=>'Community Reports','job_report'=>'Staff Job Reports','audit_export'=>'Audit Log','analytics_export'=>'Analytics'];
}
function auditReportPeriod(array $input): array {
    $tz=new DateTimeZone('Asia/Manila');$today=new DateTimeImmutable('today',$tz);$mode=$input['mode']??'daily';
    if(!is_string($mode)||!in_array($mode,['daily','monthly','range'],true))throw new DomainException('Choose Daily, Monthly or Custom Range.');
    $valid=static function($value):string {if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!workflowDate($value))throw new DomainException('Choose a valid reporting date.');return $value;};
    if($mode==='daily'){$first=$last=$valid($input['day']??$today->format('Y-m-d'));$label=(new DateTimeImmutable($first,$tz))->format('F j, Y');$filename=$first;}
    elseif($mode==='monthly'){$month=$input['month']??$today->format('Y-m');if(!is_string($month)||!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D',$month))throw new DomainException('Choose a valid month.');$first=$valid($month.'-01');$date=new DateTimeImmutable($first,$tz);$last=$date->format('Y-m-t');$label=$date->format('F Y');$filename=$month;}
    else{$first=$valid($input['start']??$today->format('Y-m-d'));$last=$valid($input['end']??$first);if($first>$last)throw new DomainException('Start Date must not be later than End Date.');$label=$first.' to '.$last;$filename=$first.'_to_'.$last;}
    $from=new DateTimeImmutable($first.' 00:00:00',$tz);$until=(new DateTimeImmutable($last.' 00:00:00',$tz))->modify('+1 day');
    return ['mode'=>$mode,'label'=>$label,'filename'=>$filename,'start'=>$first,'end'=>$last,'local_start'=>$from->format('Y-m-d H:i:s'),'local_end'=>$until->format('Y-m-d H:i:s'),'utc_start'=>$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'utc_end'=>$until->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
}
function auditReportFilters(array $input): array {
    $f=['period'=>auditReportPeriod($input)];
    foreach(['q'=>120,'role'=>30,'module'=>60,'action'=>100,'status'=>20,'sort'=>10]as$key=>$limit){$v=$input[$key]??'';if(!is_string($v)||mb_strlen($v)>$limit||preg_match('/[\x00-\x1f]/',$v))throw new DomainException('Invalid audit filter.');$f[$key]=trim($v);}
    if(!in_array($f['sort'],['','newest','oldest'],true)||!in_array($f['status'],['','success','failed','refused','unknown'],true))throw new DomainException('Choose a valid sort or status.');
    if($f['role']!==''&&!in_array($f['role'],array_merge(array_keys(getUserRoles()),['tenant','system','unknown']),true))throw new DomainException('Choose a valid role.');
    if($f['module']!==''&&!in_array($f['module'],array_merge(array_values(auditReportModules()),['Other']),true))throw new DomainException('Choose a valid module.');
    $f['page']=filter_var($input['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);if($f['page']===false)throw new DomainException('Invalid page number.');return $f;
}
function auditReportQuery(mysqli $db,string $sql,array $params=[]): mysqli_stmt {
    $s=$db->prepare($sql);if($params){$types='';foreach($params as$v)$types.=is_int($v)?'i':'s';$s->bind_param($types,...$params);}$s->execute();return $s;
}
function auditReportSource(mysqli $db): string {
    $cases=[];$allowed=[];foreach(auditReportModules()as$entity=>$module){$cases[]="WHEN '".$db->real_escape_string($entity)."' THEN '".$db->real_escape_string($module)."'";if($entity!=='community_report')$allowed[]="'".$db->real_escape_string($entity)."'";}
    return "SELECT a.id,a.created_at,a.admin_id,COALESCE(NULLIF(a.actor_name_snapshot,''),NULLIF(a.admin_name,''),IF(a.admin_id IS NULL,'System',CONCAT('Historical user #',a.admin_id))) actor,
      COALESCE(NULLIF(a.actor_identifier,''),NULLIF(u.staff_id,''),IF(a.admin_id IS NULL,'System',CONCAT('USER-',a.admin_id))) identifier,
      COALESCE(NULLIF(a.actor_role_snapshot,''),NULLIF(a.admin_role,''),IF(a.admin_id IS NULL,'system','unknown')) role,
      CASE a.entity_type ".implode(' ',$cases)." ELSE 'Other' END module,
      IF(a.entity_type IN (".implode(',',$allowed)."),a.action,'restricted_activity') action,
      COALESCE(a.outcome,'unknown') status,
      IF(a.entity_type IN (".implode(',',$allowed).") AND a.entity_id IS NOT NULL,CONCAT(UPPER(REPLACE(a.entity_type,'_','-')),'-',a.entity_id),'Withheld / not recorded') reference,
      IF(a.actor_name_snapshot IS NULL,1,0) legacy_actor
      FROM audit_logs a LEFT JOIN users u ON u.id=a.admin_id";
}
function auditReportWhere(array $f): array {
    $where=' WHERE r.created_at>=? AND r.created_at<?';$params=[$f['period']['local_start'],$f['period']['local_end']];
    foreach(['role','module','action','status']as$key)if($f[$key]!==''){$where.=" AND r.$key=?";$params[]=$f[$key];}
    if($f['q']!==''){$needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['q']).'%';$checks=[];foreach(['r.actor','r.identifier','r.role','r.module',"REPLACE(r.action,'_',' ')",'r.action','r.reference',"CONCAT('AUD-',r.id)",'CAST(r.id AS CHAR)',"CONCAT('USER-',r.admin_id)"]as$column){$checks[]="$column LIKE ? ESCAPE '!'";$params[]=$needle;}$where.=' AND ('.implode(' OR ',$checks).')';}return [$where,$params];
}
function auditReportRecord(array $r): array {
    $r['id']=(int)$r['id'];$r['admin_id']=$r['admin_id']===null?null:(int)$r['admin_id'];$r['legacy_actor']=(bool)$r['legacy_actor'];$r['local_time']=$r['created_at'];unset($r['created_at']);
    $r['activity']=ucfirst(str_replace('_',' ',$r['action']));$r['description']=$r['activity'].' in '.$r['module'].'.';
    $r['status_label']=['success'=>'Success','failed'=>'Failed','refused'=>'Refused','unknown'=>'Not recorded'][$r['status']]??'Not recorded';
    return $r;
}
function auditReportBeginSnapshot(mysqli $db): void {
    $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT | MYSQLI_TRANS_START_READ_ONLY);
}
function auditReportList(mysqli $db,array $input): array {
    auditReportActor($db);$f=auditReportFilters($input);[$where,$params]=auditReportWhere($f);$source=auditReportSource($db);
    auditReportBeginSnapshot($db);try{
        $stats=auditReportQuery($db,"SELECT COUNT(*) total,COALESCE(SUM(r.status='success'),0) success,COALESCE(SUM(r.status='failed'),0) failed,COALESCE(SUM(r.status='unknown'),0) unknown,COUNT(DISTINCT IF(r.admin_id IS NOT NULL,CONCAT('ID:',r.admin_id),IF(r.role='system',NULL,CONCAT('NAME:',r.actor)))) users FROM ($source) r$where",$params)->get_result()->fetch_assoc();$stats=array_map('intval',$stats);
        $pages=max(1,(int)ceil($stats['total']/25));$page=min($f['page'],$pages);$offset=($page-1)*25;$direction=$f['sort']==='oldest'?'ASC':'DESC';
        $rows=auditReportQuery($db,"SELECT r.* FROM ($source) r$where ORDER BY r.created_at $direction,r.id $direction LIMIT 25 OFFSET $offset",$params)->get_result()->fetch_all(MYSQLI_ASSOC);
        $options=auditReportQuery($db,"SELECT DISTINCT r.action FROM ($source) r WHERE r.created_at>=? AND r.created_at<? ORDER BY r.action",[$f['period']['local_start'],$f['period']['local_end']])->get_result()->fetch_all(MYSQLI_ASSOC);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    return ['records'=>array_map('auditReportRecord',$rows),'summary'=>$stats,'page'=>$page,'pages'=>$pages,'period'=>$f['period'],'actions'=>array_column($options,'action'),'export_limit'=>auditReportLimit()];
}
function auditReportDetail(mysqli $db,int $id): array {
    auditReportActor($db);$source=auditReportSource($db);$row=auditReportQuery($db,"SELECT r.* FROM ($source) r WHERE r.id=?",[$id])->get_result()->fetch_assoc();if(!$row)throw new DomainException('Audit record not found.',404);
    $record=auditReportRecord($row);$record['privacy_note']='Original free-text metadata is withheld because it may contain private information or credentials. Before/after values were not recorded as authorized structured changes.';return $record;
}
function auditReportLimit(): int {
    $configured=max(1,min(2000,(int)appSetting('CONDO_AUDIT_EXPORT_MAX_RECORDS','500')));
    // Dompdf retains table layout in memory. Bound the snapshot before rendering.
    $setting=trim((string)ini_get('memory_limit'));if($setting==='-1')return $configured;
    $unit=strtolower(substr($setting,-1));$bytes=(int)$setting * match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
    $available=max(0,$bytes-memory_get_usage(true)-67108864);
    return max(1,min($configured,(int)floor($available/786432)));
}
function auditReportCapture(mysqli $db,array $input): array {
    $actor=auditReportActor($db);$f=auditReportFilters($input);[$where,$params]=auditReportWhere($f);$source=auditReportSource($db);$limit=auditReportLimit();$direction=$f['sort']==='oldest'?'ASC':'DESC';
    auditReportBeginSnapshot($db);try{
        $total=(int)auditReportQuery($db,"SELECT COUNT(*) n FROM ($source) r$where",$params)->get_result()->fetch_assoc()['n'];if($total>$limit)throw new DomainException('This report exceeds the '.$limit.' record export limit. Select a narrower period or filters. No records were omitted.');
        $rows=[];$offset=0;while($offset<$total){$chunk=auditReportQuery($db,"SELECT r.* FROM ($source) r$where ORDER BY r.created_at $direction,r.id $direction LIMIT 250 OFFSET $offset",$params)->get_result()->fetch_all(MYSQLI_ASSOC);foreach($chunk as$r)$rows[]=auditReportRecord($r);$offset+=count($chunk);if(!$chunk)throw new RuntimeException('Audit snapshot could not be completed.');}$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    $filters=[];foreach(['q'=>'Search','role'=>'Role','module'=>'Module','action'=>'Action','status'=>'Status']as$key=>$label)if($f[$key]!=='')$filters[]=$label.': '.$f[$key];$filters[]='Order: '.($direction==='ASC'?'Oldest first':'Newest first');
    return ['actor'=>$actor,'period'=>$f['period'],'filters'=>implode('; ',$filters),'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),'total'=>$total,'records'=>$rows];
}
