<?php
$vpEscape=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$vpValue=static fn(string $field):string=>is_string($_POST[$field]??null)?htmlspecialchars($_POST[$field],ENT_QUOTES,'UTF-8'):'';
$vpSlots=connectDb()->query("SELECT id,slot_code FROM parking_slots WHERE slot_type='visitor' AND status<>'maintenance' ORDER BY slot_code")->fetch_all(MYSQLI_ASSOC);
?>
<div class="service-form service-wide vp-plan-fields" data-vp-plan>
<label class="field-label">Vehicle type<select name="vehicle_type" required><option value="">Choose type</option><?php foreach(['Car','Motorcycle','Van','SUV','Pickup','Other']as$type): ?><option<?= ($_POST['vehicle_type']??'')===$type?' selected':'' ?>><?= $type ?></option><?php endforeach; ?></select></label>
<label class="field-label">Vehicle model (optional)<input name="vehicle_model" maxlength="60" value="<?= $vpValue('vehicle_model') ?>" placeholder="e.g. Toyota Vios"></label>
<label class="field-label">Vehicle color (optional)<input name="vehicle_color" maxlength="30" value="<?= $vpValue('vehicle_color') ?>"></label>
<label class="field-label">Requested visitor slot<select name="requested_slot_id"><option value="">Any available visitor slot</option><?php foreach($vpSlots as$slot): ?><option value="<?= (int)$slot['id'] ?>"<?= (int)($_POST['requested_slot_id']??0)===(int)$slot['id']?' selected':'' ?>><?= $vpEscape($slot['slot_code']) ?></option><?php endforeach; ?></select><small>Authorized personnel confirm the slot after checking availability.</small></label>
<label class="field-label">Expected arrival<input type="datetime-local" name="expected_arrival" value="<?= $vpValue('expected_arrival') ?>" required></label>
<label class="field-label">Expected departure<input type="datetime-local" name="expected_departure" value="<?= $vpValue('expected_departure') ?>" required></label>
<p class="service-wide" data-vp-estimate aria-live="polite">Choose arrival and departure to estimate the parking fee. Times are Philippine local time.</p>
<label class="field-label service-wide" data-vp-extension-reason hidden>Reason for extended parking<textarea name="extension_reason" maxlength="1000" placeholder="e.g. Family staying overnight"></textarea><small>Your visitor's planned parking duration exceeds 24 hours. Management approval is required before this extended stay is authorized.</small></label>
<label class="checkbox service-wide"><input type="checkbox" name="parking_fee_ack" value="1" required<?= ($_POST['parking_fee_ack']??'')==='1'?' checked':'' ?>><span>I acknowledge that visitor parking costs PHP 50 per started hour. Parking beyond 24 hours requires management approval. Charges will be added to the responsible Resident/Tenant's billing account after actual vehicle checkout.</span></label>
</div>
