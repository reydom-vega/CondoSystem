<?php
require_once __DIR__.'/../config.php';require_once __DIR__.'/../includes/system_analytics.php';require_once __DIR__.'/../includes/analytics_charts.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    $db=connectDb();anActor($db);if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');throw new DomainException('Use POST.');}
    if(!is_string($_POST['csrf_token']??null)||!hash_equals(workflowCsrfToken(),$_POST['csrf_token']))throw new DomainException('Refresh your session and try again.',403);
    $operation=$_POST['operation']??'report';if($operation==='detail')$data=anDetail($db,$_POST);elseif($operation==='report'){$scope=$_POST['scope']??'all';if(!is_string($scope))throw new DomainException('Choose a report scope.');$data=anCapture($db,$_POST,$scope==='all'?null:$scope);foreach($data['sections']as&$s)foreach($s['charts']as&$c)$c['svg']=anChartSvg($c);unset($s,$c);}else throw new DomainException('Unknown analytics operation.');
    echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(DomainException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[401,403,404],true)?$e->getCode():422);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){http_response_code(503);error_log('System Analytics API: '.get_class($e));echo '{"error":"Analytics could not be retrieved. Please try again."}';}
