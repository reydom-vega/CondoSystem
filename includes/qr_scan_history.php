<?php
/** Central, append-only scan events. New timestamps are UTC; reports use Manila. */
function ensureQrScanHistorySchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    $db->query("CREATE TABLE IF NOT EXISTS qr_scan_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,username VARCHAR(100) NOT NULL,scanned_content TEXT NOT NULL,content_type VARCHAR(20) NOT NULL DEFAULT 'text',scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),INDEX(scanned_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns=['qr_type'=>"VARCHAR(20) NOT NULL DEFAULT 'unknown'",'reference_id'=>'INT DEFAULT NULL','subject_name'=>"VARCHAR(120) NOT NULL DEFAULT ''",'requester_name'=>"VARCHAR(120) NOT NULL DEFAULT ''",'unit_number'=>"VARCHAR(30) NOT NULL DEFAULT ''",'pass_number'=>"VARCHAR(40) NOT NULL DEFAULT ''",'scanner_full_name'=>"VARCHAR(100) NOT NULL DEFAULT ''",'scanner_role'=>"VARCHAR(20) NOT NULL DEFAULT 'unknown'",'scan_action'=>"VARCHAR(30) NOT NULL DEFAULT 'verification'",'verification_result'=>"VARCHAR(20) NOT NULL DEFAULT 'invalid'",'remarks'=>"VARCHAR(500) NOT NULL DEFAULT ''",'event_time_utc'=>'DATETIME DEFAULT NULL','created_at_utc'=>'DATETIME DEFAULT NULL','content_hash'=>'CHAR(64) DEFAULT NULL','request_key'=>'CHAR(64) DEFAULT NULL','verification_json'=>'TEXT DEFAULT NULL'];
    foreach($columns as $name=>$definition) if(!$db->query("SHOW COLUMNS FROM qr_scan_logs LIKE '$name'")->num_rows) $db->query("ALTER TABLE qr_scan_logs ADD $name $definition");
    foreach(['ix_qr_time'=>'event_time_utc,id','ix_qr_filters'=>'qr_type,verification_result,event_time_utc','ix_qr_actor'=>'user_id,content_hash,event_time_utc'] as $name=>$fields) if(!$db->query("SHOW INDEX FROM qr_scan_logs WHERE Key_name='$name'")->num_rows) $db->query("ALTER TABLE qr_scan_logs ADD INDEX $name ($fields)");
    if(!$db->query("SHOW INDEX FROM qr_scan_logs WHERE Key_name='uq_qr_request'")->num_rows) $db->query('ALTER TABLE qr_scan_logs ADD UNIQUE INDEX uq_qr_request (request_key)');
    // Preserve IDs and timestamps, but retire old raw URLs/tokens. Legacy outcomes
    // were never recorded and must not be invented from today's pass state.
    $db->query("UPDATE qr_scan_logs SET scanner_full_name=username,remarks='Legacy scan: verification outcome was not recorded; raw QR content redacted.',verification_result='unknown',event_time_utc=CONVERT_TZ(scanned_at,@@session.time_zone,'+00:00'),created_at_utc=CONVERT_TZ(scanned_at,@@session.time_zone,'+00:00'),scanned_content='[Legacy QR content redacted]' WHERE event_time_utc IS NULL");
    return true;
}

