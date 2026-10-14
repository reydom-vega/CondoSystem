<?php if (permitPassDownloadAllowed($pass)): ?>
<div class="service-actions gate-pass-controls" data-permit-downloads data-export-url="<?= htmlspecialchars(residentServicePassUrl($pass), ENT_QUOTES, 'UTF-8') ?>">
    <button class="service-btn" type="button" data-permit-download="jpg">Download JPG</button>
    <button class="service-btn service-btn-secondary" type="button" data-permit-download="pdf">Download PDF</button>
</div>
<p class="gate-pass-controls" data-permit-download-feedback role="status" aria-live="polite"></p>
<?php endif; ?>
