(() => {
    const shell = document.querySelector('[data-api-url]');
    if (!shell) return;

    const rows = document.querySelector('#notification-rows');
    const errorBox = document.querySelector('#load-error');
    const count = document.querySelector('#monthly-count');
    const updated = document.querySelector('#last-updated');
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);

    async function refresh() {
        try {
            const response = await fetch(shell.dataset.apiUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (response.status === 401) {
                window.location.assign('/staff/login');
                return;
            }
            if (!response.ok) throw new Error('Could not load notification history.');
            const data = await response.json();
            const items = Array.isArray(data.notifications) ? data.notifications : [];
            rows.innerHTML = items.length ? items.map((item) => `<tr>
                <td>${escapeHtml(item.created_at)}</td><td>${escapeHtml(item.recipient_phone)}</td>
                <td>${escapeHtml(item.template_key)}</td><td>${escapeHtml(item.message_class)}</td>
                <td>${escapeHtml(item.status)}${item.provider_status ? ` · ${escapeHtml(item.provider_status)}` : ''}</td>
                <td>${escapeHtml(item.priority)}</td><td>${escapeHtml(item.attempt_count)} (${escapeHtml(item.retry_count)} retries)</td>
                <td>${escapeHtml(item.provider)}</td><td>${escapeHtml(item.last_error || '—')}</td></tr>`).join('') : '<tr><td colspan="9">No notifications have been queued.</td></tr>';
            count.textContent = String(data.monthly_sent_count ?? 0);
            updated.textContent = `Updated ${new Date().toLocaleTimeString()}`;
            errorBox.hidden = true;
        } catch (error) {
            errorBox.textContent = error.message || 'Could not load notification history.';
            errorBox.hidden = false;
            if (!rows.children.length) rows.innerHTML = '<tr><td colspan="9">History is temporarily unavailable.</td></tr>';
        }
    }

    refresh();
    window.setInterval(refresh, 30000);
})();
