/* Sign-in and password pages: show/hide password, Caps Lock hint, password rule checklist, busy state. */
(() => {
    'use strict';

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        const name = button.dataset.passwordName || 'password';

        button.addEventListener('click', () => {
            const willShow = input.type === 'password';
            input.type = willShow ? 'text' : 'password';
            button.textContent = willShow ? 'Hide' : 'Show';
            button.setAttribute('aria-label', `${willShow ? 'Hide' : 'Show'} ${name}`);
            button.setAttribute('aria-pressed', String(willShow));
        });
    });

    // Caps Lock is the most common reason a correct password is rejected.
    document.querySelectorAll('input[type="password"][data-caps-hint]').forEach((input) => {
        const hint = document.getElementById(input.dataset.capsHint);
        if (!hint) return;
        const update = (event) => {
            if (typeof event.getModifierState === 'function') hint.hidden = !event.getModifierState('CapsLock');
        };
        input.addEventListener('keydown', update);
        input.addEventListener('keyup', update);
        input.addEventListener('blur', () => { hint.hidden = true; });
    });

    // Live checklist for the new-password rules. The server still makes the final decision.
    const newPassword = document.querySelector('[data-password-rules]');
    if (newPassword) {
        const list = document.getElementById(newPassword.dataset.passwordRules);
        const confirmation = document.getElementById(newPassword.dataset.passwordConfirm || '');
        const current = document.getElementById(newPassword.dataset.passwordCurrent || '');
        const checks = {
            length: (value) => [...value].length >= 14 && [...value].length <= 200,
            different: (value) => value !== '' && current !== null && current.value !== value,
            match: (value) => value !== '' && confirmation !== null && confirmation.value === value,
        };
        const update = () => {
            list?.querySelectorAll('[data-rule]').forEach((item) => {
                const check = checks[item.dataset.rule];
                item.classList.toggle('is-met', check ? check(newPassword.value) : false);
            });
            if (confirmation) {
                confirmation.setCustomValidity(confirmation.value !== '' && confirmation.value !== newPassword.value ? 'The two new passwords do not match.' : '');
            }
        };
        newPassword.addEventListener('input', update);
        confirmation?.addEventListener('input', update);
        current?.addEventListener('input', update);
        update();
    }

    document.querySelectorAll('[data-auth-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (!button) return;
            button.disabled = true;
            if (button.dataset.busyText) button.textContent = button.dataset.busyText;
        });
    });
})();
