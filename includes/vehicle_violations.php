<?php
/** Vehicle references identify records, never establish responsibility for an incident. */
function ensureVehicleViolationColumns(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    $columns = [
        'vehicle_id' => 'INT DEFAULT NULL',
        'visitor_registration_id' => 'INT DEFAULT NULL',
        'parking_request_id' => 'INT DEFAULT NULL',
        'parking_slot_id' => 'INT DEFAULT NULL',
        'observed_plate' => 'VARCHAR(20) DEFAULT NULL',
        'observed_plate_normalized' => "VARCHAR(20) GENERATED ALWAYS AS (REGEXP_REPLACE(UPPER(observed_plate), '[^A-Z0-9]', '')) STORED",
        'registration_source' => 'VARCHAR(20) DEFAULT NULL',
        'vehicle_snapshot' => 'LONGTEXT DEFAULT NULL',
        'account_name_snapshot' => 'VARCHAR(100) DEFAULT NULL',
        'unit_snapshot' => 'VARCHAR(30) DEFAULT NULL',
        'incident_at' => 'DATETIME DEFAULT NULL',
        'report_remarks' => 'TEXT DEFAULT NULL',
        'review_status' => "ENUM('legacy','pending','approved','rejected') NOT NULL DEFAULT 'legacy'",
        'reviewed_by' => 'INT DEFAULT NULL',
        'reviewed_at' => 'DATETIME DEFAULT NULL',
        'responsibility_note' => 'TEXT DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!$db->query("SHOW COLUMNS FROM violations LIKE '$name'")->num_rows
            && !$db->query("ALTER TABLE violations ADD COLUMN $name $definition")) return false;
    }
    // Unknown/visitor reports have no responsible account or financial state until review.
    $user = $db->query("SHOW COLUMNS FROM violations LIKE 'user_id'")->fetch_assoc();
    if ($user['Null'] === 'NO' && !$db->query('ALTER TABLE violations MODIFY user_id INT DEFAULT NULL')) return false;
    $status = $db->query("SHOW COLUMNS FROM violations LIKE 'status'")->fetch_assoc();
    if ($status['Null'] === 'NO' && !$db->query("ALTER TABLE violations MODIFY status ENUM('warning_issued','unpaid','paid','disputed','waived') NULL DEFAULT 'warning_issued'")) return false;
    foreach (['ix_violation_vehicle'=>'vehicle_id, incident_at, id', 'ix_violation_visitor'=>'visitor_registration_id, incident_at, id',
        'ix_violation_plate'=>'observed_plate_normalized, incident_at, id', 'ix_violation_review'=>'review_status, id',
        'ix_violation_slot'=>'parking_slot_id, incident_at, id'] as $name => $fields) {
        if (!$db->query("SHOW INDEX FROM violations WHERE Key_name='$name'")->num_rows
            && !$db->query("ALTER TABLE violations ADD INDEX $name ($fields)")) return false;
    }
    return true;
}

function requireVehicleViolationLookup(): void {
    requireCapability('violations.manage', true);
    requireCapability('vehicles.lookup', true);
}

