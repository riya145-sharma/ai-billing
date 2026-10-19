(() => {
    'use strict';
    // Confirmation for risky admin actions (no inline scripts under the CSP).
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    });
})();
