(() => {
    const dialog = document.createElement('dialog');
    dialog.className = 'system-confirm-dialog';
    dialog.setAttribute('aria-labelledby', 'systemConfirmTitle');
    dialog.innerHTML = `
        <div class="system-confirm-content">
            <h2 id="systemConfirmTitle">Confirm action</h2>
            <p id="systemConfirmMessage"></p>
            <div class="system-confirm-actions">
                <button type="button" class="system-confirm-cancel">Cancel</button>
                <button type="button" class="system-confirm-accept">Confirm</button>
            </div>
        </div>`;
    document.body.append(dialog);

    const message = dialog.querySelector('#systemConfirmMessage');
    const cancelButton = dialog.querySelector('.system-confirm-cancel');
    const acceptButton = dialog.querySelector('.system-confirm-accept');
    let pendingForm = null;
    let pendingSubmitter = null;

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
        if (form.dataset.confirmBypass === 'true') {
            delete form.dataset.confirmBypass;
            return;
        }

        event.preventDefault();
        pendingForm = form;
        pendingSubmitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        message.textContent = form.dataset.confirm;
        dialog.querySelector('#systemConfirmTitle').textContent = form.dataset.confirmTitle || 'Confirm action';
        acceptButton.textContent = form.dataset.confirmAction || 'Confirm';
        dialog.showModal();
        cancelButton.focus();
    });

    function closeDialog() {
        dialog.close();
        pendingForm = null;
        pendingSubmitter = null;
    }

    cancelButton.addEventListener('click', closeDialog);
    acceptButton.addEventListener('click', () => {
        if (!pendingForm) return;
        const form = pendingForm;
        const submitter = pendingSubmitter;
        closeDialog();
        form.dataset.confirmBypass = 'true';
        if (submitter) {
            form.requestSubmit(submitter);
        } else {
            form.requestSubmit();
        }
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
})();