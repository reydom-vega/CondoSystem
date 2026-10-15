<?php
require_once __DIR__.'/config.php';require_once __DIR__.'/includes/audit_report.php';require_once __DIR__.'/includes/audit_report_export.php';
header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');
try{
    $db=connectDb();auditReportActor($db);
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');throw new DomainException('Use the authorized report download button.');}
    if(!is_string($_POST['csrf_token']??null)||!hash_equals(workflowCsrfToken(),$_POST['csrf_token']))throw new DomainException('Refresh your session and try again.',403);
    foreach($_POST as$value)if(!is_string($value))throw new DomainException('Invalid audit export input.');
    $format=$_POST['format']??'pdf';if(!in_array($format,['pdf','png','preview'],true))throw new DomainException('Choose PDF, PNG or Preview Report.');
    $data=auditReportCapture($db,$_POST);session_write_close();auditReportDownload($db,$data,$format);
}catch(DomainException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[401,403],true)?$e->getCode():422);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){http_response_code(503);error_log('Audit report export: '.get_class($e));header('Content-Type: application/json');echo '{"error":"Unable to generate the complete audit report. Please try again."}';}
