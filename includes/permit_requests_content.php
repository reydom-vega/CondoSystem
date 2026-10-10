<?php
// Render inside the existing resident/staff layout. All identity comes from storage.
$identityStmt=$db->prepare('SELECT id,full_name,unit_number,contact_number,email FROM users WHERE id=?');
$identityId=(int)$_SESSION['user_id']; $identityStmt->bind_param('i',$identityId); $identityStmt->execute(); $identity=$identityStmt->get_result()->fetch_assoc();
$editPermit=$permitDetail && !$staffServices && $permitDetail['gate_data'] && $permitDetail['status']==='changes_requested' && ($_GET['edit'] ?? '')==='1' ? $permitDetail : null;
$formData=$editPermit ? gateData($editPermit) : [];
$formInput=$_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='gate_save' ? $_POST : [
    'permit_type'=>$editPermit ? 'Property Gate Pass' : 'Property Gate Pass',
    'direction'=>$formData['direction'] ?? 'entry','purpose'=>$formData['purpose'] ?? GATE_PURPOSES[0],
    'details'=>$formData['description'] ?? '', 'start_date'=>$formData['requested']['date'] ?? date('Y-m-d'),
    'start_time'=>$formData['requested']['start'] ?? '', 'end_time'=>$formData['requested']['end'] ?? '',
    'transporter'=>$formData['transporter'] ?? '', 'transporter_contact'=>$formData['transporter_contact'] ?? '',
    'vehicle_plate'=>$formData['vehicle_plate'] ?? '', 'personnel'=>$formData['personnel'] ?? '',
    'items'=>$editPermit ? gateChildren($db,(int)$editPermit['id'],'permit_items') : [['item_name'=>'','category'=>'Appliance','quantity'=>1,'description'=>'']]
];
if (!function_exists('permitFormValue')) {
    function permitFormValue(string $name): string { global $formInput; return serviceEscape(is_scalar($formInput[$name] ?? null) ? $formInput[$name] : ''); }
}
?>
<?php if ($permitDetail): ?><section class="service-panel permit-detail-panel">
<?php $gateRow=$permitDetail; include __DIR__.'/permit_details.php'; ?>
<a class="service-btn service-btn-secondary" href="<?= $staffServices ? 'service_requests.php?kind=permit' : 'permits.php' ?>">Back to permit history</a>
</section><?php endif; ?>
<?php if (!$staffServices && $ready && (!$permitDetail || $editPermit) && !isset($_GET['history'])): ?>
<section class="service-panel permit-form-card" aria-labelledby="permitFormTitle">
    <header class="permit-panel-heading">
        <div><span class="permit-eyebrow">Property Management Office</span><h2 id="permitFormTitle"><?= $editPermit ? 'Correct and resubmit your permit' : 'Request a permit' ?></h2><p>Tell management what you need to move or arrange for your unit. Property movements require approval before entry or exit.</p></div>
        <a class="service-btn service-btn-secondary" href="#permitHistory"><?= systemIcon('clipboard') ?>View history</a>
    </header>
    <div class="permit-requester-heading"><?= systemIcon('users') ?><h3>Requester information</h3><span>From your account</span></div>
    <dl class="permit-identity"><div><dt>Requester</dt><dd><?= serviceEscape($identity['full_name']) ?></dd></div><div><dt>Unit</dt><dd><?= serviceEscape($identity['unit_number']) ?></dd></div><div><dt>Account ID</dt><dd><?= (int)$identity['id'] ?></dd></div><div><dt>Contact</dt><dd><?= serviceEscape($identity['contact_number'] ?: $identity['email']) ?></dd></div></dl>
    <?php if ($editPermit): ?><p class="alert error" role="alert">Requested changes: <?= serviceEscape($editPermit['admin_notes']) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="service-form" id="permitRequestForm">
        <?= workflowCsrfField() ?><input type="hidden" name="action" value="gate_save"><input type="hidden" name="request_id" value="<?= (int)($editPermit['id'] ?? 0) ?>">
        <section class="service-wide permit-form-section" aria-labelledby="permitRequestDetailsTitle">
            <div class="permit-section-heading"><?= systemIcon('file-text') ?><div><h3 id="permitRequestDetailsTitle">Request details</h3><p>Choose the permit category and the date you need it.</p></div></div>
            <div class="service-form permit-meta-grid">
                <label class="field-label" for="permitCategory">Permit category<select id="permitCategory" name="permit_type" <?= $editPermit ? 'disabled' : '' ?>><?php foreach(RESIDENT_PERMIT_TYPES as $category): ?><option <?= ($formInput['permit_type'] ?? '')===$category ? 'selected' : '' ?>><?= serviceEscape($category) ?></option><?php endforeach; ?></select></label>
                <label class="field-label" for="permitRequestedDate">Requested date<input id="permitRequestedDate" type="date" name="start_date" min="<?= date('Y-m-d') ?>" value="<?= permitFormValue('start_date') ?>" required></label>
            </div>
        </section>
        <fieldset class="service-wide permit-fieldset" id="propertyFields">
            <legend>Property movement</legend>
            <section class="permit-form-section" aria-labelledby="permitMovementTitle">
                <div class="permit-section-heading"><?= systemIcon('arrow-right') ?><div><h3 id="permitMovementTitle">Movement &amp; purpose</h3><p>Select whether the listed items will enter or leave your unit.</p></div></div>
                <div class="permit-directions">
                    <?php foreach(['entry'=>'Property Entry (Bring In)','exit'=>'Property Exit (Pull-Out)'] as $value=>$label): ?>
                    <label><input type="radio" name="direction" value="<?= $value ?>" <?= ($formInput['direction'] ?? 'entry')===$value ? 'checked' : '' ?> required><span><strong><?= $label ?></strong><small><?= $value==='entry' ? 'Bring items into your unit' : 'Take items out of your unit' ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="service-form permit-purpose-grid">
                    <label class="field-label" for="gatePurpose">Purpose<select name="purpose" id="gatePurpose" required><?php foreach(GATE_PURPOSES as $purpose): ?><option <?= ($formInput['purpose'] ?? '')===$purpose ? 'selected' : '' ?>><?= serviceEscape($purpose) ?></option><?php endforeach; ?></select></label>
                    <label class="field-label" for="gateDescription">Description<textarea name="details" id="gateDescription" aria-describedby="gateDescriptionHint" maxlength="500" rows="3"><?= permitFormValue('details') ?></textarea><small id="gateDescriptionHint">Optional; required when the purpose is Other.</small></label>
                </div>
            </section>
            <section class="permit-form-section" aria-labelledby="permitItemsTitle">
                <div class="permit-section-heading"><?= systemIcon('clipboard') ?><div><h3 id="permitItemsTitle">Items to move</h3><p>Include every item. Quantity is the number of units of each item.</p></div><span id="permitItemCount" class="permit-count" role="status" aria-live="polite"></span></div>
                <div id="permitItems">
                    <?php $formItems=is_array($formInput['items'] ?? null) ? array_slice(array_values($formInput['items']),0,50) : []; foreach($formItems as $index=>$item): if(!is_array($item)) continue; ?>
                    <div class="permit-item">
                        <div class="permit-item-heading"><h4>Item <span data-permit-item-number><?= $index+1 ?></span></h4><button type="button" class="service-btn service-btn-danger permit-remove">Remove item</button></div>
                        <div class="service-form permit-item-fields">
                            <label class="field-label">Item name<input name="items[<?= $index ?>][item_name]" maxlength="120" value="<?= serviceEscape($item['item_name'] ?? '') ?>" placeholder="e.g. Refrigerator" required></label>
                            <label class="field-label">Category<select name="items[<?= $index ?>][category]" required><?php foreach(GATE_ITEM_CATEGORIES as $category): ?><option <?= ($item['category'] ?? '')===$category?'selected':'' ?>><?= serviceEscape($category) ?></option><?php endforeach; ?></select></label>
                            <label class="field-label">Quantity<input type="number" min="1" max="65535" step="1" name="items[<?= $index ?>][quantity]" value="<?= serviceEscape(is_scalar($item['quantity'] ?? null)?$item['quantity']:1) ?>" required></label>
                            <label class="field-label service-wide"><span>Description <span class="permit-optional">(optional)</span></span><input name="items[<?= $index ?>][description]" maxlength="300" value="<?= serviceEscape($item['description'] ?? '') ?>" placeholder="Color, model or other identifying details"></label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="permit-add-row"><button type="button" class="service-btn service-btn-secondary" id="addPermitItem"><?= systemIcon('clipboard') ?>Add another item</button><small>Up to 50 items per request.</small></div>
            </section>
            <section class="permit-form-section" aria-labelledby="permitTimeTitle">
                <div class="permit-section-heading"><?= systemIcon('clock') ?><div><h3 id="permitTimeTitle">Movement time</h3><p>Choose your expected time window on the requested date.</p></div></div>
                <div class="service-form"><label class="field-label">Expected start time<input type="time" name="start_time" value="<?= permitFormValue('start_time') ?>" required></label><label class="field-label">Expected end time<input type="time" name="end_time" value="<?= permitFormValue('end_time') ?>" required></label></div>
            </section>
            <section class="permit-form-section" aria-labelledby="permitTransportTitle">
                <div class="permit-section-heading"><?= systemIcon('car') ?><div><h3 id="permitTransportTitle">Transport &amp; personnel</h3><p>Add delivery or vehicle information if available.</p></div><span class="permit-section-tag">Optional</span></div>
                <div class="service-form">
                    <?php foreach(['transporter'=>'Delivery company / transporter','transporter_contact'=>'Transporter contact','vehicle_plate'=>'Vehicle plate number','personnel'=>'Authorized delivery personnel'] as $key=>$label): ?><label class="field-label"><?= $label ?><input name="<?= $key ?>" maxlength="<?= $key==='vehicle_plate'?20:($key==='transporter_contact'?30:120) ?>" value="<?= permitFormValue($key) ?>"></label><?php endforeach; ?>
                </div>
            </section>
            <section class="permit-form-section permit-upload-section" aria-labelledby="permitDocumentsTitle">
                <div class="permit-section-heading"><?= systemIcon('paperclip') ?><div><h3 id="permitDocumentsTitle">Supporting documents</h3><p>Attach relevant photos or documents, if needed.</p></div><span class="permit-section-tag">Optional</span></div>
                <label class="field-label" for="permitDocuments">Choose files<input id="permitDocuments" type="file" name="documents[]" aria-describedby="permitDocumentsHelp" accept="image/jpeg,image/png,application/pdf" multiple><small id="permitDocumentsHelp">JPG, PNG or PDF; up to five files per submission, <?= (int)(gateUploadLimit()/1024/1024) ?> MB each. Existing documents are retained when resubmitting.</small></label>
            </section>
        </fieldset>
        <fieldset class="permit-fieldset service-wide" id="legacyPermitFields" hidden disabled><legend>Permit details</legend><div class="service-form"><label class="field-label">End date<input type="date" name="end_date" min="<?= date('Y-m-d') ?>"></label><label class="field-label">Purpose / description<textarea name="details" maxlength="500" required></textarea></label></div></fieldset>
        <div class="service-wide permit-submit-section">
            <p><?= systemIcon('shield') ?>Management reviews your request before a permit is approved.</p>
            <div class="service-submit"><button type="submit"><?= systemIcon('send') ?><?= $editPermit ? 'Resubmit permit request' : 'Submit permit request' ?></button><a class="service-btn service-btn-secondary" href="permits.php?history=1#permitHistory">Cancel</a></div>
        </div>
    </form>
