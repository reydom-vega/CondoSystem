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
require_once __DIR__.'/includes/permit_pass_export.php';
if (isset($_GET['download'])) {
    header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
    try {
        // Re-fetch before every export so cancellation, completion or expiry
        // cannot use approval cached in an already-open browser page.
        $downloadRow = getResidentServicePass($db, $id, $token);
        if (!$downloadRow || !permitPassDownloadAllowed($downloadRow)) {
            http_response_code(403); throw new InvalidArgumentException('Downloads are available only for approved, unexpired permits.');
        }
        $downloadRow = gateRequest($db, $id);
        if (!$downloadRow || !permitPassDownloadAllowed($downloadRow)) { http_response_code(403); throw new InvalidArgumentException('This permit is no longer available for download.'); }
        $document = permitPassDownloadData($db, $downloadRow);
        if ($_GET['download'] === 'details' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Content-Type: application/json'); echo json_encode($document + ['csrf'=>workflowCsrfToken()], JSON_THROW_ON_ERROR); exit;
        }
        if ($_GET['download'] !== 'pdf' || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(400); throw new InvalidArgumentException('Invalid download request.'); }
        requireWorkflowCsrf();
        $pdf = permitPassPdf($document, is_string($_POST['qr_image'] ?? null) ? $_POST['qr_image'] : '');
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$document['reference'].'.pdf"'); echo $pdf; exit;
    } catch (InvalidArgumentException $error) {
        if (http_response_code() < 400) http_response_code(400);
        header('Content-Type: application/json'); echo json_encode(['error'=>$error->getMessage()]); exit;
    } catch (Throwable $error) {
        error_log('Permit download: '.$error->getMessage()); http_response_code(500);
        header('Content-Type: application/json'); echo json_encode(['error'=>'Unable to download this permit. Please try again.']); exit;
    }
}
if (!empty($pass['gate_data'])) { include __DIR__.'/includes/property_gate_pass_page.php'; exit; }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    if (checkInRegisteredVisitor($db, $id, $token, ($_POST['vehicle_entry_confirmed']??'')==='1')) { logAudit('checkin', 'resident_service_request', $id); redirect('resident_service_pass.php?id=' . $id . '&token=' . $token); }
    $message = 'Check-in refused. Verify the approval, visit date, and current check-in status.';
    $pass = getResidentServicePass($db, $id, $token);
}
$identityRequired = $pass['request_kind'] === 'visitor';
$approvalsComplete = !$identityRequired || visitorAccessApprovalsComplete($db, $pass);
$valid = $pass['status'] === 'approved' && $approvalsComplete && $pass['start_date'] <= date('Y-m-d') && $pass['end_date'] >= date('Y-m-d');
$label = $valid ? 'VALID' : ($pass['start_date'] > date('Y-m-d') && $pass['status'] === 'approved' ? 'NOT YET VALID' : strtoupper(str_replace('_',' ',$pass['status'])));
if ($identityRequired && $pass['status'] === 'approved' && !$approvalsComplete) $label = !visitorIdentityVerified($pass) ? 'IDENTITY ' . strtoupper($pass['identity_status']) : 'PARKING APPROVAL REQUIRED';
if ($pass['end_date'] < date('Y-m-d') && $pass['status'] !== 'checked_in') $label = 'EXPIRED';
function passEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$isVisitorPass = $pass['request_kind'] === 'visitor';
$passTitle = $isVisitorPass ? 'Visitor Access Pass' : 'Resident Permit';
$statusTone = 'danger';
$statusIcon = 'close';
$statusTitle = ucwords(strtolower($label));
$securityInstruction = 'This pass is not valid for entry. Please contact the resident or management office for assistance.';
if ($label === 'EXPIRED') {
    $statusIcon = 'clock';
    $securityInstruction = 'This pass has expired and cannot be used for entry. Please request a new pass from the resident.';
} elseif ($valid) {
    $statusTone = 'success';
    $statusIcon = 'check-circle';
    $statusTitle = 'Approved / Active';
    $securityInstruction = 'Present this QR code to security for verification upon entry.';
} elseif ($label === 'NOT YET VALID') {
    $statusTone = 'warning';
    $statusIcon = 'calendar';
    $securityInstruction = 'This approved pass is not yet valid. Entry is available only during the visit dates shown above, after security verification.';
} elseif ($pass['status'] === 'pending') {
    $statusTone = 'warning';
    $statusIcon = 'hourglass';
    $securityInstruction = 'This request is awaiting approval. It cannot be used for entry until approved and within its visit dates.';
} elseif ($pass['status'] === 'checked_in') {
    $statusTone = 'info';
    $statusIcon = 'check-circle';
    $securityInstruction = 'This visitor has already checked in. Present this pass to security for verification when leaving; it cannot be used for another entry.';
} elseif ($pass['status'] === 'checked_out') {
    $statusTone = 'info';
    $statusIcon = 'check-circle';
    $securityInstruction = 'This visit is complete. This pass cannot be used for another entry.';
} elseif ($pass['status'] === 'rejected') {
    $securityInstruction = 'This request was rejected and cannot be used for entry. Please contact the resident or management office.';
} elseif ($pass['status'] === 'cancelled') {
    $securityInstruction = 'This pass has been cancelled and cannot be used for entry.';
}
$showQr = $approvalsComplete && in_array($pass['status'], ['approved','checked_in','checked_out'], true);
$visitorFields = [
    ['clipboard', 'Reference number', '#' . $id],
    ['dashboard', 'Unit number', $pass['unit_number']],
    ['users', 'Resident name', $pass['full_name']],
    [$isVisitorPass ? 'residents' : 'file-text', $isVisitorPass ? 'Visitor name' : 'Permit type', $pass['visitor_name'] ?: $pass['permit_type']],
    ['file-text', $isVisitorPass ? 'Purpose of visit' : 'Purpose / details', $pass['details']],
];
if ($isVisitorPass) {
    $visitorFields[] = ['users', 'Number of visitors', (string)(int)$pass['visitor_count']];
    $visitorFields[] = ['shield', 'Identity verification', ucfirst($pass['identity_status'])];
    $visitorVehicle = visitorPassVehicle($db, $id);
    if ($visitorVehicle) {
        $visitorFields[] = ['parking', 'Vehicle plate', $visitorVehicle['vehicle_plate']];
        $visitorFields[] = ['parking', 'Vehicle information', $visitorVehicle['vehicle_description'] ?: 'Not provided'];
        $visitorFields[] = ['parking', 'Parking approval', ucfirst($visitorVehicle['status'])];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= passEscape($passTitle) ?> | The Celandine</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('resident.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('services.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="assets/css/resident-service-pass.css?v=<?= filemtime(__DIR__.'/assets/css/resident-service-pass.css') ?>">
    <script defer src="assets/js/resident-service-pass.js?v=<?= filemtime(__DIR__.'/assets/js/resident-service-pass.js') ?>"></script>
    <?php if ($isVisitorPass): ?><script defer src="assets/js/visitor-pass-download.js?v=<?= filemtime(__DIR__.'/assets/js/visitor-pass-download.js') ?>"></script><?php endif; ?>
    <?php if ($pass['request_kind'] === 'permit'): ?><script defer src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/permit-pass-download.js'), ENT_QUOTES, 'UTF-8') ?>"></script><?php endif; ?>
</head>
<body class="portal-ui dashboard-page visitor-pass-page">
<main class="service-pass service-panel visitor-pass" aria-labelledby="passTitle">
    <header class="visitor-pass-header">
        <?= brandHtml('compact') ?>
        <?= brandPropertyAddressHtml() ?>
        <div>
            <h1 id="passTitle"><?= passEscape($passTitle) ?></h1>
            <span class="visitor-pass-reference">Reference #<?= $id ?></span>
        </div>
    </header>
    <div class="visitor-pass-status visitor-pass-status--<?= passEscape($statusTone) ?>" aria-label="Pass status: <?= passEscape($statusTitle) ?>">
        <?= systemIcon($statusIcon) ?><strong><?= passEscape($statusTitle) ?></strong>
    </div>
    <?php if ($message): ?><p class="visitor-pass-feedback" role="alert"><?= passEscape($message) ?></p><?php endif; ?>
    <section class="visitor-pass-section" aria-labelledby="visitorDetailsTitle">
        <h2 id="visitorDetailsTitle"><?= $isVisitorPass ? 'Visitor information' : 'Permit information' ?></h2>
        <dl class="visitor-pass-details">
            <?php foreach ($visitorFields as [$icon, $fieldLabel, $fieldValue]): ?>
            <div class="visitor-pass-row">
                <dt><?= systemIcon($icon, 'visitor-pass-row-icon') ?><?= passEscape($fieldLabel) ?></dt>
                <dd><?= passEscape($fieldValue) ?><?php if ($fieldLabel === 'Number of visitors'): ?><small>Including the named visitor</small><?php endif; ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </section>
    <section class="visitor-pass-section visitor-pass-schedule" aria-labelledby="visitScheduleTitle">
        <h2 id="visitScheduleTitle"><?= $isVisitorPass ? 'Visit schedule' : 'Permit schedule' ?></h2>
        <dl class="visitor-pass-details">
            <div class="visitor-pass-row">
                <dt><?= systemIcon('calendar', 'visitor-pass-row-icon') ?><?= $isVisitorPass ? 'Visit start date' : 'Start date' ?></dt>
                <dd><time datetime="<?= passEscape($pass['start_date']) ?>"><?= passEscape($pass['start_date']) ?></time></dd>
            </div>
            <div class="visitor-pass-row">
                <dt><?= systemIcon('calendar', 'visitor-pass-row-icon') ?><?= $isVisitorPass ? 'Visit end date' : 'End date' ?></dt>
                <dd><time datetime="<?= passEscape($pass['end_date']) ?>"><?= passEscape($pass['end_date']) ?></time></dd>
            </div>
        </dl>
    </section>
    <section class="visitor-pass-qr-section" aria-labelledby="passQrTitle">
        <h2 id="passQrTitle">Security verification</h2>
        <?php if ($showQr): ?>
        <div id="serviceQr" data-qr-value="<?= passEscape(residentServicePassUrl($pass)) ?>" data-qr-correction="<?= $isVisitorPass ? 'Q' : 'M' ?>" role="img" aria-label="<?= $isVisitorPass ? 'Visitor' : 'Permit' ?> access QR code" aria-describedby="passSecurityInstruction"></div>
        <p id="passQrFallback" class="visitor-pass-qr-fallback" role="status">QR code unavailable. Reload this page before presenting or downloading the pass.</p>
        <?php else: ?>
        <div class="visitor-pass-qr-unavailable"><?= systemIcon('lock') ?><p>QR code unavailable for this request status.</p></div>
        <?php endif; ?>
    </section>
    <aside class="visitor-pass-instructions visitor-pass-instructions--<?= $valid ? 'valid' : 'warning' ?>" aria-labelledby="securityInstructionsTitle">
        <?= systemIcon($valid ? 'shield' : 'info') ?>
        <div><h2 id="securityInstructionsTitle"><?= $valid ? 'At the gate' : 'Entry notice' ?></h2><p id="passSecurityInstruction"><?= passEscape($securityInstruction) ?></p></div>
    </aside>
    <?php if ($valid && isSecurity() && $pass['request_kind'] === 'visitor' && empty($visitorVehicle)): ?>
    <form method="post" class="service-actions visitor-pass-checkin"><?= workflowCsrfField() ?><?php if(!empty($visitorVehicle)): ?><label class="checkbox"><input type="checkbox" name="vehicle_entry_confirmed" value="1" required> I have verified actual vehicle arrival and its assigned visitor slot. The parking timer starts now.</label><?php endif; ?><button type="submit"><?= systemIcon('check-circle') ?><?= !empty($visitorVehicle)?'Confirm visitor &amp; vehicle check-in':'Confirm visitor check-in' ?></button></form>
    <?php endif; ?>
    <?php if($isVisitorPass && visitorParkingFeesReady($db)){ $vpLink=$db->query('SELECT id FROM parking_requests WHERE visitor_registration_id='.(int)$id." AND status='approved' ORDER BY id DESC LIMIT 1")->fetch_assoc();if($vpLink){$vpOnlyRequest=(int)$vpLink['id'];include __DIR__.'/includes/visitor_parking_widget.php';unset($vpOnlyRequest);}} ?>
    <?php if ($pass['request_kind'] === 'permit') include __DIR__.'/includes/permit_pass_download_controls.php'; ?>
    <div class="service-actions visitor-pass-actions">
        <?php if ($isVisitorPass): ?>
        <?php if ($showQr): ?><button type="button" class="service-btn" data-visitor-pass-download data-brand-image="<?= passEscape(buildUrl('assets/branding/email-brand-light.png')) ?>" data-property="<?= passEscape(json_encode(getPropertyInformation(), JSON_THROW_ON_ERROR)) ?>" data-download-name="visitor-access-pass-<?= $id ?>.png" disabled><?= systemIcon('download', 'system-action-icon') ?>Download Image</button><?php endif; ?>
        <?php else: ?>
        <button type="button" class="service-btn" onclick="window.print()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/><path d="M18 12h.01"/></svg>Print Pass</button>
        <?php endif; ?>
        <a class="service-btn service-btn-secondary" href="<?= isSuperAdmin() ? 'superadmin/scanner.php' : (isSecurity() ? 'security/scanner.php' : (canReviewPermits() ? 'superadmin/service_requests.php' : ('resident/' . ($pass['request_kind'] === 'visitor' ? 'visitors.php' : 'permits.php')))) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7M5 12h14"/></svg>Back</a>
    </div>
    <?php if ($isVisitorPass): ?><p data-visitor-pass-download-feedback role="status" aria-live="polite"></p><?php endif; ?>
    <?= brandDocumentFooterHtml() ?>
</main>
</body>
</html>
