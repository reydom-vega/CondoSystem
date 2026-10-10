<?php
/** Property permits extend resident_service_requests; visitors and legacy permits retain their workflow. */
const GATE_PURPOSES = ['New Appliance / Furniture', 'Appliance Replacement', 'Personal Property Transfer', 'Repair / Return', 'Other'];
const GATE_ITEM_CATEGORIES = ['Appliance', 'Furniture', 'Equipment', 'Other'];
function gateEscape($value): string { return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8'); }

function ensurePropertyGateSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    foreach (['gate_data'=>'LONGTEXT DEFAULT NULL', 'qr_token_hash'=>'CHAR(64) DEFAULT NULL', 'updated_at'=>'DATETIME DEFAULT NULL'] as $name=>$definition) {
        if (!$db->query("SHOW COLUMNS FROM resident_service_requests LIKE '$name'")->num_rows && !$db->query("ALTER TABLE resident_service_requests ADD $name $definition")) return false;
    }
    $status = $db->query("SHOW COLUMNS FROM resident_service_requests LIKE 'status'")->fetch_assoc();
    if (!str_contains($status['Type'], "'changes_requested'")) {
        if (!$db->query("ALTER TABLE resident_service_requests MODIFY status ENUM('pending','approved','rejected','cancelled','checked_in','checked_out','changes_requested','expired','completed') NOT NULL DEFAULT 'pending'")) return false;
    }
    foreach ([
        "CREATE TABLE IF NOT EXISTS permit_items (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, item_name VARCHAR(120) NOT NULL, category VARCHAR(20) NOT NULL, quantity SMALLINT UNSIGNED NOT NULL, description VARCHAR(300) NOT NULL DEFAULT '', INDEX(request_id), FOREIGN KEY(request_id) REFERENCES resident_service_requests(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS permit_documents (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, stored_name VARCHAR(80) NOT NULL, original_name VARCHAR(150) NOT NULL, mime VARCHAR(30) NOT NULL, INDEX(request_id), FOREIGN KEY(request_id) REFERENCES resident_service_requests(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS permit_history (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, actor_id INT NOT NULL, action VARCHAR(30) NOT NULL, remarks VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(request_id), FOREIGN KEY(request_id) REFERENCES resident_service_requests(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ] as $sql) if (!$db->query($sql)) return false;
    return true;
}

function gateText(array $input, string $key, int $max, bool $required = false): string {
    if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$key).'.');
    $value = trim($input[$key] ?? '');
    if (($required && $value === '') || mb_strlen($value) > $max) throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$key).' (up to '.$max.' characters).');
    return $value;
}

function gateSchedule(string $date, string $start, string $end, bool $future = true): array {
    if (!workflowDate($date) || !preg_match('/^\d{2}:\d{2}$/D',$start) || !preg_match('/^\d{2}:\d{2}$/D',$end) || $start > '23:59' || $end > '23:59' || substr($start,3) > '59' || substr($end,3) > '59' || $start >= $end || ($future && ($date < date('Y-m-d') || "$date $end:00" <= date('Y-m-d H:i:s')))) {
        throw new InvalidArgumentException('Choose a current or future date and an end time after the start time, on the same day.');
    }
    return ['date'=>$date,'start'=>$start,'end'=>$end];
}

