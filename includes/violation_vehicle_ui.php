<?php if (canAccess('vehicles.read')): ?>
<div <?php echo violationWorkspacePanelAttributes('lookup', $activeTab); ?>>
<section class="unit-management-panel vehicle-lookup-panel" id="vehicleLookup" data-api="<?= htmlspecialchars(buildUrl('api/violation_vehicles.php'),ENT_QUOTES,'UTF-8') ?>" data-can-review="<?= canAccess('violations.review') ? '1' : '0' ?>" data-visitor-policy="<?= appSetting('CONDO_VISITOR_VIOLATION_HOST_BILLING','0')==='1' ? '1' : '0' ?>">
    <h2 class="section-title">Vehicle &amp; Parking Lookup</h2>
    <p>Search registered vehicles, visitor vehicles, or parking assignments before issuing a violation.</p>
    <form id="vehicleLookupForm" class="vehicle-lookup-controls" role="search">
        <div class="violation-field"><label for="vehicleLookupMode">Search mode</label><select id="vehicleLookupMode"><option value="plate">Search by Plate Number</option><option value="slot">Search by Parking Slot</option></select></div>
        <div class="violation-field"><label for="vehicleLookupQuery">Plate number or parking slot</label><input id="vehicleLookupQuery" type="search" maxlength="30" autocomplete="off" placeholder="Enter at least 2 starting characters" aria-describedby="vehicleLookupHint"></div>
        <button type="submit">Search</button><button type="button" class="btn-neutral" id="vehicleLookupClear">Clear</button>
    </form>
    <p id="vehicleLookupHint">Partial searches match the start of a plate or slot code. A match identifies a record; verify the incident and responsible account separately.</p>
    <p id="vehicleLookupStatus" role="status" aria-live="polite"></p>
    <div id="vehicleLookupResults" class="vehicle-lookup-results" aria-busy="false"></div>
    <button type="button" id="reportUnknownVehicle" hidden>Report Unregistered Vehicle</button>
</section>
</div>
<dialog id="violationVehicleDialog" class="vehicle-violation-dialog" aria-labelledby="violationVehicleDialogTitle">
    <div class="vehicle-dialog-heading"><h2 id="violationVehicleDialogTitle">Vehicle details</h2><button type="button" data-close-vehicle-dialog aria-label="Close vehicle details">Close</button></div>
    <div id="violationVehicleDialogBody"></div>
</dialog>
<dialog id="violationIncidentDialog" class="vehicle-violation-dialog" aria-labelledby="violationIncidentDialogTitle">
    <div class="vehicle-dialog-heading"><h2 id="violationIncidentDialogTitle">Incident details</h2><button type="button" data-close-vehicle-dialog aria-label="Close incident details">Close</button></div>
    <div id="violationIncidentDialogBody"></div>
</dialog>
<?php endif; ?>
