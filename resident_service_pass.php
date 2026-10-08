<?php
require_once __DIR__ . '/config.php';
if (!isLoggedIn()) redirect('login.php');
$db = connectDb();
ensureResidentServicesTables($db);
$id = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');
$pass = getResidentServicePass($db, $id, $token);
if (!$pass || ((int)$pass['user_id'] !== (int)$_SESSION['user_id'] && !isSecurity() && !canReviewPermits())) { http_response_code(404); exit('Pass not found or access denied.'); }
if ((int)$pass['user_id']===(int)$_SESSION['user_id']) requireResidentPermission($pass['request_kind']==='visitor' ? 'resident.visitors.register' : 'resident.permits.request');
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    if (checkInRegisteredVisitor($db, $id, $token)) { logAudit('checkin', 'resident_service_request', $id); redirect('resident_service_pass.php?id=' . $id . '&token=' . $token); }
    $message = 'Check-in refused. Verify the approval, visit date, and current check-in status.';
    $pass = getResidentServicePass($db, $id, $token);
}
$valid = $pass['status'] === 'approved' && $pass['start_date'] <= date('Y-m-d') && $pass['end_date'] >= date('Y-m-d');
$label = $valid ? 'VALID' : ($pass['start_date'] > date('Y-m-d') && $pass['status'] === 'approved' ? 'NOT YET VALID' : strtoupper(str_replace('_',' ',$pass['status'])));
if ($pass['end_date'] < date('Y-m-d')) $label = 'EXPIRED';
function passEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Resident Access Pass</title><link rel="stylesheet" href="resident.css"><link rel="stylesheet" href="services.css"></head>
<body class="dashboard-page"><main class="service-pass service-panel"><h1><?= $pass['request_kind'] === 'visitor' ? 'Visitor Access Pass' : 'Resident Permit' ?></h1><h2><?= passEscape($label) ?></h2><?php if ($message): ?><p role="alert"><?= passEscape($message) ?></p><?php endif; ?>
<p>Reference #<?= $id ?> · Unit <?= passEscape($pass['unit_number']) ?></p><p>Resident: <?= passEscape($pass['full_name']) ?></p><p><?= passEscape($pass['visitor_name'] ?: $pass['permit_type']) ?></p><p><?= passEscape($pass['start_date']) ?> to <?= passEscape($pass['end_date']) ?></p><p><?= passEscape($pass['details']) ?></p>
<?php if (in_array($pass['status'], ['approved','checked_in','checked_out'], true)): ?><div id="serviceQr" aria-label="Access QR code"></div><?php endif; ?>
<?php if ($valid && isSecurity() && $pass['request_kind'] === 'visitor'): ?><form method="post" class="service-actions"><?= workflowCsrfField() ?><button type="submit">Confirm visitor check-in</button></form><?php endif; ?>
<div class="service-actions"><button type="button" onclick="window.print()">Print pass</button><a class="service-btn service-btn-secondary" href="<?= isSuperAdmin() ? 'superadmin/scanner.php' : (isSecurity() ? 'security/scanner.php' : (canReviewPermits() ? 'superadmin/service_requests.php' : ('resident/' . ($pass['request_kind'] === 'visitor' ? 'visitors.php' : 'permits.php')))) ?>">Back</a></div>
</main><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script>if(document.getElementById('serviceQr')) new QRCode(document.getElementById('serviceQr'),{text:<?= json_encode(residentServicePassUrl($pass), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,width:256,height:256});</script></body></html>
