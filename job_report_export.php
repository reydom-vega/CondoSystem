<?php
require_once __DIR__.'/config.php';require_once __DIR__.'/includes/job_report_export.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Use the authorized report download button.');}
try{
    $db=connectDb();$management=($_POST['scope']??'')==='management';$a=jrActor($db,$management);
    if(!hash_equals(workflowCsrfToken(),(string)($_POST['csrf_token']??''))){http_response_code(403);throw new DomainException('Refresh your session and try again.');}
    foreach($_POST as$value)if(!is_string($value))throw new DomainException('Invalid report input.');
    $filed=null;
    if((int)($_POST['id']??0)>0){$filed=jrLoad($db,(int)$_POST['id'],$management);$data=$filed['data'];}
    else{
        if($management)throw new DomainException('Choose a filed report.');$p=jrPeriod($_POST);$notes=jrNotes(json_decode((string)($_POST['notes']??'{}'),true,32,JSON_THROW_ON_ERROR));
        $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);try{$data=jrCapture($db,$a,$p,$notes);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
        if(!hash_equals(hash('sha256',jrJson($data)),(string)($_POST['preview_hash']??'')))throw new DomainException('The report changed. Preview it again before downloading.');$data['generated_at']=gmdate('Y-m-d H:i:s');
    }
    jrExportDownload($data,(string)($_POST['format']??'pdf'),$filed);
}catch(DomainException|JsonException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[401,403],true)?$e->getCode():422);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Job report export: '.get_class($e).' '.$e->getMessage());http_response_code(500);header('Content-Type: application/json');echo '{"error":"Unable to generate this report. Please try again."}';}
