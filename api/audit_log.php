<?php
require_once __DIR__.'/../config.php';require_once __DIR__.'/../includes/audit_report.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    $db=connectDb();auditReportActor($db);
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');throw new DomainException('Use POST.');}
    if(!is_string($_POST['csrf_token']??null)||!hash_equals(workflowCsrfToken(),$_POST['csrf_token']))throw new DomainException('Refresh your session and try again.',403);
    $action=$_POST['operation']??'list';$data=match($action){'list'=>auditReportList($db,$_POST),'detail'=>auditReportDetail($db,filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0),default=>throw new DomainException('Unknown audit operation.')};echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG);
}catch(DomainException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[401,403,404],true)?$e->getCode():422);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){http_response_code(503);error_log('Audit report API: '.get_class($e));echo '{"error":"Audit records could not be loaded. Please try again."}';}
