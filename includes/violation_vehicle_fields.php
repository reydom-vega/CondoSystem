<input type="hidden" name="registration_source" value="">
<input type="hidden" name="related_record_id" value="0">
<input type="hidden" name="vehicle_parking_slot_id" value="0">
<div id="vehicleIncidentFields" hidden>
    <div class="vehicle-selection-heading">
        <p id="selectedVehicleIdentity"></p>
        <button type="button" id="clearSelectedVehicle" class="btn-neutral">Clear vehicle</button>
    </div>
    <p class="vehicle-report-notice">This report will be Pending Review. Management must verify responsibility and approve it before any charge is added.</p>
    <div class="violation-form-grid">
        <div class="violation-field"><label for="observedVehiclePlate">Observed Plate Number</label><input id="observedVehiclePlate" name="observed_plate" maxlength="20" disabled></div>
        <div class="violation-field"><label for="vehicleReportPenalty">Proposed Action</label><select id="vehicleReportPenalty" disabled><option value="fine">Fine</option><option value="warning">Warning</option></select></div>
        <div class="violation-field vehicle-incident-time"><label for="vehicleIncidentAt">Incident Date and Time</label><input id="vehicleIncidentAt" name="incident_at" type="datetime-local" disabled><small>Confirm when the incident happened.</small></div>
        <div class="violation-field" id="unknownParkingSlotField" hidden><label for="unknownParkingSlot">Observed Parking Slot (optional)</label><select id="unknownParkingSlot"><option value="0">No slot selected</option><?php foreach (getParkingSlots($connection) as $lookupSlot): ?><option value="<?= (int)$lookupSlot['id'] ?>"><?= htmlspecialchars($lookupSlot['slot_code'].' · '.$lookupSlot['level'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div>
    </div>
</div>
