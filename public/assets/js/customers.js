(() => {
    'use strict';
    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
    document.querySelectorAll('[data-customer-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.textContent = 'Saving…'; }
        });
    });
    document.querySelectorAll('[data-reveal-kind]').forEach((button) => {
        button.addEventListener('click', async () => {
            const shell = document.querySelector('[data-reveal-url]');
            button.disabled = true;
            try {
                const body = new URLSearchParams({
                    _csrf: shell.dataset.csrf,
                    customer_id: new URLSearchParams(window.location.search).get('customer_id') || '',
                    kind: button.dataset.revealKind,
                    record_id: button.dataset.recordId,
                });
                const response = await fetch(shell.dataset.revealUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': shell.dataset.csrf},
                    body,
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Could not reveal this value.');
                button.previousElementSibling.textContent = result.value;
                button.textContent = 'Hidden';
            } catch (error) {
                button.textContent = error.message;
            } finally {
                button.disabled = true;
            }
        });
    });
})();
