// Navigation is handled by assets/js/ui-components.js.
const visitorNeedsParking = document.getElementById('needs_parking');
const visitorParkingFields = document.getElementById('visitorParkingFields');
function syncVisitorParkingFields() {
    if (!visitorNeedsParking || !visitorParkingFields) return;
    visitorParkingFields.hidden = !visitorNeedsParking.checked;
    visitorParkingFields.disabled = !visitorNeedsParking.checked;
    visitorNeedsParking.setAttribute('aria-expanded', String(visitorNeedsParking.checked));
}
visitorNeedsParking?.addEventListener('change', syncVisitorParkingFields);
syncVisitorParkingFields();
const serviceStartDate = document.getElementById('service_start_date');
const serviceEndDate = document.getElementById('service_end_date');
serviceStartDate?.addEventListener('change', () => {
    if (serviceEndDate) { serviceEndDate.min = serviceStartDate.value; if (serviceEndDate.value < serviceStartDate.value) serviceEndDate.value = serviceStartDate.value; }
});
