<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
try { profileRequireSelf(); } catch (InvalidArgumentException $error) { http_response_code(401); echo json_encode(['success'=>false,'error'=>$error->getMessage()]); exit; }
try {
    $db = connectDb();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode(['success'=>true,'profile'=>profilePublicData(profileCurrent($db,true))],JSON_THROW_ON_ERROR); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['success'=>false,'error'=>'Method not allowed.']); exit; }
    requireWorkflowCsrf();
    if (isset($_POST['user_id']) && (string)$_POST['user_id'] !== (string)$_SESSION['user_id']) { http_response_code(403); throw new InvalidArgumentException('You may edit only your own profile photo.'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'upload') $profile = profileStorePhoto($db,$_FILES['photo'] ?? null);
    elseif ($action === 'remove') $profile = profileRemovePhoto($db);
    else throw new InvalidArgumentException('Invalid photo action.');
    echo json_encode(['success'=>true,'profile'=>$profile],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) { if (http_response_code()<400) http_response_code(422); echo json_encode(['success'=>false,'error'=>$error->getMessage()]); }
catch (Throwable $error) { error_log('Profile image request failed: '.$error->getMessage()); http_response_code(503); echo json_encode(['success'=>false,'error'=>'Unable to save your profile picture. Please try again or contact management.']); }