/** Explicit field lists keep private ownership documents, access tokens and contact details out of lookup responses. */
function vehicleViolationRecord(mysqli $db, string $source, int $id, bool $lock = false): ?array {
    if (!canAccess('violations.manage') || !canAccess('vehicles.lookup')) throw new InvalidArgumentException('Vehicle lookup is not permitted.');
    if ($id < 1) return null;
    if ($source === 'registered') {
        $sql = "SELECT v.id, v.user_id AS account_id, v.plate_number AS plate, v.make, v.model, v.color, v.year,
            v.status AS registration_status, u.full_name AS account_name, u.unit_number, u.account_type,
            ps.id AS parking_slot_id, ps.slot_code, ps.level, ps.status AS slot_status,
            sv.sticker_number, o.claim_status AS sticker_status, p.status AS sticker_payment_status, sv.revoked_at AS sticker_revoked_at
            FROM vehicles v JOIN users u ON u.id=v.user_id
            LEFT JOIN parking_slots ps ON ps.id=v.parking_slot_id
            LEFT JOIN parking_sticker_vehicles sv ON sv.vehicle_id=v.id
            LEFT JOIN parking_sticker_orders o ON o.id=sv.order_id
            LEFT JOIN payments p ON p.id=o.bill_payment_id WHERE v.id=? AND v.authorization_revoked_at IS NULL AND u.is_active=1 AND u.archived_at IS NULL";
    } elseif ($source === 'visitor') {
        $sql = "SELECT pr.id, pr.user_id AS account_id, pr.vehicle_plate AS plate, pr.vehicle_description,
            pr.visitor_registration_id, pr.status AS parking_status, pr.start_date, pr.end_date,
            u.full_name AS account_name, u.unit_number, u.account_type,
            ps.id AS parking_slot_id, ps.slot_code, ps.level, ps.status AS slot_status,
            vr.id AS verified_visitor_registration_id, vr.visitor_name, vr.status AS visitor_status, vl.time_in, vl.time_out, vl.status AS gate_status
            FROM parking_requests pr JOIN users u ON u.id=pr.user_id
            LEFT JOIN parking_slots ps ON ps.id=pr.slot_id
            LEFT JOIN resident_service_requests vr ON vr.id=pr.visitor_registration_id AND vr.request_kind='visitor' AND vr.user_id=pr.user_id
            LEFT JOIN visitor_logs vl ON vl.id=vr.visitor_log_id
            WHERE pr.id=? AND pr.request_type='visitor'";
    } elseif ($source === 'parking') {
        $sql = "SELECT ps.id, ps.assigned_user_id AS account_id, ps.vehicle_plate AS plate,
            u.full_name AS account_name, COALESCE(ps.assigned_unit,u.unit_number) AS unit_number, u.account_type,
            ps.id AS parking_slot_id, ps.slot_code, ps.level, ps.slot_type, ps.status AS slot_status
            FROM parking_slots ps LEFT JOIN users u ON u.id=ps.assigned_user_id WHERE ps.id=?";
    } else return null;
    $stmt = $db->prepare($sql . ($lock ? ' FOR UPDATE' : '')); $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    $row['source'] = $source;
    $row['normalized_plate'] = normalizePlateNumber((string)($row['plate'] ?? ''));
    $row['registration_type'] = $source === 'registered' ? (residentAccountKind($row['account_type']) === 'tenant' ? 'Tenant vehicle' : 'Resident vehicle')
        : ($source === 'visitor' ? 'Visitor vehicle' : 'Parking inventory record');
    $row['current'] = $source === 'registered' ? $row['registration_status'] === 'approved'
        : ($source === 'visitor' ? $row['parking_status'] === 'approved' && $row['start_date'] <= date('Y-m-d') && ($row['end_date'] ?: $row['start_date']) >= date('Y-m-d')
            && (!isset($row['visitor_status']) || in_array($row['visitor_status'], ['approved','checked_in'], true)) : $row['slot_status'] === 'occupied');
    return $row;
}

