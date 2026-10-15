<?php
require_once dirname(__DIR__).'/config.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['success'=>false,'error'=>'Use POST.']);exit;}
requireWorkflowCsrf();
try{
    $db=connectDb();$action=vpText($_POST,'action',30,true);$id=(int)($_POST['id']??0);
    $result=match($action){
        'list'=>vpWorkspaceData($db,$_POST),
        'detail'=>vpSessionData($db,vpLoad($db,$id),vpNow()),
        'review_approval'=>vpApprovalReview($db,$id),
        'approve_all'=>vpApproveTogether($db,$id,$_POST),
        'rate_policy'=>vpRatePolicyView($db),
        'save_rate'=>['id'=>vpSaveRate($db,$_POST)],
        'finalize_rate'=>vpFinalizeRateReview($db,$id,$_POST),
        'checkin'=>vpVehicleCheckIn($db,$id),
        'checkout'=>vpCheckoutSession($db,$id),
        'extend'=>['id'=>vpRequestExtension($db,$id,$_POST)],
        'decide'=>['saved'=>vpDecideExtension($db,$id,vpText($_POST,'decision',20,true),vpText($_POST,'decision_note',1000),vpText($_POST,'approved_until',16))],
        'cancel'=>['saved'=>vpCancelExtension($db,$id)],
        'acknowledge'=>(function()use($db,$id){vpAcknowledgeReservation($db,$id,$_POST);return ['saved'=>true];})(),
        default=>throw new InvalidArgumentException('Unknown parking action.'),
    };echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException $error){http_response_code(422);echo json_encode(['success'=>false,'error'=>$error->getMessage()]);}
catch(Throwable $error){http_response_code(503);error_log('Visitor parking operation failed: '.get_class($error));echo json_encode(['success'=>false,'error'=>'Parking could not be updated. Refresh before retrying.']);}
