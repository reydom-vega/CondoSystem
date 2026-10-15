<?php
$vpEscape=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$vpValue=static fn(string $field):string=>htmlspecialchars((string)(is_string($_POST[$field]??null)?$_POST[$field]:($vpExisting[$field]??'')),ENT_QUOTES,'UTF-8');
$vpSlots=connectDb()->query("SELECT id,slot_code FROM parking_slots WHERE slot_type='visitor' AND status<>'maintenance' ORDER BY slot_code")->fetch_all(MYSQLI_ASSOC);
?>
<div class="service-form service-wide vp-plan-fields" data-vp-plan>
<label class="field-label">Vehicle type<select name="vehicle_type" required><option value="">Choose type</option><?php foreach(['Car','Motorcycle','Van','SUV','Pickup','Other']as$type): ?><option<?= ($_POST['vehicle_type']??$vpExisting['vehicle_type']??'')===$type?' selected':'' ?>><?= $type ?></option><?php endforeach; ?></select></label>
<label class="field-label">Vehicle model (optional)<input name="vehicle_model" maxlength="60" value="<?= $vpValue('vehicle_model') ?>" placeholder="e.g. Toyota Vios"></label>
<label class="field-label">Vehicle color (optional)<input name="vehicle_color" maxlength="30" value="<?= $vpValue('vehicle_color') ?>"></label>
<label class="field-label">Requested visitor slot<select name="requested_slot_id"><option value="">Any available visitor slot</option><?php foreach($vpSlots as$slot): ?><option value="<?= (int)$slot['id'] ?>"<?= (int)($_POST['requested_slot_id']??$vpExisting['requested_slot_id']??0)===(int)$slot['id']?' selected':'' ?>><?= $vpEscape($slot['slot_code']) ?></option><?php endforeach; ?></select><small>Authorized personnel confirm the slot after checking availability.</small></label>
<?php renderVisitorParkingRateCard(connectDb()); ?>
<label class="checkbox service-wide"><input type="checkbox" name="parking_fee_ack" value="1" required<?= ($_POST['parking_fee_ack']??'')==='1'?' checked':'' ?>><span>I understand the visitor parking rates and acknowledge that applicable parking fees will be charged to the responsible billing account associated with this visit.</span></label>
</div>
