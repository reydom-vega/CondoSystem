<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$requestId = (int)($_GET['request_id'] ?? 0);
$signature = trim($_GET['signature'] ?? '');
$pass = $requestId > 0 ? getParkingPass($requestId, $signature) : null;
$isGateViewer = canAccess('security.gate');
$isOwner = $pass && (int)$pass['user_id'] === (int)($_SESSION['user_id'] ?? 0);

if (!$pass || (!$isGateViewer && !$isOwner)) {
    http_response_code(404);
    exit('Parking pass not found or access denied.');
}

if ($isOwner && !$isGateViewer) requireResidentPermission('resident.parking.request');

$today = date('Y-m-d');
$isValid = $today >= $pass['start_date'] && $today <= ($pass['end_date'] ?: $pass['start_date']);
$statusLabel = $isValid ? 'VALID' : ($today < $pass['start_date'] ? 'NOT YET VALID' : 'EXPIRED');
$passUrl = parkingPassUrl($requestId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Parking Pass</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .pass-shell { width: min(100%, 620px); margin: 0 auto; }
        .pass-card { padding: 24px; background: var(--dash-card-bg); border: 1px solid var(--dash-card-border); border-radius: 12px; }
        .pass-status { display: inline-block; padding: 7px 12px; border-radius: 7px; background: <?php echo $isValid ? '#065f46' : '#7f1d1d'; ?>; color: #fff; font-weight: 800; letter-spacing: .08em; }
        .pass-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        .pass-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 18px 0; }
        .pass-field { padding: 12px; background: var(--input-bg); border-radius: 8px; }
        .pass-field small { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: 10px; text-transform: uppercase; }
        .pass-field strong { color: var(--text-primary); word-break: break-word; }
        .pass-qr { display: flex; justify-content: center; padding: 24px; background: #fff; border-radius: 10px; }
        #qrCode { width: min(100%, 360px); aspect-ratio: 1; }
        #qrCode img, #qrCode canvas { display: block; width: 100% !important; height: 100% !important; image-rendering: pixelated; }
        .pass-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .pass-actions button, .pass-actions a { width: auto; margin: 0; text-decoration: none; }
        .pass-note { color: var(--text-muted); font-size: 12px; line-height: 1.5; margin-top: 14px; }
        @media (max-width: 520px) { .pass-grid { grid-template-columns: 1fr; } .pass-heading { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
    <main class="pass-shell">
        <section class="pass-card">
            <div class="pass-heading"><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Visitor Parking Pass</h1></div><span class="pass-status"><?php echo $statusLabel; ?></span></div>
            <div id="qrCode" class="pass-qr" aria-label="Visitor parking QR code"></div>
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
                <button type="button" id="downloadPass">Download QR Pass</button>
                <?php if ($isGateViewer): ?><a href="<?php echo isSuperAdmin() ? 'superadmin/scanner.php' : 'security/scanner.php'; ?>" class="btn-neutral">Back to Scanner</a><?php else: ?><a href="resident/parking.php" class="btn-neutral">Back to Parking</a><?php endif; ?>
            </div>
        </section>
    </main>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const passUrl = <?php echo json_encode($passUrl); ?>;
        const qrSize = 360;
        const qrBorder = 24;
        const qrCode = new QRCode(document.getElementById('qrCode'), { text: passUrl, width: qrSize, height: qrSize, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
        document.getElementById('downloadPass').addEventListener('click', function () {
            const canvas = document.querySelector('#qrCode canvas');
            if (!canvas) return;
            const downloadCanvas = document.createElement('canvas');
            downloadCanvas.width = qrSize + (qrBorder * 2);
            downloadCanvas.height = qrSize + (qrBorder * 2);
            const downloadContext = downloadCanvas.getContext('2d');
            downloadContext.fillStyle = '#ffffff';
            downloadContext.fillRect(0, 0, downloadCanvas.width, downloadCanvas.height);
            downloadContext.drawImage(canvas, qrBorder, qrBorder, qrSize, qrSize);
            const link = document.createElement('a');
            link.download = 'visitor-parking-pass-<?php echo $requestId; ?>.png';
            link.href = downloadCanvas.toDataURL('image/png');
            link.click();
        });
    </script>
</body>
</html>
