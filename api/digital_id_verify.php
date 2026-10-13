<?php
require_once __DIR__.'/../config.php';
digitalIdNoStore();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['success'=>false,'error'=>'Method not allowed.']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !is_string($input['credential'] ?? null) || strlen($input['credential']) > 64) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Invalid QR Code']); exit; }
try {
    $db = connectDb();
    if (!allowAuthenticationRequest($db, 'digital-id-verification', 600)) {
        http_response_code(429); header('Retry-After: 60'); echo json_encode(['success'=>false,'error'=>'Too many verification requests. Please wait.']); exit;
    }
    if (digitalIdCanReview()) {
        requireWorkflowCsrf(is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : '');
        if (!is_string($input['request_id'] ?? null)) throw new InvalidArgumentException('A verification request ID is required.');
        // Existing deduplicated, token-redacted audit history; no physical entry action.
        $data = recordQrVerification($db, buildUrl('digital_id_verify.php').'#credential='.$input['credential'], $input['request_id']);
        echo json_encode($data, JSON_THROW_ON_ERROR);
    } else {
        $result = digitalIdVerify($db, $input['credential']);
        $public = array_intersect_key($result, array_flip(['valid','verification_result','message','expires_at','server_time']));
        echo json_encode(['success'=>true,'verification'=>$public], JSON_THROW_ON_ERROR);
    }
} catch (InvalidArgumentException $error) { http_response_code(422); echo json_encode(['success'=>false,'error'=>$error->getMessage()]); }
catch (Throwable $error) { error_log('Digital ID verification failed: '.get_class($error)); http_response_code(503); echo json_encode(['success'=>false,'error'=>'Verification is temporarily unavailable. Please try again.']); }
