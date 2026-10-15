<?php
require_once __DIR__.'/../config.php';
if (!($parkingWorkspaceEmbedded ?? false)) { requireCapability('parking.configure'); $parkingDefaultTab='slots'; require __DIR__.'/parking.php'; return; }
if (!isLoggedIn()) redirect('../login.php');
if (!isSuperAdmin()) { http_response_code(403); exit('Access denied.'); }
$db = connectDb();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_visitor_rate') {
    requireWorkflowCsrf();
    try { vpSaveRate($db,$_POST);setFlash('success','New visitor parking rate version scheduled.');redirect('parking.php?tab=slots'); }
    catch(InvalidArgumentException $exception){$error=$exception->getMessage();}
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sticker_price'])) {
    requireWorkflowCsrf();
    if (saveParkingPolicy($db, $_POST)) { logAudit('configure','parking_policy',1,'Updated parking sticker price, order limit, and visitor duration'); setFlash('success','Parking configuration saved.'); redirect('parking.php?tab=slots'); }
    $error = 'Price must be 1–100,000, quantity 1–100, and visitor duration 1–30 days.';
}
$policy = getParkingPolicy($db); $flash = getFlash();
include __DIR__.'/../includes/parking_configuration_content.php';
include __DIR__.'/../includes/visitor_parking_rate_settings.php';
