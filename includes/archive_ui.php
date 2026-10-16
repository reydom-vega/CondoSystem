<?php
function renderArchiveConfirmation(bool $includeStyles=true): void {
    if(!canAccess('archive.manage'))return;
    ?>
    <?php if($includeStyles): ?><link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/archive.css'),ENT_QUOTES,'UTF-8') ?>"><?php endif; ?>
    <dialog class="archive-dialog" data-archive-dialog aria-labelledby="archiveConfirmTitle" data-endpoint="<?= htmlspecialchars(buildUrl('api/account_archive.php'),ENT_QUOTES,'UTF-8') ?>">
        <form method="post" data-archive-confirm-form data-loading="manual">
            <?= workflowCsrfField() ?>
            <div class="archive-dialog-header"><h2 id="archiveConfirmTitle">Review archive action</h2><button type="button" class="btn-neutral" data-archive-close aria-label="Close confirmation"><?= systemIcon('close') ?></button></div>
            <p data-archive-introduction></p>
            <p role="status" aria-live="polite" data-archive-feedback></p>
            <div data-archive-impact></div>
            <div data-archive-confirm-fields hidden>
                <label class="field-label" for="archiveReasonCode">Reason<select id="archiveReasonCode" name="reason_code" required><option value="">Choose a reason</option><?php foreach(ARCHIVE_REASONS as $value=>$label): ?><option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label>
                <label class="field-label" for="archiveReason">Explanation<textarea id="archiveReason" name="reason" rows="3" minlength="3" maxlength="1000" required placeholder="Explain why this relationship or account is ending."></textarea></label>
                <label class="archive-check checkbox" data-archive-cancel-unused hidden><input type="checkbox" name="cancel_unused" value="1"><span>Cancel the unused visitor/permit/parking authorizations and future bookings listed above. Keep existing bills, paid transactions, maintenance work and visits in progress for authorized staff review.</span></label>
                <label class="archive-check checkbox" data-archive-parking-clear hidden><input type="checkbox" name="parking_cleared" value="1"><span>I have physically inspected the resident parking slots to be released and confirmed they are empty. Visitor sessions with recorded entry remain occupied until Security confirms actual checkout.</span></label>
                <label class="archive-check checkbox"><input type="checkbox" name="confirmed" value="1" required><span>I reviewed the selected identities, relationships and linked records, and authorize this action. Historical records and outstanding obligations must be preserved.</span></label>
            </div>
            <div class="service-actions archive-dialog-actions"><button type="button" class="service-btn service-btn-secondary" data-archive-close>Cancel</button><button type="button" class="service-btn service-btn-secondary" data-archive-reload hidden>Refresh summary</button><button type="submit" class="service-btn" data-archive-submit disabled>Confirm archive</button></div>
        </form>
    </dialog>
    <script defer src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/archive.js'),ENT_QUOTES,'UTF-8') ?>"></script>
    <?php
}
