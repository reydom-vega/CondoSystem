<?php
// Render inside the existing resident/staff layout. All identity comes from storage.
require_once __DIR__.'/permit_workspace.php';
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
$permitTabs=$staffServices ? ['requests'=>'Requests','history'=>'History'] : ['form'=>'Request Form','requests'=>'Requests','history'=>'History'];
$permitGroups=['requests'=>[],'history'=>[]];
foreach ($requests as $request) $permitGroups[permitRequestIsHistory($request) ? 'history' : 'requests'][]=$request;
$permitDetailTab=$permitDetail && permitRequestIsHistory($permitDetail) ? 'history' : 'requests';
$permitActiveTab=is_string($_GET['tab'] ?? null) && isset($permitTabs[$_GET['tab']]) ? $_GET['tab'] : (isset($_GET['history']) ? 'history' : ($staffServices ? 'requests' : 'form'));
if ($permitDetail) $permitActiveTab=$editPermit ? 'form' : $permitDetailTab;
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($error)) {
    if (!$staffServices && in_array($_POST['action'] ?? 'create',['gate_save','create'],true)) $permitActiveTab='form';
    else foreach ($requests as $request) {
        if ((int)$request['id']===(int)($_POST['request_id'] ?? 0)) $permitActiveTab=permitRequestIsHistory($request) ? 'history' : 'requests';
    }
}
$permitBaseUrl=$staffServices ? 'service_requests.php?kind=permit' : 'permits.php';
$permitFormAction='permits.php?tab=form'.($editPermit ? '&request_id='.(int)$editPermit['id'].'&edit=1' : '');
$permitDetailAction=$permitDetail ? $permitBaseUrl.($staffServices ? '&' : '?').'request_id='.(int)$permitDetail['id'].'&tab='.$permitDetailTab : null;
?>
<div class="permit-workspace" data-workspace-tabs>
<?php renderPermitWorkspaceTabs($permitTabs, $permitActiveTab, $permitGroups, $staffServices); ?>
<?php if (!$staffServices): ?><section <?= permitWorkspacePanelAttributes('form', $permitActiveTab) ?>><?php endif; ?>
<?php if (!$staffServices && $ready): ?>
<section class="service-panel permit-form-card" aria-labelledby="permitFormTitle">
    <header class="permit-panel-heading">
        <div><span class="permit-eyebrow">Property Management Office</span><h2 id="permitFormTitle"><?= $editPermit ? 'Correct and resubmit your permit' : 'Request a permit' ?></h2><p>Tell management what you need to move or arrange for your unit. Property movements require approval before entry or exit.</p></div>
    </header>
    <div class="permit-requester-heading"><?= systemIcon('users') ?><h3>Requester information</h3><span>From your account</span></div>
    <dl class="permit-identity"><div><dt>Requester</dt><dd><?= serviceEscape($identity['full_name']) ?></dd></div><div><dt>Unit</dt><dd><?= serviceEscape($identity['unit_number']) ?></dd></div><div><dt>Account ID</dt><dd><?= (int)$identity['id'] ?></dd></div><div><dt>Contact</dt><dd><?= serviceEscape($identity['contact_number'] ?: $identity['email']) ?></dd></div></dl>
    <?php if ($editPermit): ?><p class="alert error" role="alert">Requested changes: <?= serviceEscape($editPermit['admin_notes']) ?></p><?php endif; ?>
    <form method="post" action="<?= serviceEscape($permitFormAction) ?>" enctype="multipart/form-data" class="service-form" id="permitRequestForm">
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
            <div class="service-submit"><button type="submit"><?= systemIcon('send') ?><?= $editPermit ? 'Resubmit permit request' : 'Submit permit request' ?></button><a class="service-btn service-btn-secondary" href="permits.php?tab=requests" data-permit-tab-link="requests">Cancel</a></div>
        </div>
    </form>
</section>
<?php endif; ?>
<?php if (!$staffServices): ?></section><?php endif; ?>
<?php foreach ($permitGroups as $tabKey => $permitRecords): ?>
<section <?= permitWorkspacePanelAttributes($tabKey, $permitActiveTab) ?>>
<?php if ($permitDetail && !$editPermit && $permitDetailTab === $tabKey): ?>
<section class="service-panel permit-detail-panel">
<?php $gateRow=$permitDetail; include __DIR__.'/permit_details.php'; ?>
<a class="service-btn service-btn-secondary" href="<?= serviceEscape($permitBaseUrl . ($staffServices ? '&' : '?') . 'tab=' . $tabKey) ?>">Back to <?= $tabKey === 'history' ? 'permit history' : 'permit requests' ?></a>
</section>
<?php endif; ?>
<?php $permitListAction = $permitBaseUrl . ($staffServices ? '&' : '?') . 'tab=' . $tabKey; include __DIR__.'/permit_request_records.php'; ?>
</section>
<?php endforeach; ?>
</div>
<script src="../js/permit-requests.js?v=<?= filemtime(__DIR__ . '/../js/permit-requests.js') ?>"></script>