function gateInput(array $input): array {
    $direction = gateText($input,'direction',10,true);
    $purpose = gateText($input,'purpose',50,true);
    if (!in_array($direction,['entry','exit'],true) || !in_array($purpose,GATE_PURPOSES,true)) throw new InvalidArgumentException('Choose a valid movement type and purpose.');
    $description = gateText($input,'details',500,$purpose === 'Other');
    $schedule = gateSchedule(gateText($input,'start_date',10,true),gateText($input,'start_time',5,true),gateText($input,'end_time',5,true));
    $rawItems = $input['items'] ?? [];
    if (!is_array($rawItems) || count($rawItems)<1 || count($rawItems)>50) throw new InvalidArgumentException('Add between 1 and 50 items.');
    $items=[];
    foreach ($rawItems as $item) {
        if (!is_array($item)) throw new InvalidArgumentException('Invalid item.');
        $name=gateText($item,'item_name',120,true); $category=gateText($item,'category',20,true);
        $quantity=filter_var($item['quantity'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if (!in_array($category,GATE_ITEM_CATEGORIES,true) || $quantity===false) throw new InvalidArgumentException('Each item needs a category and a positive whole quantity (maximum 65535).');
        $items[]=['item_name'=>$name,'category'=>$category,'quantity'=>$quantity,'description'=>gateText($item,'description',300)];
    }
    $data=['direction'=>$direction,'purpose'=>$purpose,'description'=>$description,'requested'=>$schedule,'authorized'=>null];
    foreach (['transporter'=>120,'transporter_contact'=>30,'vehicle_plate'=>20,'personnel'=>120] as $key=>$max) $data[$key]=gateText($input,$key,$max);
    return [$data,$items];
}

function gateHistory(mysqli $db,int $id,string $action,string $remarks=''): void {
    $actor=(int)($_SESSION['user_id'] ?? 0);
    $stmt=$db->prepare('INSERT INTO permit_history (request_id,actor_id,action,remarks) VALUES (?,?,?,?)');
    $stmt->bind_param('iiss',$id,$actor,$action,$remarks); $stmt->execute();
}

function gateNumber(int $id): string { return 'PGP-'.str_pad((string)$id,8,'0',STR_PAD_LEFT); }
function gateData(array $row): array { return json_decode($row['gate_data'] ?? '{}',true,512,JSON_THROW_ON_ERROR); }
function gateEffectiveStatus(array $row): string {
    if ($row['status']==='approved') {
        $s=gateData($row)['authorized'] ?? null;
        if ($s && $s['date'].' '.$s['end'].':00' < date('Y-m-d H:i:s')) return 'expired';
    }
    return $row['status'];
}
function gateValid(array $row): bool {
    $s=gateData($row)['authorized'] ?? null;
    return $row['status']==='approved' && $s && $s['date']===date('Y-m-d') && date('H:i') >= $s['start'] && date('H:i:s') <= $s['end'].':00';
}
function gateToken(array $row, ?string $signingSignature=null): string {
    // A separate, domain-separated QR token; only its hash is stored in this module.
    return hash_hmac('sha256','property-gate:'.$row['id'].':'.$row['access_token'],$signingSignature ?? parkingPassSignature((int)$row['id']));
}
function gatePassUrl(array $row): string {
    return buildUrl('resident_service_pass.php?id='.(int)$row['id'].'&token='.gateToken($row));
}

function gateRequest(mysqli $db,int $id,bool $lock=false): ?array {
    $stmt=$db->prepare('SELECT r.*,u.full_name,u.unit_number,u.contact_number,u.email,a.full_name AS approver_name FROM resident_service_requests r JOIN users u ON u.id=r.user_id LEFT JOIN users a ON a.id=r.decided_by WHERE r.id=?'.($lock?' FOR UPDATE':''));
    $stmt->bind_param('i',$id); $stmt->execute(); return $stmt->get_result()->fetch_assoc() ?: null;
}
function gateCanView(mysqli $db,array $row): bool {
    if (canReviewPermits() || isSecurity()) return true;
    return (int)$row['user_id']===(int)($_SESSION['user_id'] ?? 0) && residentUserHasPermission($db,(int)$row['user_id'],'resident.permits.request');
}
function gateChildren(mysqli $db,int $id,string $table): array {
    if (!in_array($table,['permit_items','permit_documents','permit_history'],true)) throw new InvalidArgumentException('Invalid details.');
    $sql=$table==='permit_history' ? 'SELECT h.*,u.full_name AS actor_name FROM permit_history h LEFT JOIN users u ON u.id=h.actor_id WHERE h.request_id=? ORDER BY h.id' : "SELECT * FROM $table WHERE request_id=? ORDER BY id";
    $stmt=$db->prepare($sql); $stmt->bind_param('i',$id); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function gateUploadLimit(): int { return max(1,min(20,(int)appSetting('CONDO_PERMIT_UPLOAD_MAX_MB','5')))*1024*1024; }
function gateStoreUploads(array $files): array {
    $saved=[]; $file=$files['documents'] ?? null;
    if (!$file) return [];
    try {
        if (!isset($file['error']) || !is_array($file['error']) || count($file['error'])>5) throw new InvalidArgumentException('Upload at most five documents per submission.');
        foreach ($file['error'] as $index=>$error) {
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            $tmp=$file['tmp_name'][$index] ?? '';
            if ($error!==UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp) || filesize($tmp)<1 || filesize($tmp)>gateUploadLimit()) throw new InvalidArgumentException('Each document must fit the configured upload limit.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp); $ext=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'][$mime] ?? null;
            if (!$ext || ($ext!=='pdf' && !getimagesize($tmp))) throw new InvalidArgumentException('Only valid JPG, PNG and PDF documents are accepted.');
            $dir=dirname(__DIR__).'/private_uploads/permit_documents';
            if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Upload storage unavailable.');
            $name=bin2hex(random_bytes(24)).'.'.$ext;
            if (!move_uploaded_file($tmp,$dir.'/'.$name)) throw new RuntimeException('Could not store document.');
            $saved[]=['stored_name'=>$name,'original_name'=>mb_substr(basename((string)($file['name'][$index] ?? 'Document')),0,150),'mime'=>$mime];
        }
        return $saved;
    } catch (Throwable $e) { gateRemoveUploads($saved); throw $e; }
}
function gateRemoveUploads(array $documents): void {
    foreach ($documents as $doc) if (preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D',$doc['stored_name'])) @unlink(dirname(__DIR__).'/private_uploads/permit_documents/'.$doc['stored_name']);
}

function savePropertyGateRequest(mysqli $db,array $input,array $files=[],int $id=0): int {
    $actor=(int)($_SESSION['user_id'] ?? 0);
    if (!residentUserHasPermission($db,$actor,'resident.permits.request')) throw new InvalidArgumentException('An approved resident or tenant account is required.');
    [$data,$items]=gateInput($input); $documents=gateStoreUploads($files);
    $db->begin_transaction();
    try {
        if ($id) {
            $row=gateRequest($db,$id,true);
            if (!$row || !$row['gate_data'] || (int)$row['user_id']!==$actor || $row['status']!=='changes_requested') throw new InvalidArgumentException('Only your returned property permit can be edited and resubmitted.');
            $data['unit_number']=gateData($row)['unit_number'];
            if (count(gateChildren($db,$id,'permit_documents'))+count($documents)>10) throw new InvalidArgumentException('At most ten supporting documents may be retained on one request.');
        } else {
            $data['unit_number']=residentContext($db,$actor)['unit_number'];
        }
        $json=json_encode($data,JSON_THROW_ON_ERROR); $date=$data['requested']['date']; $details=$data['description'] ?: $data['purpose'];
        if (!$id) {
            $token=bin2hex(random_bytes(32));
            $stmt=$db->prepare("INSERT INTO resident_service_requests (user_id,request_kind,permit_type,details,start_date,end_date,access_token,gate_data,updated_at) VALUES (?,'permit','Property Gate Pass',?,?,?,?,?,NOW())");
            $stmt->bind_param('isssss',$actor,$details,$date,$date,$token,$json); $stmt->execute(); $id=(int)$db->insert_id;
        } else {
            $stmt=$db->prepare("UPDATE resident_service_requests SET details=?,start_date=?,end_date=?,gate_data=?,status='pending',qr_token_hash=NULL,decided_by=NULL,decided_at=NULL,admin_notes=NULL,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('ssssi',$details,$date,$date,$json,$id); $stmt->execute();
            $delete=$db->prepare('DELETE FROM permit_items WHERE request_id=?'); $delete->bind_param('i',$id); $delete->execute();
        }
        $stmt=$db->prepare('INSERT INTO permit_items (request_id,item_name,category,quantity,description) VALUES (?,?,?,?,?)');
        foreach ($items as $item) { $stmt->bind_param('issis',$id,$item['item_name'],$item['category'],$item['quantity'],$item['description']); $stmt->execute(); }
        $stmt=$db->prepare('INSERT INTO permit_documents (request_id,stored_name,original_name,mime) VALUES (?,?,?,?)');
        foreach ($documents as $doc) { $stmt->bind_param('isss',$id,$doc['stored_name'],$doc['original_name'],$doc['mime']); $stmt->execute(); }
        gateHistory($db,$id,isset($row)?'resubmitted':'submitted'); $db->commit(); return $id;
    } catch (Throwable $e) { $db->rollback(); gateRemoveUploads($documents); throw $e; }
}

function gateTransition(mysqli $db,int $id,string $action,array $input=[]): void {
    $actor=(int)($_SESSION['user_id'] ?? 0); $notes=gateText($input,'admin_notes',500);
    // Authentication may update last-seen using another connection. Resolve it
    // before locking a joined requester row to avoid waiting on our own locks.
    $review=canReviewPermits(); $security=isSecurity();
    ensureQrScanHistorySchema($db);
    $signingSignature=$action==='approved' && $review ? parkingPassSignature($id) : null;
    $db->begin_transaction();
    try {
        $row=gateRequest($db,$id,true);
        if (!$row || !$row['gate_data']) throw new InvalidArgumentException('Property permit not found.');
        $data=gateData($row); $status=gateEffectiveStatus($row); $hash=$row['qr_token_hash'];
        if (in_array($action,['approved','rejected','changes_requested'],true)) {
            if (!$review || $status!=='pending') throw new InvalidArgumentException('Only management can review a pending property permit.');
            if ($action!=='approved' && $notes==='') throw new InvalidArgumentException('Provide a reason for rejection or requested changes.');
            if (!residentUserHasPermission($db,(int)$row['user_id'],'resident.permits.request') || residentContext($db,(int)$row['user_id'])['unit_number']!==$data['unit_number']) throw new InvalidArgumentException('Requester is no longer authorized for this unit.');
            if ($action==='approved') {
                $data['authorized']=gateSchedule(gateText($input,'authorized_date',10) ?: $data['requested']['date'],gateText($input,'authorized_start',5) ?: $data['requested']['start'],gateText($input,'authorized_end',5) ?: $data['requested']['end']);
                $row['access_token']=bin2hex(random_bytes(32)); $hash=hash('sha256',gateToken($row,$signingSignature));
            } else $hash=null;
        } elseif ($action==='cancelled') {
            if ((!$review && ((int)$row['user_id']!==$actor || !residentUserHasPermission($db,$actor,'resident.permits.request'))) || !in_array($status,['pending','changes_requested','approved'],true)) throw new InvalidArgumentException('This permit cannot be cancelled.');
            $hash=null;
        } elseif ($action==='completed') {
            if ((!$review && !$security) || !gateValid($row) || !residentUserHasPermission($db,(int)$row['user_id'],'resident.permits.request') || residentContext($db,(int)$row['user_id'])['unit_number']!==$data['unit_number']) throw new InvalidArgumentException('Only security or management can complete a currently valid property permit.');
            if (($input['physical_check'] ?? '')!=='1') throw new InvalidArgumentException('Confirm the physical item check before completing this permit.');
            $data['completed_by']=$actor; $data['completed_at']=date('Y-m-d H:i:s');
            gateHistory($db,$id,'verified_valid',$notes); $hash=null;
        } else throw new InvalidArgumentException('Invalid permit action.');
        $json=json_encode($data,JSON_THROW_ON_ERROR); $nonce=$row['access_token'];
        if (in_array($action,['approved','rejected','changes_requested'],true)) {
            $stmt=$db->prepare('UPDATE resident_service_requests SET status=?,gate_data=?,qr_token_hash=?,access_token=?,admin_notes=?,decided_by=?,decided_at=NOW(),updated_at=NOW() WHERE id=?');
            $stmt->bind_param('sssssii',$action,$json,$hash,$nonce,$notes,$actor,$id);
        } else {
            $stmt=$db->prepare('UPDATE resident_service_requests SET status=?,gate_data=?,qr_token_hash=?,updated_at=NOW() WHERE id=?'); $stmt->bind_param('sssi',$action,$json,$hash,$id);
        }
        $stmt->execute(); gateHistory($db,$id,$action,$notes);
        if ($action==='completed') qrActionEvent($db,$row,'permit_completion',$notes ?: 'Physical property movement confirmed.');
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function gateVerifyToken(mysqli $db,int $id,string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
    $row=gateRequest($db,$id);
    if (!$row || !$row['gate_data'] || !hash_equals(gateToken($row),$token)) return null;
    if (!residentUserHasPermission($db,(int)$row['user_id'],'resident.permits.request') || residentContext($db,(int)$row['user_id'])['unit_number']!==gateData($row)['unit_number']) return null;
    if ($row['status']==='approved' && (!$row['qr_token_hash'] || !hash_equals($row['qr_token_hash'],hash('sha256',$token)))) return null;
    return $row;
}
