<?php
require_once __DIR__.'/audit_report.php';
/** Authorized aggregate reporting; no business mutations and no raw private metadata. */
function anActor(mysqli $db): array {
    if(!isLoggedIn())throw new DomainException('Sign in to open System Analytics.',401);
    $u=anQuery($db,'SELECT id,full_name,username,role,status,is_active,is_verified,session_version FROM users WHERE id=?',[(int)$_SESSION['user_id']])->get_result()->fetch_assoc();
    if(!$u||!roleHasCapability($u['role'],'analytics.read')||$u['role']!==($_SESSION['role']??'')||!$u['is_active']||!$u['is_verified']||$u['status']!=='approved'||(isset($_SESSION['session_version'])&&(int)$u['session_version']!==(int)$_SESSION['session_version']))throw new DomainException('Access denied.',403);
    return ['id'=>(int)$u['id'],'name'=>$u['full_name']?:$u['username'],'role'=>$u['role']];
}
function anSections(array $actor): array {
    $catalog=['overview'=>['Overview',null],'financial'=>['Financial','billing.manage'],'units'=>['Units & Occupancy','units.manage'],'parking'=>['Parking & Visitors','parking.review'],'maintenance'=>['Maintenance','maintenance.review'],'amenities'=>['Amenities','bookings.review'],'violations'=>['Violations','violations.review'],'staff'=>['Staff Activities','jobs.review'],'system'=>['System Activity','audit.read'],'health'=>['Data Quality','audit.read']];
    return array_filter($catalog,static fn($section)=>$section[1]===null||roleHasCapability($actor['role'],$section[1]));
}
function anQuery(mysqli $db,string $sql,array $params=[]): mysqli_stmt {return auditReportQuery($db,$sql,$params);}
function anRow(mysqli $db,string $sql,array $params=[]): array {return anQuery($db,$sql,$params)->get_result()->fetch_assoc();}
function anNumber(mysqli $db,string $sql,array $params=[]): int {return (int)array_values(anRow($db,$sql,$params))[0];}
function anPeriod(array $input,?DateTimeImmutable $clock=null): array {
    $tz=new DateTimeZone('Asia/Manila');$now=($clock??new DateTimeImmutable('now',$tz))->setTimezone($tz);$today=$now->setTime(0,0);$preset=$input['preset']??'month';$group=$input['group']??'daily';
    if(!is_string($preset)||!in_array($preset,['today','week','month','year','custom'],true)||!is_string($group)||!in_array($group,['daily','monthly','yearly'],true))throw new DomainException('Choose a valid reporting period and trend grouping.');
    if($preset==='custom'){$start=$input['start']??'';$end=$input['end']??'';if(!is_string($start)||!is_string($end)||!workflowDate($start)||!workflowDate($end)||$start>$end||$end>$today->format('Y-m-d')||$start<'2000-01-01')throw new DomainException('Choose a valid range from 2000 through today, with Start Date before or on End Date.');$from=new DateTimeImmutable($start,$tz);$fullEnd=(new DateTimeImmutable($end,$tz))->modify('+1 day');$prevFullEnd=$from;$previous=$from->modify('-'.(int)$from->diff($fullEnd)->days.' days');$filename=$start.'_to_'.$end;}
    else{[$from,$fullEnd,$previous]=match($preset){'today'=>[$today,$today->modify('+1 day'),$today->modify('-1 day')],'week'=>[$today->modify('-'.((int)$today->format('N')-1).' days'),$today->modify('-'.((int)$today->format('N')-1).' days')->modify('+1 week'),$today->modify('-'.((int)$today->format('N')-1).' days')->modify('-1 week')],'month'=>[$today->modify('first day of this month'),$today->modify('first day of next month'),$today->modify('first day of last month')],'year'=>[$today->setDate((int)$today->format('Y'),1,1),$today->setDate((int)$today->format('Y')+1,1,1),$today->setDate((int)$today->format('Y')-1,1,1)]};$prevFullEnd=$from;$filename=$preset==='month'?$from->format('Y-m'):($preset==='year'?$from->format('Y'):$from->format('Y-m-d').'_to_'.$fullEnd->modify('-1 day')->format('Y-m-d'));}
    $until=$now<$fullEnd?$now:$fullEnd;$partial=$until<$fullEnd;$elapsed=(float)$until->format('U.u')-(float)$from->format('U.u');$prevEnd=$partial?$previous->modify('+'.(int)floor($elapsed).' seconds'):$prevFullEnd;if($prevEnd>$prevFullEnd)$prevEnd=$prevFullEnd;
    $bucketEnd=$until>$from?$until->modify('-1 microsecond'):$from;$days=(int)$from->diff($bucketEnd)->days+1;$buckets=$group==='daily'?$days:($group==='monthly'?((int)$bucketEnd->format('Y')-(int)$from->format('Y'))*12+(int)$bucketEnd->format('n')-(int)$from->format('n')+1:(int)$bucketEnd->format('Y')-(int)$from->format('Y')+1);if($buckets>420)throw new DomainException('This range exceeds 420 trend points. Choose Monthly or Yearly grouping, or narrow the dates.');
    $local=static fn($d)=>$d->format('Y-m-d H:i:s.u');$utc=static fn($d)=>$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    return ['preset'=>$preset,'group'=>$group,'start'=>$from->format('Y-m-d'),'end'=>$fullEnd->modify('-1 day')->format('Y-m-d'),'from'=>$local($from),'until'=>$local($until),'utc_from'=>$utc($from),'utc_until'=>$utc($until),'previous_from'=>$local($previous),'previous_until'=>$local($prevEnd),'previous_utc_from'=>$utc($previous),'previous_utc_until'=>$utc($prevEnd),'partial'=>$partial,'label'=>$from->format('M j, Y').' to '.$fullEnd->modify('-1 day')->format('M j, Y'),'comparison_label'=>($partial?'Matched elapsed period: ':'Previous full period: ').$previous->format('M j, Y H:i').' to '.$prevEnd->format('M j, Y H:i'),'through'=>$until->format('Y-m-d H:i:s'),'filename'=>$filename,'timezone'=>'Asia/Manila'];
}
function anInput(array $input): array {
    $f=['period'=>anPeriod($input)];foreach(['staff_q'=>120,'staff_role'=>30]as$key=>$max){$value=$input[$key]??'';if(!is_string($value)||mb_strlen($value)>$max||preg_match('/[\x00-\x1f]/',$value))throw new DomainException('Invalid staff lookup.');$f[$key]=trim($value);}
    if($f['staff_role']!==''&&!in_array($f['staff_role'],JR_ROLES,true))throw new DomainException('Choose a valid staff role.');return $f;
}
function anRatio(int|float $a,int|float $b): ?float {return $b>0?round($a/$b*100,2):null;}
function anMetric(string $key,string $label,int|float|null $value,string $unit='count',string $context='Selected period',string $definition='',?string $url=null,int|float|null $previous=null): array {
    return compact('key','label','value','unit','context','definition','url','previous')+['change'=>$value!==null&&$previous!==null?$value-$previous:null,'percent_change'=>$value!==null&&$previous!==null&&$previous>0?round(($value-$previous)/$previous*100,2):null];
}
function anSection(string $key,array $metrics=[],array $charts=[],array $notes=[]): array {return ['key'=>$key,'available'=>true,'metrics'=>$metrics,'charts'=>$charts,'notes'=>$notes];}
function anChart(string $id,string $title,array $rows,string $type='bar',string $unit='count',?string $url=null): array {
    if(count($rows)>420)throw new DomainException('Too many chart categories to report completely. Narrow the filters.');
    return compact('id','title','type','unit','url')+['rows'=>array_map(static fn($r)=>['label'=>(string)$r['label'],'value'=>(float)$r['value']]+(isset($r['url'])?['url'=>$r['url']]:[]),$rows)];
}
function anGroup(mysqli $db,string $table,string $label,string $where,array $params=[],string $value='COUNT(*)',?string $url=null): array {
    $rows=anQuery($db,"SELECT COALESCE($label,'Not recorded') label,$value value FROM $table WHERE $where GROUP BY $label ORDER BY value DESC,label LIMIT 421",$params)->get_result()->fetch_all(MYSQLI_ASSOC);if(count($rows)>420)throw new DomainException('More than 420 categories matched. Narrow the reporting period.');return $rows;
}
function anTrend(mysqli $db,string $table,string $column,string $where,array $params,array $p,string $value='COUNT(*)',bool $utc=false): array {
    $format=['daily'=>'%Y-%m-%d','monthly'=>'%Y-%m','yearly'=>'%Y'][$p['group']];$display=$utc?"CONVERT_TZ($column,'+00:00','+08:00')":$column;
    $rows=anQuery($db,"SELECT DATE_FORMAT($display,'$format') label,$value value FROM $table WHERE $where GROUP BY label ORDER BY label LIMIT 421",$params)->get_result()->fetch_all(MYSQLI_ASSOC);if(count($rows)>420)throw new DomainException('Too many trend points. Narrow the reporting period.');$map=array_column($rows,'value','label');$date=new DateTimeImmutable(substr($p['from'],0,10),new DateTimeZone('Asia/Manila'));$end=new DateTimeImmutable($p['until'],new DateTimeZone('Asia/Manila'));if($p['group']==='monthly')$date=$date->modify('first day of this month');if($p['group']==='yearly')$date=$date->setDate((int)$date->format('Y'),1,1);$phpFormat=['daily'=>'Y-m-d','monthly'=>'Y-m','yearly'=>'Y'][$p['group']];$step=['daily'=>'+1 day','monthly'=>'+1 month','yearly'=>'+1 year'][$p['group']];$result=[];for(;$date<$end;$date=$date->modify($step)){$label=$date->format($phpFormat);$result[]=['label'=>$label,'value'=>(float)($map[$label]??0)];}return $result;
}
function anBounds(array $p,bool $utc=false,bool $previous=false): array {return $previous?[$p[$utc?'previous_utc_from':'previous_from'],$p[$utc?'previous_utc_until':'previous_until']]:[$p[$utc?'utc_from':'from'],$p[$utc?'utc_until':'until']];}
function anLink(string $route,array $query=[]): string {return buildUrl($route.($query?'?'.http_build_query($query):''));}
require_once __DIR__.'/analytics_operations.php';
require_once __DIR__.'/analytics_financial.php';
require_once __DIR__.'/analytics_management.php';
function anCapture(mysqli $db,array $input,?string $only=null): array {
    $actor=anActor($db);$f=anInput($input);$catalog=anSections($actor);if($only!==null&&!isset($catalog[$only]))throw new DomainException('This analytics scope is not authorized.',403);
    $requested=$only&&$only!=='overview'?[$only]:array_keys($catalog);$sections=[];$p=$f['period'];auditReportBeginSnapshot($db);
    try{foreach($requested as$key){if($key==='overview')continue;try{$sections[$key]=match($key){'financial'=>anFinancial($db,$p),'units'=>anUnits($db,$p),'parking'=>anParking($db,$p),'maintenance'=>anMaintenance($db,$p),'amenities'=>anAmenities($db,$p),'violations'=>anViolations($db,$p),'staff'=>anStaff($db,$p,$f),'system'=>anSystem($db,$p),'health'=>anHealth($db,$p)};}catch(Throwable $e){error_log('Analytics section '.$key.': '.get_class($e));$sections[$key]=['key'=>$key,'available'=>false,'metrics'=>[],'charts'=>[],'notes'=>[$e instanceof DomainException?$e->getMessage():$catalog[$key][0].' analytics are temporarily unavailable. No values were substituted.']];}}
        if(in_array('overview',$requested,true)){try{$overview=anOverview($db,$p,$sections);}catch(Throwable $e){error_log('Analytics overview: '.get_class($e));$overview=['key'=>'overview','available'=>false,'metrics'=>[],'charts'=>[],'notes'=>['Overview analytics are temporarily unavailable. Other available sections remain usable.']];}$sections=['overview'=>$overview]+$sections;}if(isset($sections['health'])&&$sections['health']['available'])$sections['health']['metrics'][]=anMetric('retrieval_failures','Section retrieval failures',count(array_filter($sections,static fn($s)=>!$s['available'])),'count','This retrieval only');$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    return ['actor'=>$actor,'period'=>$p,'sections'=>$sections,'catalog'=>array_map(static fn($v)=>$v[0],$catalog),'insights'=>anInsights($p,$sections),'refreshed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),'filters'=>['staff_q'=>$f['staff_q'],'staff_role'=>$f['staff_role']],'scope'=>$only??'all'];
}
