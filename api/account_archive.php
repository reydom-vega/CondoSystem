<?php
require_once dirname(__DIR__).'/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
requireCapability('archive.manage',true);
try {
    $db=connectDb();
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $data=archivePreview($db,$_GET);echo json_encode(['success'=>true,'preview'=>$data],JSON_THROW_ON_ERROR);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
    requireWorkflowCsrf();
    $result=archiveApply($db,$_POST);
    echo json_encode(['success'=>true,'result'=>$result,'redirect'=>buildUrl('superadmin/archive.php?id='.$result['event_id'])],JSON_THROW_ON_ERROR);
}catch(DomainException $error){http_response_code(403);echo json_encode(['error'=>$error->getMessage()]);}
catch(InvalidArgumentException $error){http_response_code(422);echo json_encode(['error'=>$error->getMessage()]);}
catch(Throwable $error){error_log('Archive operation: '.$error->getMessage());http_response_code(500);echo json_encode(['error'=>'The archive operation could not finish. No partial changes were saved. Refresh the record and try again.']);}
