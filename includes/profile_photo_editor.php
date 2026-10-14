<?php /* The original profile page owns this one editor for every role. */ ?>
<dialog id="profilePhotoEditor" class="profile-photo-editor" aria-labelledby="profilePhotoEditorTitle" data-endpoint="<?= htmlspecialchars(buildUrl('api/profile_photo.php'), ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(workflowCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <header><div><h2 id="profilePhotoEditorTitle">Adjust Profile Picture</h2><p>Drag to position your photo. Pinch or use the slider to zoom.</p></div><button type="button" class="profile-photo-close" data-photo-cancel aria-label="Close photo editor"><?= systemIcon('close') ?></button></header>
    <div class="profile-photo-stage" id="profilePhotoStage" tabindex="0" role="img" aria-label="Circular photo preview. Drag or use arrow keys to reposition."><canvas id="profilePhotoCanvas" width="512" height="512"></canvas></div>
    <label class="field-label" for="profilePhotoZoom">Zoom</label><input id="profilePhotoZoom" type="range" min="1" max="4" step="0.01" value="1">
    <p id="profilePhotoEditorFeedback" class="profile-photo-feedback" role="status" aria-live="polite"></p>
    <div class="profile-photo-actions"><button type="button" data-photo-cancel>Cancel</button><button type="button" id="resetProfilePhoto">Reset</button><button type="button" id="applyProfilePhoto" class="profile-photo-apply">Apply Photo</button></div>
</dialog>
<script defer src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/profile-photo-editor.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
