(() => {
    'use strict';
    const form = document.getElementById('loginForm');
    if (!form || form.dataset.loginReady) return;
    form.dataset.loginReady = 'true';
    const identifier = document.getElementById('identifier');
    const password = document.getElementById('password');
    const remember = document.getElementById('rememberMe');
    const feedback = document.getElementById('loginFeedback');
    const submit = form.querySelector('button[type=submit]');
    const identifierKey = 'celandine_remembered_identifier';
    const preferenceKey = 'celandine_remember_me';

    // Only the identifier and checkbox preference belong in site storage.
    try {
        if (form.dataset.submitted !== 'true') {
            const preference = localStorage.getItem(preferenceKey);
            if (preference !== null) remember.checked = preference === '1';
            if (remember.checked && !identifier.value) identifier.value = localStorage.getItem(identifierKey) || '';
        }
    } catch { /* Browser password autofill works even when site storage is blocked. */ }
    function rememberIdentifier() {
        try {
            localStorage.setItem(preferenceKey, remember.checked ? '1' : '0');
            if (remember.checked && identifier.value.trim()) localStorage.setItem(identifierKey, identifier.value.trim());
            else localStorage.removeItem(identifierKey);
        } catch { /* Private/storage-restricted browsing must still allow login. */ }
    }
    remember.addEventListener('change', rememberIdentifier);

    const toggle = form.querySelector('.toggle-password');
    const icon = toggle.querySelector('svg');
    const eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    const eyeClosed = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path>';
    function updateToggle() {
        toggle.classList.toggle('is-visible', password.value.length > 0);
        toggle.setAttribute('aria-label', password.type === 'password' ? 'Show password' : 'Hide password');
        icon.innerHTML = password.type === 'password' ? eyeClosed : eyeOpen;
    }
    updateToggle();
    password.addEventListener('input', updateToggle);
    password.addEventListener('change', updateToggle);
    window.addEventListener('pageshow', updateToggle);
    toggle.addEventListener('click', () => {
        password.type = password.type === 'password' ? 'text' : 'password';
        updateToggle();
        password.focus();
    });

    function showError(message) {
        feedback.classList.add('error');
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.focus();
    }
    let submitting = false;
    form.addEventListener('submit', async event => {
        if (submitting) { event.preventDefault(); return; }
        rememberIdentifier();
        // Keep the native password-manager form recognizable, even after Show password.
        password.type = 'password';
        updateToggle();
        if (!remember.checked || !window.isSecureContext || typeof window.PasswordCredential !== 'function'
            || typeof navigator.credentials?.store !== 'function' || typeof window.fetch !== 'function') return;

        let credential;
        try { credential = new PasswordCredential(form); }
        catch { return; } // Unsupported browsers retain ordinary form submission/autofill.
        event.preventDefault();
        submitting = true;
        const originalLabel = submit.textContent;
        submit.disabled = true;
        submit.textContent = 'Signing in…';
        form.setAttribute('aria-busy', 'true');
        feedback.hidden = true;
        feedback.classList.remove('error');
        let navigating = false;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: {Accept: 'application/json'}, redirect: 'error'
            });
            if (!response.headers.get('content-type')?.includes('application/json')) {
                throw new Error(response.status === 403
                    ? 'Your session expired. Refresh this page and try again.'
                    : 'Unable to sign in right now. Please try again.');
            }
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors?.[0] || 'Unable to sign in. Please try again.');
            const destination = new URL(result.redirect, location.href);
            if (destination.origin !== location.origin) throw new Error('Unable to open your dashboard. Refresh this page and try again.');
            if (result.save_credentials === true) {
                // Ask the browser only after the server has verified this exact password.
                // Cancelling/blocking the browser's save prompt must not cancel login.
                try { await navigator.credentials.store(credential); } catch {}
            }
            navigating = true;
            location.assign(destination.href);
        } catch (error) {
            showError(error instanceof TypeError ? 'Unable to connect. Check your connection and try again.' : error.message);
        } finally {
            credential = null;
            if (!navigating) {
                submitting = false;
                submit.disabled = false;
                submit.textContent = originalLabel;
                form.removeAttribute('aria-busy');
            }
        }
    });
})();