function qrScannerIdentity(mysqli $db): array {
    $id=(int)($_SESSION['user_id'] ?? 0);
    $stmt=$db->prepare('SELECT id,full_name,role,is_active,is_verified FROM users WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
    if(!$row || !$row['is_active'] || !$row['is_verified'] || $row['role']!==($_SESSION['role'] ?? '') || !roleHasCapability($row['role'],'security.gate')) throw new InvalidArgumentException('Access denied.');
    return ['id'=>$id,'name'=>$row['full_name'],'role'=>$row['role']];
}
function qrHistoryAllowed(): bool { return canAccess('security.gate'); }
function qrExportAllowed(): bool { return canAccess('scan_history.export'); }

function qrAppendEvent(mysqli $db,array $scanner,array $data,string $action='verification',?string $key=null,?string $contentHash=null,?array $verification=null): int {
    $safe='QR '.str_replace('_',' ',$action); $type=$data['qr_type'] ?? 'unknown'; $ref=$data['reference_id'] ?? null;
    $subject=$data['subject_name'] ?? ''; $requester=$data['requester_name'] ?? ''; $unit=$data['unit_number'] ?? ''; $pass=$data['pass_number'] ?? '';
    $outcome=$data['verification_result'] ?? 'invalid'; $remarks=mb_substr($data['remarks'] ?? '',0,500);
    // Do not persist verification URLs. They contain bearer tokens.
    $json=$verification ? json_encode(array_diff_key($verification,['pass_url'=>true]),JSON_THROW_ON_ERROR) : null;
    $stmt=$db->prepare("INSERT INTO qr_scan_logs (user_id,username,scanned_content,content_type,qr_type,reference_id,subject_name,requester_name,unit_number,pass_number,scanner_full_name,scanner_role,scan_action,verification_result,remarks,event_time_utc,created_at_utc,content_hash,request_key,verification_json) VALUES (?,?,?,'event',?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?,?)");
    $stmt->bind_param('isssissssssssssss',$scanner['id'],$scanner['name'],$safe,$type,$ref,$subject,$requester,$unit,$pass,$scanner['name'],$scanner['role'],$action,$outcome,$remarks,$contentHash,$key,$json);
    $stmt->execute(); return (int)$db->insert_id;
}

function qrActionEvent(mysqli $db,array $row,string $action,string $remarks=''): void {
    $scanner=qrScannerIdentity($db); $property=!empty($row['gate_data']);
    $data=['qr_type'=>$property?'property':'visitor','reference_id'=>(int)$row['id'],'subject_name'=>$property?$row['full_name']:($row['visitor_name'] ?? ''),'requester_name'=>$row['full_name'] ?? '', 'unit_number'=>$property?gateData($row)['unit_number']:($row['unit_number'] ?? ''),'pass_number'=>$property?gateNumber((int)$row['id']):'VIS-'.str_pad((string)$row['id'],8,'0',STR_PAD_LEFT),'verification_result'=>'valid','remarks'=>$remarks];
    $key=hash('sha256','action:'.$data['qr_type'].':'.$row['id'].':'.$action);
    qrAppendEvent($db,$scanner,$data,$action,$key);
}

function recordQrVerification(mysqli $db,string $content,string $requestId): array {
    $scanner=qrScannerIdentity($db);
    if(strlen($content)>4096 || trim($content)==='') throw new InvalidArgumentException('Scan or paste a QR verification URL.');
    if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$requestId)) throw new InvalidArgumentException('A valid scan request ID is required.');
    $contentHash=hash('sha256',$content); $key=hash('sha256',$scanner['id'].':'.strtolower($requestId));
    $lockName='qr:'.substr(hash('sha256',$scanner['id'].':'.$contentHash),0,60);
    $stmt=$db->prepare('SELECT GET_LOCK(?,5) AS acquired'); $stmt->bind_param('s',$lockName); $stmt->execute();
    if((int)$stmt->get_result()->fetch_assoc()['acquired']!==1) throw new RuntimeException('Scan processing is busy. Please try again.');
    try {
        $stmt=$db->prepare('SELECT * FROM qr_scan_logs WHERE request_key=?'); $stmt->bind_param('s',$key); $stmt->execute(); $prior=$stmt->get_result()->fetch_assoc(); $sameRequest=(bool)$prior;
        if($prior && !hash_equals($prior['content_hash'],$contentHash)) throw new InvalidArgumentException('This scan request ID was already used.');
        if(!$prior) {
            $stmt=$db->prepare("SELECT * FROM qr_scan_logs WHERE user_id=? AND content_hash=? AND scan_action='verification' AND event_time_utc>=UTC_TIMESTAMP()-INTERVAL 5 SECOND ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('is',$scanner['id'],$contentHash); $stmt->execute(); $prior=$stmt->get_result()->fetch_assoc();
        }
        $verification=verifyAccessScan($db,$content);
        if($prior && ($sameRequest || $prior['verification_result']===($verification['verification_result'] ?? 'invalid'))) {
            return ['success'=>true,'id'=>(int)$prior['id'],'duplicate'=>true,'verification'=>$verification];
        }
        $db->begin_transaction();
        try {
            $id=qrAppendEvent($db,$scanner,$verification,'verification',$key,$contentHash,$verification);
            if(($verification['qr_type'] ?? '')==='property' && isset($verification['reference_id'])) gateHistory($db,(int)$verification['reference_id'],$verification['valid']?'verified_valid':'verified_invalid','QR scan: '.$verification['verification_result']);
            $db->commit();
        } catch(Throwable $e) { $db->rollback(); throw $e; }
        return ['success'=>true,'id'=>$id,'duplicate'=>false,'verification'=>$verification];
    } finally { $stmt=$db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s',$lockName); $stmt->execute(); }
}

function qrDateBounds(array $input): array {
    $zone=new DateTimeZone('Asia/Manila'); $utc=new DateTimeZone('UTC'); $today=new DateTimeImmutable('today',$zone);
    $preset=$input['period'] ?? 'all'; $start=''; $end='';
    if (!is_string($preset)) throw new InvalidArgumentException('Choose a valid date filter.');
    switch($preset) {
        case 'all': break;
        case 'today': $start=$end=$today->format('Y-m-d'); break;
        case 'yesterday': $start=$end=$today->modify('-1 day')->format('Y-m-d'); break;
        case 'last7': $start=$today->modify('-6 days')->format('Y-m-d'); $end=$today->format('Y-m-d'); break;
        case 'month': $start=$today->format('Y-m-01'); $end=$today->format('Y-m-t'); break;
        case 'single': $start=$end=$input['date'] ?? ''; break;
        case 'range': $start=$input['start_date'] ?? ''; $end=$input['end_date'] ?? ''; break;
        default: throw new InvalidArgumentException('Choose a valid date filter.');
    }
    if($preset==='all') return ['start'=>'','end'=>'','from'=>null,'until'=>null];
    if(!is_string($start) || !is_string($end) || !workflowDate($start) || !workflowDate($end) || $end<$start) throw new InvalidArgumentException('Choose valid dates with the end on or after the start.');
    $from=new DateTimeImmutable($start.' 00:00:00',$zone); $until=(new DateTimeImmutable($end.' 00:00:00',$zone))->modify('+1 day');
    return ['start'=>$start,'end'=>$end,'from'=>$from->setTimezone($utc)->format('Y-m-d H:i:s'),'until'=>$until->setTimezone($utc)->format('Y-m-d H:i:s')];
}
function qrHistoryFilters(array $input): array {
    $result=[];
    foreach(['q'=>120,'qr_type'=>20,'status'=>20,'role'=>20,'sort'=>10] as $name=>$max) {
        $value=$input[$name] ?? ''; if(!is_string($value) || mb_strlen($value)>$max) throw new InvalidArgumentException('Invalid history filter.'); $result[$name]=trim($value);
    }
    foreach(['qr_type'=>['','visitor','property','parking','permit','unknown'],'status'=>['','valid','invalid','expired','cancelled','already_used','unknown'],'role'=>['','security','admin','superadmin'],'sort'=>['','newest','oldest']] as $name=>$allowed) if(!in_array($result[$name],$allowed,true)) throw new InvalidArgumentException('Invalid history filter.');
    $result['dates']=qrDateBounds($input);
    $result['page']=filter_var($input['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    if($result['page']===false) throw new InvalidArgumentException('Invalid page number.');
    return $result;
}
function qrWhere(array $filters): array {
    $where=[]; $params=[]; $types='';
    foreach(['qr_type'=>'qr_type','status'=>'verification_result','role'=>'scanner_role'] as $key=>$column) if($filters[$key]!=='') { $where[]="$column=?"; $params[]=$filters[$key]; $types.='s'; }
    if($filters['dates']['from']) { $where[]='event_time_utc>=? AND event_time_utc<?'; $params[]=$filters['dates']['from']; $params[]=$filters['dates']['until']; $types.='ss'; }
    if($filters['q']!=='') {
        $needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['q']).'%';
        $search=[]; foreach(['subject_name','requester_name','unit_number','pass_number','scanner_full_name',"CONCAT('QR-',id)",'CAST(id AS CHAR)'] as $column) { $search[]="$column LIKE ? ESCAPE '!'"; $params[]=$needle; $types.='s'; }
        $where[]='('.implode(' OR ',$search).')';
    }
    return [$where?' WHERE '.implode(' AND ',$where):'',$types,$params];
}
function qrQuery(mysqli $db,string $sql,string $types='',array $params=[]): mysqli_result|bool {
    $stmt=$db->prepare($sql); if($types!=='') $stmt->bind_param($types,...$params); $stmt->execute(); return $stmt->get_result();
}
function qrSummary(mysqli $db,array $filters): array {
    [$where,$types,$params]=qrWhere($filters);
    $row=qrQuery($db,"SELECT COUNT(*) AS total,COALESCE(SUM(verification_result='valid'),0) AS valid,COALESCE(SUM(verification_result IN ('invalid','already_used')),0) AS invalid,COALESCE(SUM(verification_result IN ('expired','cancelled')),0) AS expired_cancelled,COALESCE(SUM(qr_type='visitor'),0) AS visitor,COALESCE(SUM(qr_type='property'),0) AS property FROM qr_scan_logs".$where,$types,$params)->fetch_assoc();
    return array_map('intval',$row);
}
function qrPublicRecord(array $row): array {
    $keys=['id','qr_type','reference_id','subject_name','requester_name','unit_number','pass_number','scanner_full_name','scanner_role','scan_action','verification_result','remarks','event_time_utc','created_at_utc'];
    $result=array_intersect_key($row,array_flip($keys));
    $result['scan_reference']='QR-'.$row['id'];
    $result['scanner_user_id']=(int)$row['user_id'];
    $result['local_time']=(new DateTimeImmutable($row['event_time_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    return $result;
}
function qrHistoryPage(mysqli $db,array $filters): array {
    $summary=qrSummary($db,$filters); $pages=max(1,(int)ceil($summary['total']/25)); $page=min($filters['page'],$pages); $offset=($page-1)*25;
    [$where,$types,$params]=qrWhere($filters); $order=$filters['sort']==='oldest'?'ASC':'DESC';
    $rows=qrQuery($db,"SELECT * FROM qr_scan_logs$where ORDER BY event_time_utc $order,id $order LIMIT 25 OFFSET $offset",$types,$params)->fetch_all(MYSQLI_ASSOC);
    return ['success'=>true,'records'=>array_map('qrPublicRecord',$rows),'summary'=>$summary,'page'=>$page,'pages'=>$pages,'total'=>$summary['total']];
}