function searchViolationVehicles(mysqli $db, string $mode, string $query): array {
    if (!canAccess('violations.manage') || !canAccess('vehicles.lookup')) throw new InvalidArgumentException('Vehicle lookup is not permitted.');
    $query = trim($query);
    if (!in_array($mode, ['plate','slot'], true) || strlen($query) > 30) throw new InvalidArgumentException('Choose a search mode and enter up to 30 characters.');
    $needle = $mode === 'plate' ? normalizePlateNumber($query) : strtoupper($query);
    if (strlen($needle) < 2) return ['records'=>[], 'limited'=>false];
    // Prefix suggestions use the normalized-plate and unique slot-code indexes.
    $pattern = addcslashes($needle, '%_\\') . '%';
    $queries = $mode === 'plate' ? [
        'registered'=>"SELECT id FROM vehicles WHERE authorization_revoked_at IS NULL AND normalized_plate LIKE ? AND EXISTS(SELECT 1 FROM users u WHERE u.id=vehicles.user_id AND u.is_active=1 AND u.archived_at IS NULL) ORDER BY (status='approved') DESC, id DESC LIMIT 21",
        'visitor'=>"SELECT id FROM parking_requests WHERE lookup_plate LIKE ? AND request_type='visitor' ORDER BY (status='approved' AND start_date<=CURRENT_DATE() AND COALESCE(end_date,start_date)>=CURRENT_DATE()) DESC, id DESC LIMIT 21",
        'parking'=>"SELECT id FROM parking_slots WHERE lookup_plate LIKE ? ORDER BY id LIMIT 21",
    ] : [
        'parking'=>"SELECT id FROM parking_slots WHERE slot_code LIKE ? ORDER BY slot_code LIMIT 21",
        'registered'=>"SELECT v.id FROM parking_slots ps JOIN vehicles v ON v.parking_slot_id=ps.id WHERE ps.slot_code LIKE ? ORDER BY (v.status='approved') DESC, v.id DESC LIMIT 21",
        'visitor'=>"SELECT pr.id FROM parking_slots ps JOIN parking_requests pr ON pr.slot_id=ps.id WHERE ps.slot_code LIKE ? AND pr.request_type='visitor' ORDER BY (pr.status='approved' AND pr.start_date<=CURRENT_DATE() AND COALESCE(pr.end_date,pr.start_date)>=CURRENT_DATE()) DESC, pr.id DESC LIMIT 21",
    ];
    $records = []; $limited = false;
    foreach ($queries as $source => $sql) {
        $stmt = $db->prepare($sql); $stmt->bind_param('s', $pattern); $stmt->execute();
        $ids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($ids) > 20) { $limited = true; array_pop($ids); }
        foreach ($ids as $id) { $record = vehicleViolationRecord($db, $source, (int)$id['id']); if ($record) $records[] = $record; }
    }
    // A linked vehicle card already includes its inventory details; keep inventory as a fallback.
    $linked=$records;
    $records=array_values(array_filter($records,static function(array $record) use ($linked): bool {
        if ($record['source']!=='parking') return true;
        foreach ($linked as $other) {
            if ($other['source']==='parking' || !$other['current'] || (int)($other['parking_slot_id'] ?? 0)!==(int)$record['id']) continue;
            if ($record['normalized_plate']==='' || $record['normalized_plate']===$other['normalized_plate']) return false;
        }
        return true;
    }));
    usort($records, static fn(array $a, array $b): int => ((int)$b['current'] <=> (int)$a['current']) ?: ($b['id'] <=> $a['id']));
    return ['records'=>$records, 'limited'=>$limited];
}

/** Shared SQL history predicate: exact references first; unlinked plate matches are explicitly provisional. */
function vehicleViolationHistoryScope(array $record): array {
    $source = $record['source']; $id = (int)$record['id'];
    $plate = $record['normalized_plate'];
    if ($source === 'registered') { $exact = 'v.vehicle_id=?'; $ref = $id; }
    elseif ($source === 'visitor' && !empty($record['visitor_registration_id'])) { $exact = 'v.visitor_registration_id=?'; $ref = (int)$record['visitor_registration_id']; }
    elseif ($source === 'visitor') { $exact = 'v.parking_request_id=?'; $ref = $id; }
    else { $exact = 'v.parking_slot_id=?'; $ref = $id; }
    return ['exact'=>$exact, 'ref'=>$ref, 'plate'=>$plate,
        'where'=>"($exact OR (v.vehicle_id IS NULL AND v.visitor_registration_id IS NULL AND v.parking_request_id IS NULL AND ?<>'' AND v.observed_plate_normalized=?))"];
}

