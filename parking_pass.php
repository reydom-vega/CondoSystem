<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$requestId = (int)($_GET['request_id'] ?? 0);
$signature = trim($_GET['signature'] ?? '');
$pass = $requestId > 0 ? getParkingPassDetails($requestId, $signature) : null;
$isGateViewer = canAccess('security.gate');
$isOwner = $pass && (int)$pass['user_id'] === (int)($_SESSION['user_id'] ?? 0);

if (!$pass || (!$isGateViewer && !$isOwner)) {
    http_response_code(404);
    renderPortalError(
        'Parking pass not found or access denied. You are signed in as "' . (string)($_SESSION['username'] ?? '') . '". Open the pass using the resident or tenant account that submitted this parking request, or an Admin, Super Admin or Security account. Return to your dashboard to open the current pass from Parking & Vehicles → Requests.',
        'Parking pass unavailable'
    );
    exit;
}

if ($isOwner && !$isGateViewer) requireResidentPermission('resident.parking.request');

$today = date('Y-m-d');
$approvalNotice = parkingPassApprovalNotice($pass);
$isValid = $approvalNotice === null && $today >= $pass['start_date'] && $today <= ($pass['end_date'] ?: $pass['start_date']);
$statusLabel = $approvalNotice !== null ? 'NOT ACTIVE' : ($isValid ? 'VALID' : ($today < $pass['start_date'] ? 'NOT YET VALID' : 'EXPIRED'));
$passUrl = parkingPassUrl($requestId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Parking Pass | The Celandine</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .pass-shell { width: min(100%, 620px); margin: 0 auto; }
        .pass-card { padding: 24px; background: var(--dash-card-bg); border: 1px solid var(--dash-card-border); border-radius: 12px; }
        .pass-status { display: inline-block; padding: 7px 12px; border-radius: 7px; background: <?php echo $isValid ? 'var(--theme-success-surface,#065f46)' : 'var(--theme-danger-surface,#7f1d1d)'; ?>; color: var(--theme-ink,#fff); font-weight: 800; letter-spacing: .08em; }
        .pass-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        .pass-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 18px 0; }
        .pass-field { padding: 12px; background: var(--input-bg); border-radius: 8px; }
        .pass-field small { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: 10px; text-transform: uppercase; }
        .pass-field strong { color: var(--text-primary); word-break: break-word; }
        .pass-qr { display: flex; justify-content: center; padding: 24px; background: #fff; border-radius: 10px; }
        #qrCode { width: fit-content; max-width: 100%; margin: 0 auto; }
        #qrCode img, #qrCode canvas { display: block; width: auto; max-width: 100%; height: auto; image-rendering: pixelated; }
        .pass-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .pass-actions button, .pass-actions a { width: auto; margin: 0; text-decoration: none; }
        .pass-note { color: var(--text-muted); font-size: 12px; line-height: 1.5; margin-top: 14px; }
        @media (max-width: 520px) { .pass-grid { grid-template-columns: 1fr; } .pass-heading { align-items: flex-start; flex-direction: column; } }
    </style>
<?php renderPortalUiHead(); ?>
    <script defer src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/resident-service-pass.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body class="portal-ui portal-account-page">
    <main class="pass-shell">
        <section class="pass-card">
            <div class="pass-heading"><div><?= brandHtml('compact') ?><h1 class="dash-title">Visitor Parking Pass</h1></div><span class="pass-status"><?php echo $statusLabel; ?></span></div>
            <?php if ($approvalNotice === null): ?>
            <div id="qrCode" class="pass-qr" data-qr-value="<?= htmlspecialchars($passUrl, ENT_QUOTES, 'UTF-8') ?>" data-qr-size="360" aria-label="Visitor parking QR code"></div>
            <p id="passQrFallback" class="pass-note" role="status">QR code unavailable. Reload this page before downloading the pass.</p>
            <?php else: ?><div class="alert error" role="status"><?= htmlspecialchars($approvalNotice, ENT_QUOTES, 'UTF-8') ?><?php if ($isOwner && residentHasPermission('resident.visitors.register') && !empty($pass['visitor_registration_id'])): ?><p><a href="resident/visitors.php?tab=requests">View visitor registration</a></p><?php endif; ?></div><?php endif; ?>
            <div class="pass-grid">
                <div class="pass-field"><small>Pass ID</small><strong>VP-<?php echo str_pad((string)$requestId, 6, '0', STR_PAD_LEFT); ?></strong></div>
                <div class="pass-field"><small>Resident</small><strong><?php echo htmlspecialchars($pass['full_name']); ?></strong></div>
                <?php if (!empty($pass['visitor_name'])): ?><div class="pass-field"><small>Registered visitor</small><strong><?php echo htmlspecialchars($pass['visitor_name']); ?></strong></div><?php endif; ?>
                <div class="pass-field"><small>Unit</small><strong><?php echo htmlspecialchars($pass['unit_number']); ?></strong></div>
                <div class="pass-field"><small>Vehicle Plate</small><strong><?php echo htmlspecialchars($pass['vehicle_plate']); ?></strong></div>
                <div class="pass-field"><small>Vehicle</small><strong><?php echo htmlspecialchars($pass['vehicle_description'] ?: 'Not specified'); ?></strong></div>
                <div class="pass-field"><small>Assigned Slot</small><strong><?php echo htmlspecialchars($pass['slot_code'] ?: 'Visitor slot'); ?></strong></div>
                <div class="pass-field"><small>Valid From</small><strong><?php echo htmlspecialchars(date('M j, Y', strtotime($pass['start_date']))); ?></strong></div>
                <div class="pass-field"><small>Valid Until</small><strong><?php echo htmlspecialchars(date('M j, Y', strtotime($pass['end_date'] ?: $pass['start_date']))); ?></strong></div>
            </div>
            <p class="pass-note">Security can scan this QR code to verify the visitor, vehicle, assigned slot, and validity dates. This pass is valid only for an approved visitor parking request.</p>
            <div class="pass-actions">
                <?php if ($approvalNotice === null): ?><button type="button" id="downloadPass" data-download-name="visitor-parking-pass-<?= $requestId ?>.png" disabled>Download QR Pass</button><?php endif; ?>
                <?php if ($isGateViewer): ?><a href="<?php echo isSuperAdmin() ? 'superadmin/scanner.php' : 'security/scanner.php'; ?>" class="btn-neutral">Back to Scanner</a><?php else: ?><a href="resident/parking.php" class="btn-neutral">Back to Parking</a><?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
