<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['success'=>false,'error'=>'Use a private POST request.']);exit;}
if(empty($_POST)&&!empty($_SERVER['CONTENT_LENGTH'])){http_response_code(413);echo json_encode(['success'=>false,'error'=>'The upload exceeds the server request limit. Select fewer or smaller evidence files.']);exit;}
requireWorkflowCsrf();
try{
    $db=connectDb();$action=reportText($_POST,'action',20,true);$management=($_POST['management']??'0')==='1';$anonymous=($_POST['anonymous']??'0')==='1';
    $result=match($action){
        'list'=>reportList($db,$_POST,$management),
        'create'=>reportCreate($db,$_POST,reportUploadFiles($_FILES['evidence']??[])),
        'track'=>reportTrackAnonymous($db,reportText($_POST,'reference',24,true),reportText($_POST,'access_code',64,true)),
        'read'=>reportDetail($db,reportText($_POST,'reference',24,true),$management,$anonymous),
        'update'=>reportAction($db,$_POST,reportUploadFiles($_FILES['evidence']??[]),$management,$anonymous),
        'forget'=>(function(){unset($_SESSION['anonymous_report_access']);return [];})(),
        default=>throw new InvalidArgumentException('Unknown report request.'),
    };
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException $error){http_response_code(422);echo json_encode(['success'=>false,'error'=>$error->getMessage()]);}
catch(Throwable $error){http_response_code(503);error_log('Community report operation failed: '.get_class($error));echo json_encode(['success'=>false,'error'=>'The report could not be processed. Please retry or contact property management.']);}