function vehicleViolationHistory(mysqli $db, array $record, array $input): array {
    if (!canAccess('violations.manage') || !canAccess('vehicles.lookup')) throw new InvalidArgumentException('Vehicle history is not permitted.');
    $scope = vehicleViolationHistoryScope($record);
    $params = [$scope['ref'], $scope['plate'], $scope['plate']]; $types = 'iss';
    $where = $scope['where'];
    $summaryStmt = $db->prepare("SELECT COUNT(*) AS total, SUM(v.review_status='pending') AS pending,
        SUM(v.review_status IN ('legacy','approved') AND v.status IN ('unpaid','paid','warning_issued')) AS confirmed,
        SUM(v.status='disputed') AS disputed, SUM(v.review_status='rejected' OR v.status='waived') AS dismissed
        FROM violations v WHERE $where");
    $summaryStmt->bind_param($types, ...$params); $summaryStmt->execute();
    $summary = array_map('intval', $summaryStmt->get_result()->fetch_assoc());
    foreach (['from'=>'>=', 'to'=>'<='] as $field => $operator) {
        $date = (string)($input[$field] ?? '');
        if ($date !== '') {
            if (!workflowDate($date)) throw new InvalidArgumentException('Enter valid history dates.');
            $where .= " AND COALESCE(v.incident_at,v.created_at) $operator ?";
            $params[] = $date . ($field === 'from' ? ' 00:00:00' : ' 23:59:59'); $types .= 's';
        }
    }
    $status = (string)($input['status'] ?? '');
    if ($status !== '') {
        if (!in_array($status,['pending','approved','rejected','warning_issued','unpaid','paid','disputed','waived'],true)) throw new InvalidArgumentException('Invalid history status.');
        $where .= in_array($status,['pending','approved','rejected'],true) ? ' AND v.review_status=?' : ' AND v.status=?';
        $params[]=$status; $types.='s';
    }
    $type=trim((string)($input['type'] ?? ''));
    if ($type !== '') { $where.=' AND v.violation_type=?'; $params[]=$type; $types.='s'; }
    $search=trim((string)($input['search'] ?? ''));
    if (strlen($search)>100 || strlen($type)>100) throw new InvalidArgumentException('History search is too long.');
    if ($search !== '') {
        $where.=" AND (v.violation_type LIKE ? OR v.description LIKE ? OR v.location LIKE ? OR v.observed_plate LIKE ? OR CAST(v.id AS CHAR)=?)";
        $term='%'.addcslashes($search,'%_\\').'%'; array_push($params,$term,$term,$term,$term,ltrim($search,'#')); $types.='sssss';
    }
    $count=$db->prepare("SELECT COUNT(*) AS total FROM violations v WHERE $where"); $count->bind_param($types,...$params); $count->execute();
    $total=(int)$count->get_result()->fetch_assoc()['total'];
    $pages=max(1,(int)ceil($total/10)); $page=min($pages,max(1,(int)($input['page'] ?? 1))); $offset=($page-1)*10;
    $stmt=$db->prepare("SELECT v.id,v.observed_plate,v.violation_type,COALESCE(v.incident_at,v.created_at) AS incident_at,
        v.location,v.fine_amount,v.status,v.review_status,v.payment_id,p.status AS payment_status,
        v.account_name_snapshot,v.unit_snapshot,v.vehicle_snapshot,($scope[exact]) AS exact_match
        FROM violations v LEFT JOIN payments p ON p.id=v.payment_id WHERE $where
        ORDER BY COALESCE(v.incident_at,v.created_at) DESC,v.id DESC LIMIT 10 OFFSET $offset");
    $allParams=array_merge([$scope['ref']],$params); $stmt->bind_param('i'.$types,...$allParams); $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $snapshot=json_decode($row['vehicle_snapshot'] ?? '',true) ?: [];
        $row['slot_code']=$snapshot['slot_code'] ?? null; unset($row['vehicle_snapshot']);
        $row['match_label']=$row['exact_match'] ? ($record['source']==='parking' ? 'Same slot; vehicle identity unconfirmed' : 'Exact registration reference') : 'Potential historical plate match';
    } unset($row);
    $typeStmt=$db->prepare('SELECT DISTINCT v.violation_type FROM violations v WHERE '.$scope['where'].' ORDER BY v.violation_type');
    $scopeParams=[$scope['ref'],$scope['plate'],$scope['plate']]; $typeStmt->bind_param('iss',...$scopeParams); $typeStmt->execute();
    return ['summary'=>$summary,'records'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'types'=>array_column($typeStmt->get_result()->fetch_all(MYSQLI_ASSOC),'violation_type')];
}

function vehicleViolationDetails(mysqli $db, int $id): ?array {
    if (!canAccess('violations.manage') || !canAccess('vehicles.lookup')) throw new InvalidArgumentException('Violation details are not permitted.');
    $stmt=$db->prepare("SELECT v.*, issuer.full_name AS reporting_personnel, reviewer.full_name AS reviewer_name,
        u.full_name AS legacy_account_name, u.unit_number AS legacy_unit_number, p.status AS payment_status
        FROM violations v LEFT JOIN users issuer ON issuer.id=v.issued_by LEFT JOIN users reviewer ON reviewer.id=v.reviewed_by
        LEFT JOIN users u ON u.id=v.user_id LEFT JOIN payments p ON p.id=v.payment_id WHERE v.id=?");
    $stmt->bind_param('i',$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    $row['vehicle_snapshot']=json_decode($row['vehicle_snapshot'] ?? '',true) ?: null;
    $row['audit_events']=[];
    if (canAccess('audit.read')) {
        $audit=$db->prepare("SELECT action,admin_name,admin_role,details,created_at FROM audit_logs WHERE entity_type='violation' AND entity_id=? ORDER BY id DESC LIMIT 100");
        $audit->bind_param('i',$id); $audit->execute(); $row['audit_events']=$audit->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $row['audit_authorized']=canAccess('audit.read');
    return $row;
}

function submitVehicleViolationReport(mysqli $db, array $input, ?string $evidencePath = null): int {
    if (!canAccess('violations.issue') || !canAccess('vehicles.lookup')) throw new InvalidArgumentException('Vehicle reporting is not permitted.');
    $source=(string)($input['registration_source'] ?? '');
    $recordId=(int)($input['related_record_id'] ?? 0);
    $record=null;
    if ($source!=='unknown') {
        $record=vehicleViolationRecord($db,$source,$recordId);
        if (!$record) throw new InvalidArgumentException('The selected registration no longer exists. Search again.');
    } elseif ($recordId!==0) throw new InvalidArgumentException('Unknown vehicles cannot use a registration reference.');
    $plate=strtoupper(trim((string)($input['observed_plate'] ?? '')));
    $normalized=normalizePlateNumber($plate);
    if ($normalized==='' || strlen($plate)>20) throw new InvalidArgumentException('Enter an observed plate number of up to 20 characters.');
    if ($record && $record['normalized_plate']!=='' && $normalized!==$record['normalized_plate']) throw new InvalidArgumentException('The observed plate does not match the selected record. Search again or report an unregistered vehicle.');
    $slotId=(int)($input['vehicle_parking_slot_id'] ?? 0);
    if ($slotId<0) throw new InvalidArgumentException('Invalid parking slot reference.');
    if ($record && $slotId!==(int)($record['parking_slot_id'] ?? 0)) throw new InvalidArgumentException('The parking assignment changed. Search and select the record again.');
    if ($source==='unknown' && $slotId>0) {
        $slot=$db->prepare('SELECT id,slot_code,level FROM parking_slots WHERE id=?'); $slot->bind_param('i',$slotId); $slot->execute();
        $slot=$slot->get_result()->fetch_assoc();
        if (!$slot) throw new InvalidArgumentException('Select an existing parking slot.');
    }
    $type=trim((string)($input['violation_type'] ?? '')); $rates=getViolationFineRates();
    $penalty=(string)($input['penalty_type'] ?? 'fine');
    if (!in_array($penalty,['fine','warning'],true) || $type==='' || strlen($type)>100 || ($penalty==='fine' && !isset($rates[$type]))) throw new InvalidArgumentException('Select a configured violation type for a vehicle fine. Custom types may be reported as warnings.');
    $amount=$penalty==='fine' ? (float)$rates[$type] : 0.0;
    $description=trim((string)($input['description'] ?? '')); $location=trim((string)($input['location'] ?? ''));
    $remarks=trim((string)($input['report_remarks'] ?? ''));
    $incident=(string)($input['incident_at'] ?? ''); $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$incident);
    if (!$date || $date->format('Y-m-d\TH:i')!==$incident || $date>new DateTimeImmutable('+1 minute')) throw new InvalidArgumentException('Enter a valid incident date and time that is not in the future.');
    if ($description==='' || strlen($description)>5000 || $location==='' || strlen($location)>255 || strlen($remarks)>5000) throw new InvalidArgumentException('Enter the incident description and actual location within the displayed limits.');
    $due=trim((string)($input['due_date'] ?? '')) ?: null;
    if ($due!==null && !workflowDate($due)) throw new InvalidArgumentException('Enter a valid fine due date.');
    if (!ensureViolationsTable($db) || !ensureAuditLogTable($db)) throw new RuntimeException('Report storage is unavailable.');
    $snapshot=$record ?? ['source'=>'unknown','plate'=>$plate,'normalized_plate'=>$normalized,'parking_slot_id'=>$slotId ?: null,'slot_code'=>$slot['slot_code'] ?? null,'level'=>$slot['level'] ?? null];
    $snapshot['captured_at']=date('Y-m-d H:i:s');
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $vehicleId=$source==='registered' ? $recordId : null;
    $visitorId=$source==='visitor' ? ($record['visitor_registration_id'] ?: null) : null;
    $requestId=$source==='visitor' ? $recordId : null; $slotId=$slotId ?: null;
    $incident=$date->format('Y-m-d H:i:s'); $issuer=(int)$_SESSION['user_id'];
    $db->begin_transaction();
    try {
        if ($record) {
            $record=vehicleViolationRecord($db,$source,$recordId,true);
            if (!$record || ($record['normalized_plate']!=='' && $record['normalized_plate']!==$normalized)
                || (int)($record['parking_slot_id'] ?? 0)!==(int)$slotId) throw new InvalidArgumentException('The selected vehicle or parking assignment changed. Search again.');
            if ($source==='visitor' && !empty($record['visitor_registration_id'])
                && (int)$record['visitor_registration_id']!==(int)$record['verified_visitor_registration_id']) throw new InvalidArgumentException('The linked visitor registration is unavailable. Report the observed vehicle without assuming a host account.');
            $snapshot=$record; $snapshot['captured_at']=date('Y-m-d H:i:s');
            $json=json_encode($snapshot,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $visitorId=$source==='visitor' ? ($record['visitor_registration_id'] ?: null) : null;
        } elseif ($slotId) {
            $slotLock=$db->prepare('SELECT slot_code,level FROM parking_slots WHERE id=? FOR UPDATE'); $slotLock->bind_param('i',$slotId); $slotLock->execute(); $slot=$slotLock->get_result()->fetch_assoc();
            if (!$slot) throw new InvalidArgumentException('The selected parking slot no longer exists.');
            $snapshot['slot_code']=$slot['slot_code']; $snapshot['level']=$slot['level'];
            $json=json_encode($snapshot,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $stmt=$db->prepare("INSERT INTO violations (user_id,violation_type,description,location,evidence_path,penalty_type,fine_amount,due_date,status,issued_by,
            vehicle_id,visitor_registration_id,parking_request_id,parking_slot_id,observed_plate,registration_source,vehicle_snapshot,incident_at,report_remarks,review_status)
            VALUES (NULL,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,'pending')");
        $stmt->bind_param('sssssdsiiiiisssss', $type,$description,$location,$evidencePath,$penalty,$amount,$due,$issuer,$vehicleId,$visitorId,$requestId,$slotId,$plate,$source,$json,$incident,$remarks);
        $stmt->execute(); $id=(int)$db->insert_id;
        if (!logAudit('vehicle_reported','violation',$id,'Vehicle incident reported for review; no charge created.',$db)) throw new RuntimeException('Could not audit the report.');
        $db->commit(); return $id;
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}

/** Caller holds a pending report lock and verified resident/billing-account locks. */
function billApprovedVehicleViolation(mysqli $db, array $report, int $billUserId, float $amount): array {
    $find=$db->prepare("SELECT p.id FROM payments p WHERE p.user_id=? AND p.billing_scope='unit'
        AND p.status IN ('pending','overdue') AND p.paymongo_checkout_id IS NULL AND p.billing_period_start IS NULL
        AND EXISTS (SELECT 1 FROM bill_items bi WHERE bi.payment_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM bill_items bi WHERE bi.payment_id=p.id AND bi.category<>'Violation Fine')
        ORDER BY p.id LIMIT 1 FOR UPDATE");
    $find->bind_param('i',$billUserId); $find->execute(); $bill=$find->get_result()->fetch_assoc();
    $billId=(int)($bill['id'] ?? 0);
    if (!$billId) {
        $due=$report['due_date'] ?: date('Y-m-d',strtotime('+15 days'));
        $create=$db->prepare("INSERT INTO payments (user_id,amount,payment_method,status,due_date,billing_scope) VALUES (?,0,'unbilled','pending',?,'unit')");
        $create->bind_param('is',$billUserId,$due); $create->execute(); $billId=(int)$db->insert_id;
    }
    $description='Violation #'.$report['id'].': '.$report['violation_type'];
    $item=$db->prepare("INSERT INTO bill_items (payment_id,category,description,amount) VALUES (?,'Violation Fine',?,?)");
    $item->bind_param('isd',$billId,$description,$amount); $item->execute(); $itemId=(int)$db->insert_id;
    recalculateBillTotal($db,$billId);
    return [$billId,$itemId];
}

/** Pending -> approved/rejected is atomic; repeated approvals never create another charge. */
function reviewVehicleViolationReport(mysqli $db, int $id, string $decision, array $input): bool {
    if (!canAccess('violations.review')) throw new InvalidArgumentException('Report review is not permitted.');
    if ($id<1 || !in_array($decision,['approve','reject'],true)) throw new InvalidArgumentException('Choose a valid report decision.');
    $note=trim((string)($input['responsibility_note'] ?? ''));
    if ($note==='' || strlen($note)>5000) throw new InvalidArgumentException('Record how responsibility was verified or why the report is rejected.');
    ensureViolationsTable($db); ensurePaymentsTable($db); ensureBillingTables($db); ensurePaymongoColumns($db); ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $find=$db->prepare('SELECT * FROM violations WHERE id=? FOR UPDATE'); $find->bind_param('i',$id); $find->execute(); $report=$find->get_result()->fetch_assoc();
        if (!$report || $report['review_status']!=='pending' || !empty($report['payment_id']) || !empty($report['bill_item_id'])) { $db->rollback(); return false; }
        $actor=(int)$_SESSION['user_id'];
        if ($decision==='reject') {
            $reject=$db->prepare("UPDATE violations SET review_status='rejected',reviewed_by=?,reviewed_at=NOW(),responsibility_note=?,admin_remarks=? WHERE id=? AND review_status='pending'");
            $reject->bind_param('issi',$actor,$note,$note,$id); $reject->execute();
        } else {
            if (($input['responsibility_verified'] ?? '')!=='1') throw new InvalidArgumentException('Confirm that the responsible account has been verified.');
            // Visitor-host charging is disabled unless the property explicitly configures a policy.
            if ($report['registration_source']==='visitor' && $report['penalty_type']==='fine' && appSetting('CONDO_VISITOR_VIOLATION_HOST_BILLING','0')!=='1') throw new InvalidArgumentException('Visitor fines require a PMO host-billing policy. Keep this report pending investigation or reject it.');
            if ($report['registration_source']==='visitor' && $report['penalty_type']==='fine' && ($input['visitor_policy_verified'] ?? '')!=='1') throw new InvalidArgumentException('Confirm the PMO policy applies to this visitor incident.');
            $userId=(int)($input['responsible_user_id'] ?? 0);
            $user=$db->prepare("SELECT id,full_name,unit_number FROM users WHERE id=? AND role='resident' AND status='approved' AND is_active=1 AND is_verified=1 FOR UPDATE");
            $user->bind_param('i',$userId); $user->execute(); $account=$user->get_result()->fetch_assoc();
            $context=residentContext($db,$userId);
            if (!$account || !$context || !$context['approved'] || empty($context['billing_user_id'])) throw new InvalidArgumentException('Select an approved responsible resident or tenant with a verified billing owner.');
            $billUserId=(int)$context['billing_user_id'];
            if ($billUserId!==$userId) { $user->bind_param('i',$billUserId); $user->execute(); if (!$user->get_result()->fetch_assoc()) throw new InvalidArgumentException('The unit billing owner is unavailable.'); }
            $rates=getViolationFineRates();
            if ($report['penalty_type']==='fine' && !isset($rates[$report['violation_type']])) throw new InvalidArgumentException('This report has no configured fine rate. Keep it pending for investigation.');
            $amount=$report['penalty_type']==='fine' ? (float)$rates[$report['violation_type']] : 0.0;
            $billId=null; $itemId=null;
            if ($amount>0) [$billId,$itemId]=billApprovedVehicleViolation($db,$report,$billUserId,$amount);
            $status=$amount>0 ? 'unpaid' : 'warning_issued';
            $update=$db->prepare("UPDATE violations SET user_id=?,account_name_snapshot=?,unit_snapshot=?,review_status='approved',status=?,fine_amount=?,payment_id=?,bill_item_id=?,reviewed_by=?,reviewed_at=NOW(),responsibility_note=?,admin_remarks=? WHERE id=? AND review_status='pending'");
            $update->bind_param('isssdiiissi',$userId,$account['full_name'],$account['unit_number'],$status,$amount,$billId,$itemId,$actor,$note,$note,$id); $update->execute();
            if ($update->affected_rows!==1) throw new RuntimeException('The review changed. Please reload.');
        }
        if (!logAudit($decision==='approve' ? 'vehicle_report_approved' : 'vehicle_report_rejected','violation',$id,substr($note,0,450),$db)) throw new RuntimeException('Could not audit the review.');
        $db->commit(); return true;
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}