</section>
<?php endif; ?>
<section class="service-panel permit-history-panel" id="permitHistory" aria-labelledby="permitHistoryTitle"><header class="permit-panel-heading"><div><h2 id="permitHistoryTitle"><?= $staffServices ? 'Permit management' : 'Your permit history' ?></h2><p>Track the status of your requests and view approved permits.</p></div><?php if(!$staffServices && isset($_GET['history'])): ?><a class="service-btn" href="permits.php#permitRequestForm"><?= systemIcon('file-text') ?>Request a permit</a><?php endif; ?></header>
<div class="service-form permit-filters"><label class="field-label">Search permits<input type="search" id="permitSearch" placeholder="Permit number, requester or unit"></label><label class="field-label">Status<select id="permitStatusFilter"><option value="">All statuses</option><?php foreach(['pending','changes_requested','approved','rejected','cancelled','expired','completed'] as $status): ?><option value="<?= $status ?>"><?= serviceEscape(ucfirst(str_replace('_',' ',$status))) ?></option><?php endforeach; ?></select></label></div>
<?php if(!$requests): ?><p class="service-empty-state">No permits submitted yet. Your requests will appear here after submission.</p><?php else: ?><div class="permit-table-wrap"><table class="permit-table"><thead><tr><th>Permit number / type</th><?php if($staffServices): ?><th>Requester / Unit</th><?php endif; ?><th>Items</th><th>Schedule</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($requests as $request): $property=!empty($request['gate_data']); $data=$property?gateData($request):[]; $status=$property?gateEffectiveStatus($request):$request['status']; $schedule=$data['authorized'] ?? $data['requested'] ?? null; ?>
<tr data-permit-status="<?= serviceEscape($status) ?>"><td data-label="Permit"><div class="permit-cell-value"><strong><?= $property?gateNumber((int)$request['id']):'#'.(int)$request['id'] ?></strong><br><?= serviceEscape($request['permit_type']) ?><?= $property?'<br>'.($data['direction']==='entry'?'Bring In':'Pull-Out'):'' ?></div></td>
<?php if($staffServices): ?><td><?= serviceEscape($request['full_name']) ?><br>Unit <?= serviceEscape($request['unit_number']) ?></td><?php endif; ?>
<td data-label="Items"><div class="permit-cell-value"><?= $property?(int)$request['item_count']:'—' ?></div></td><td data-label="Schedule"><div class="permit-cell-value"><?= serviceEscape($schedule['date'] ?? $request['start_date']) ?><?php if($schedule): ?><br><?= serviceEscape($schedule['start'].' – '.$schedule['end']) ?><?php else: ?><br>to <?= serviceEscape($request['end_date']) ?><?php endif; ?></div></td><td data-label="Submitted"><div class="permit-cell-value"><?= serviceEscape($request['created_at']) ?></div></td><td data-label="Status"><div class="permit-cell-value"><span class="service-status service-status-<?= serviceEscape($status) ?>"><?= serviceEscape(ucfirst(str_replace('_',' ',$status))) ?></span></div></td>
<td class="permit-actions-cell" data-label="Actions"><div class="permit-table-actions"><a class="service-btn service-btn-secondary" href="<?= $staffServices?'service_requests.php?kind=permit&amp;':'permits.php?' ?>request_id=<?= (int)$request['id'] ?>">View details</a>
<?php if(!$staffServices && $property && $status==='changes_requested'): ?><a class="service-btn" href="permits.php?request_id=<?= (int)$request['id'] ?>&amp;edit=1">Edit / resubmit</a><?php endif; ?>
<?php if($status==='approved'): ?><a class="service-btn service-btn-secondary" href="<?= serviceEscape(residentServicePassUrl($request)) ?>"><?= $property?'View gate pass':'View permit' ?></a><?php endif; ?>
<?php if($property && in_array($status,['pending','changes_requested','approved'],true)): ?><form method="post" data-confirm="Cancel this permit request?"><?= workflowCsrfField() ?><input type="hidden" name="action" value="gate_cancelled"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="service-btn service-btn-danger">Cancel request</button></form><?php elseif(!$property && !$staffServices && in_array($status,['pending','approved'],true)): ?><form method="post" data-confirm="Cancel this permit request?"><?= workflowCsrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="service-btn service-btn-danger">Cancel request</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><p id="permitNoMatches" hidden>No permits match your search.</p><?php endif; ?></section>
<script src="../js/permit-requests.js?v=<?= filemtime(__DIR__ . '/../js/permit-requests.js') ?>"></script>
