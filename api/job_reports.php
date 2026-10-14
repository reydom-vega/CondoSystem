<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo '{"error":"Use POST."}';exit;}
try{
    $db=connectDb();$management=($_POST['scope']??'personal')==='management';$a=jrActor($db,$management);
    if(!hash_equals(workflowCsrfToken(),(string)($_POST['csrf_token']??''))){http_response_code(403);throw new DomainException('Refresh your session and try again.');}
    foreach($_POST as$value)if(!is_string($value))throw new DomainException('Invalid report input.');
    $input=$_POST;$input['notes']=isset($_POST['notes'])&&is_string($_POST['notes'])?json_decode($_POST['notes'],true,32,JSON_THROW_ON_ERROR):[];
    if(!is_array($input['notes']))throw new DomainException('Invalid work notes.');
    $action=(string)($_POST['action']??'overview');
    if($management&&!in_array($action,['list','detail','review'],true))throw new DomainException('Open your personal Job Report page for this action.');
    switch($action){
        case 'overview':$p=jrPeriod($input);$events=jrActivities($db,$a['id'],$p,$input);$filed=jrList($db,['type'=>$p['type'],$p['type']==='daily'?'day':'month'=>$p['period']]);$result=['actor'=>$a,'period'=>$p,'activity'=>$events,'filed_count'=>$filed['total'],'metrics'=>jrRoleMetrics($db,$a['id'],$p,$a['role']),'draft'=>jrDraft($db,$a,$p),'latest'=>jrLatestPeriod($db,$a,$p),'limit'=>jrLimit()];break;
        case 'save_draft':$result=jrSaveDraft($db,$input);break;
        case 'preview':$result=jrPreview($db,$input);break;
        case 'file':$result=jrFile($db,$input);break;
        case 'list':$result=jrList($db,$input,$management);break;
        case 'detail':$result=jrLoad($db,(int)($input['id']??0),$management);$result['total']=count($result['data']['activities']);$result['pages']=max(1,(int)ceil($result['total']/50));$page=min($result['pages'],max(1,(int)($input['page']??1)));$result['page']=$page;$result['data']['activities']=array_slice($result['data']['activities'],($page-1)*50,50);break;
        case 'review':if(!$management)throw new DomainException('Open Staff Reports to review submissions.');$result=jrReview($db,$input);break;
        default:throw new DomainException('Unknown report action.');
    }
    echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(DomainException|JsonException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[401,403],true)?$e->getCode():422);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Job report API: '.get_class($e).' '.$e->getMessage());http_response_code(500);echo '{"error":"The report could not be processed. Please try again."}';}
