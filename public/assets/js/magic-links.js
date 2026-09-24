(() => {
    const shell = document.querySelector('[data-redeem-url]');
    if (!shell) return;

    const button = document.querySelector('#magic-link-continue');
    const status = document.querySelector('#magic-link-status');
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const token = fragment.get('token') || '';
    const purpose = fragment.get('purpose') || '';
    window.history.replaceState(null, '', window.location.pathname);

    if (!/^[A-Za-z0-9_-]{43}$/.test(token) || !/^[a-z_]{1,64}$/.test(purpose)) {
        status.textContent = 'This link is incomplete. Ask the rental office to send a new link.';
        return;
    }

    status.textContent = 'Select Continue to verify this one-time link.';
    button.disabled = false;
    button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Verifying your link…';
        try {
            const response = await fetch(shell.dataset.redeemUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ token, purpose })
            });
            const result = await response.json();
            if (!response.ok || result.verified !== true) {
                throw new Error(result.error || 'This link is invalid or expired. Ask the rental office to send a new link.');
            }
            const sessionResponse = await fetch(`/api/magic-links/session?purpose=${encodeURIComponent(result.purpose)}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const session = await sessionResponse.json();
            if (!sessionResponse.ok || session.verified !== true) {
                throw new Error(session.error || 'Secure-link access could not be established. Request a new link.');
            }
            status.textContent = 'Your secure link has been verified. Purpose-bound access is active in this browser session.';
            button.hidden = true;
        } catch (error) {
            status.textContent = error.message || 'This link could not be verified. Ask the rental office to send a new link.';
            button.hidden = true;
        }
    });
})();
