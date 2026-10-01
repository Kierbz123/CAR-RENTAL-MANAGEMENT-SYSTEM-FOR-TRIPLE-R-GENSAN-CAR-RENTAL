(() => {
    'use strict';
    // Confirmation prompts for [data-confirm] forms are handled once, in app-shell.js.
    document.querySelectorAll('[data-vehicle-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.textContent = 'Saving…'; }
        });
    });
})();
