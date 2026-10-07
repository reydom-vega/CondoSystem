(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        if (!profileMenu || !profileToggle) return;

        if (profileToggle.contains(event.target)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const isOpen = profileMenu.classList.toggle('open');
            profileToggle.setAttribute('aria-expanded', String(isOpen));
        } else if (!profileMenu.contains(event.target)) {
            profileMenu.classList.remove('open');
            profileToggle.setAttribute('aria-expanded', 'false');
        }
    }, true);
})();
