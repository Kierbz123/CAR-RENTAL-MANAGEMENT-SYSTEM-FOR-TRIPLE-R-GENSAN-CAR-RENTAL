(() => {
    'use strict';
    // Shown on a customer's page while a Telegram connection code is waiting to be used:
    // watches for the customer pressing Start in Telegram. The QR code itself is drawn by qr-render.js.
    const shell = document.querySelector('[data-telegram-connect]');
    if (!shell) return;

    const wait = shell.querySelector('[data-telegram-wait]');
    const expiresAt = Number(shell.dataset.expiresAt) * 1000;
    let timer = null;

    function expire() {
        window.clearInterval(timer);
        shell.classList.add('is-expired');
        if (wait) wait.textContent = 'This code has expired. Create a new code to try again.';
    }

    async function check() {
        if (Date.now() >= expiresAt) { expire(); return; }
        if (document.hidden) return;
        try {
            const response = await fetch(shell.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const status = await response.json();
            if (status.connected) {
                window.clearInterval(timer);
                if (wait) wait.textContent = 'Connected. Updating this page…';
                window.location.reload();
            }
        } catch (error) {
            // A missed check is harmless; the next one runs in a few seconds.
        }
    }

    timer = window.setInterval(check, 3000);
    check();
})();
