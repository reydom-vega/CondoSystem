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
document.getElementById('no_visitor_vehicle')?.addEventListener('change', syncVisitorParkingFields);
syncVisitorParkingFields();
const serviceStartDate = document.getElementById('service_start_date');
const serviceEndDate = document.getElementById('service_end_date');
serviceStartDate?.addEventListener('change', () => {
    if (serviceEndDate) { serviceEndDate.min = serviceStartDate.value; if (serviceEndDate.value < serviceStartDate.value) serviceEndDate.value = serviceStartDate.value; }
});
document.querySelectorAll('[data-visitor-id-form]').forEach(form => {
    const upload = form.querySelector('[name="valid_id"]');
    if (!upload) return;
    const validate = () => {
        const file = upload.files[0];
        const validType = file && /\.(jpe?g|png|webp)$/i.test(file.name) && ['image/jpeg','image/png','image/webp'].includes(file.type);
        upload.setCustomValidity(!file ? 'Upload a valid ID image.' : file.size > 5 * 1024 * 1024 ? 'The ID image must be no larger than 5 MB.' : !validType ? 'Choose a JPG, PNG or WEBP image.' : '');
    };
    upload.addEventListener('change', () => { validate(); upload.reportValidity(); });
    form.addEventListener('submit', event => { validate(); if (!form.reportValidity()) event.preventDefault(); });
});
document.querySelectorAll('.visitor-identity-review').forEach(form => {
    const note = form.querySelector('[name="identity_review_note"]');
    const validateReview = decision => note.setCustomValidity(decision === 'rejected' && !note.value.trim() ? 'Give a reason for rejecting the ID.' : '');
    form.querySelectorAll('[name="identity_decision"]').forEach(button => button.addEventListener('click', () => validateReview(button.value)));
    form.addEventListener('submit', event => {
        validateReview(event.submitter?.value);
        if (!form.reportValidity()) event.preventDefault();
    });
    note.addEventListener('input', () => note.setCustomValidity(''));
});
