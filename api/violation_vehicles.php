<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireVehicleViolationLookup();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); header('Allow: GET'); echo json_encode(['error'=>'Use GET for vehicle lookup.']); exit;
}
try {
    $db=connectDb();
    $action=(string)($_GET['action'] ?? 'search');
    if ($action==='search') {
        $result=searchViolationVehicles($db,(string)($_GET['mode'] ?? 'plate'),(string)($_GET['query'] ?? ''));
    } elseif ($action==='violation') {
        $result=vehicleViolationDetails($db,(int)($_GET['id'] ?? 0));
        if (!$result) { http_response_code(404); $result=['error'=>'Violation not found.']; }
    } elseif (in_array($action,['details','history'],true)) {
        $record=vehicleViolationRecord($db,(string)($_GET['source'] ?? ''),(int)($_GET['id'] ?? 0));
        if (!$record) { http_response_code(404); $result=['error'=>'Vehicle or parking record no longer exists. Search again.']; }
        else $result=$action==='details' ? $record : vehicleViolationHistory($db,$record,$_GET);
    } else throw new InvalidArgumentException('Unknown lookup action.');
    echo json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(422); echo json_encode(['error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Vehicle violation lookup failed: '.$error->getMessage());
    http_response_code(503); echo json_encode(['error'=>'Vehicle lookup is temporarily unavailable. Please try again.']);
}
