<?php
require_once __DIR__.'/config.php';
digitalIdNoStore();
$reviewer = digitalIdCanReview();
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Digital Condo ID Verification — The Celandine</title>
<?php renderPortalUiHead(); ?>
<?php if (empty($_SESSION['user_id'])): ?><link rel="stylesheet" href="<?= $escape(portalUiAssetUrl('assets/css/digital-id.css')) ?>"><?php endif; ?>
<script defer src="<?= $escape(portalUiAssetUrl('assets/js/digital-id-verify.js')) ?>"></script>
</head><body class="portal-ui digital-id-verification-page">
<main class="digital-id-verification" data-digital-id-verification data-endpoint="<?= $escape(buildUrl('api/digital_id_verify.php')) ?>" data-csrf="<?= $escape(workflowCsrfToken()) ?>" data-reviewer="<?= $reviewer ? 'true' : 'false' ?>">
    <?= brandHtml('compact') ?>
    <h1>Digital Condo ID Verification</h1>
    <h2 id="digitalIdVerificationStatus" data-state="pending" role="status" aria-live="polite">Checking QR code...</h2>
    <div id="digitalIdVerificationDetails"></div>
    <?php if (!$reviewer): ?><p>Only authorized Admin and Security personnel can view identity details. Sign in, then scan the current code again.</p><a href="<?= $escape(buildUrl('login.php')) ?>">Authorized staff sign in</a><?php endif; ?>
    <p class="digital-id-verification-note">A valid QR confirms current account eligibility. Security must match the displayed identity to the person and check their access permissions. This QR does not authorize entry, parking, paid amenities, or restricted areas.</p>
</main></body></html>
