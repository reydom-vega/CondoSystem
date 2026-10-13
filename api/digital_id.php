<?php
require_once __DIR__.'/../config.php';
digitalIdNoStore();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['success'=>false,'error'=>'Method not allowed.']); exit; }
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Sign in again to open your Digital Condo ID.']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
requireWorkflowCsrf(is_array($input) && is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : '');
try {
    $db = connectDb();
    if (!allowAuthenticationRequest($db, 'digital-id-issue:'.(int)$_SESSION['user_id'], 120)) {
        http_response_code(429); header('Retry-After: 60'); echo json_encode(['success'=>false,'error'=>'Too many QR requests. Please wait before trying again.']); exit;
    }
    if (($input['action'] ?? '') === 'issue') echo json_encode(digitalIdIssue($db), JSON_THROW_ON_ERROR);
    elseif (($input['action'] ?? '') === 'revoke') { digitalIdRevoke($db); echo json_encode(['success'=>true]); }
    else { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Invalid Digital ID action.']); }
} catch (InvalidArgumentException $error) { http_response_code(403); echo json_encode(['success'=>false,'error'=>$error->getMessage()]); }
catch (Throwable $error) { error_log('Digital ID request failed: '.get_class($error)); http_response_code(503); echo json_encode(['success'=>false,'error'=>'Digital ID is temporarily unavailable. Please try again.']); }
