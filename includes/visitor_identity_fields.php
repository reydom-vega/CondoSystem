<?php /** Used only in authenticated resident visitor registration / replacement forms. */ ?>
<label class="field-label" for="<?= serviceEscape($identityFieldPrefix) ?>-id-type">Valid ID Type
    <select id="<?= serviceEscape($identityFieldPrefix) ?>-id-type" name="id_type" required>
        <option value="">Choose a valid ID type</option>
        <?php foreach (VISITOR_ID_TYPES as $idType): ?><option value="<?= serviceEscape($idType) ?>" <?= ($_POST['id_type'] ?? '') === $idType ? 'selected' : '' ?>><?= serviceEscape($idType) ?></option><?php endforeach; ?>
    </select>
</label>
<label class="field-label" for="<?= serviceEscape($identityFieldPrefix) ?>-id-number">ID Number (optional)
    <input id="<?= serviceEscape($identityFieldPrefix) ?>-id-number" name="id_number" maxlength="80" autocomplete="off" aria-describedby="<?= serviceEscape($identityFieldPrefix) ?>-number-help">
    <small id="<?= serviceEscape($identityFieldPrefix) ?>-number-help">Only the last four characters are retained for staff reference.</small>
</label>
<label class="field-label service-wide" for="<?= serviceEscape($identityFieldPrefix) ?>-id-upload">Valid ID Upload (required)
    <input id="<?= serviceEscape($identityFieldPrefix) ?>-id-upload" type="file" name="valid_id" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required aria-describedby="<?= serviceEscape($identityFieldPrefix) ?>-upload-help">
    <small id="<?= serviceEscape($identityFieldPrefix) ?>-upload-help">JPG, PNG or WEBP. Maximum 5 MB. Upload a readable ID for the named visitor or group representative.</small>
</label>
<p class="service-wide visitor-id-privacy">Privacy notice: identification is collected only to verify the visitor for condo access. Only authorized Admin and Security personnel can view the document. Cover unrelated information; leave the visitor's name, photo and ID type readable. The ID image and optional number reference are deleted <?= visitorIdRetentionDays() ?> days after the visit date under our retention policy. ID documents and numbers are excluded from QR pages. Provide this notice to your visitor before uploading.</p>
