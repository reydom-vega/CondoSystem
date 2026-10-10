<?php
$gateError='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf();
    try {
        if (($_POST['action'] ?? '')!=='gate_completed') throw new InvalidArgumentException('Invalid action.');
        gateTransition($db,(int)$pass['id'],'completed',$_POST);
        redirect(gatePassUrl(gateRequest($db,(int)$pass['id'])));
    } catch (InvalidArgumentException $e) { $gateError=$e->getMessage(); }
    catch (Throwable $e) { error_log($e->getMessage()); $gateError='Unable to confirm movement. Please try again.'; }
}
$gateRow=gateRequest($db,(int)$pass['id']); $gateStatus=gateEffectiveStatus($gateRow);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= gateEscape(gateNumber((int)$gateRow['id'])) ?> · The Celandine Homes</title><link rel="stylesheet" href="resident.css"><link rel="stylesheet" href="services.css?v=<?= filemtime(__DIR__.'/../services.css') ?>"><?php renderPortalUiHead(); ?>
</head><body class="portal-ui dashboard-page"><main class="service-panel property-gate-print"><header class="gate-pass-brand"><h1>The Celandine Homes</h1><p>Property Gate Pass</p></header>
<?php if($gateError): ?><p class="alert error" role="alert"><?= gateEscape($gateError) ?></p><?php endif; ?>
<?php if(!gateValid($gateRow)): ?><p class="alert error" role="status">Invalid for movement now: <?= gateEscape(str_replace('_',' ',$gateStatus)) ?> or outside the authorized schedule.</p><?php else: ?><p class="alert success">Valid within the authorized schedule. Security must check all items before confirming completion.</p><?php endif; ?>
<?php include __DIR__.'/permit_details.php'; ?>
<?php if($gateStatus==='approved'): ?><div id="serviceQr" aria-label="Property gate pass verification QR code"></div><p>Scan to verify the current status. Opening or scanning this pass does not complete the movement.</p><?php endif; ?>
<div class="service-actions gate-pass-controls"><button type="button" onclick="window.print()">Print / Save as PDF</button><a class="service-btn service-btn-secondary" href="<?= gateEscape(buildUrl(isSecurity()?'security/scanner.php':(canReviewPermits()?'superadmin/service_requests.php?kind=permit':'resident/permits.php'))) ?>">Back</a></div>
</main><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script>if(document.getElementById('serviceQr')) new QRCode(document.getElementById('serviceQr'),{text:<?= json_encode(gatePassUrl($gateRow),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,width:256,height:256});</script><script src="js/permit-requests.js"></script></body></html>
