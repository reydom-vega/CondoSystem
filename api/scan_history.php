<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
requireCapability('security.gate',true);
$db=connectDb(); ensureQrScanHistorySchema($db);
try {
    qrScannerIdentity($db);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        $input=json_decode(file_get_contents('php://input'),true);
        requireWorkflowCsrf(is_array($input) && is_string($input['csrf_token'] ?? null)?$input['csrf_token']:'');
        if(!is_array($input) || !is_string($input['content'] ?? null) || !is_string($input['request_id'] ?? null)) throw new InvalidArgumentException('Invalid scan request.');
        echo json_encode(recordQrVerification($db,trim($input['content']),$input['request_id']),JSON_THROW_ON_ERROR); exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed.']); exit; }
    if(isset($_GET['id'])) {
        $id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id) throw new InvalidArgumentException('Invalid scan ID.');
        $row=qrQuery($db,'SELECT * FROM qr_scan_logs WHERE id=?','i',[$id])->fetch_assoc();
        if(!$row) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scan not found.']); exit; }
        echo json_encode(['success'=>true,'record'=>qrPublicRecord($row)]); exit;
    }
    echo json_encode(qrHistoryPage($db,qrHistoryFilters($_GET)),JSON_THROW_ON_ERROR);
} catch(InvalidArgumentException $e) { http_response_code(422); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
catch(Throwable $e) { error_log('QR history request failed: '.get_class($e)); http_response_code(503); echo json_encode(['success'=>false,'error'=>'Scan history is temporarily unavailable. Please try again.']); }