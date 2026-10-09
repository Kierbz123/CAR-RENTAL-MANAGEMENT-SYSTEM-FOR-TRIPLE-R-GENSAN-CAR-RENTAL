(() => {
    const shell = document.querySelector('[data-api-url]');
    if (!shell) return;

    const rows = document.querySelector('#notification-rows');
    const errorBox = document.querySelector('#load-error');
    const count = document.querySelector('#monthly-count');
    const updated = document.querySelector('#last-updated');
    const queued = document.querySelector('#queued-count');
    const failed = document.querySelector('#failed-count');
    // Stored in UTC; shown in Manila time like the rest of the workspace.
    const when = (value) => {
        const date = new Date(String(value ?? '').replace(' ', 'T').slice(0, 19) + 'Z');
        return Number.isNaN(date.getTime()) ? String(value ?? '') : date.toLocaleString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    // "magic_link.booking_manage" reads as "Magic link booking manage".
    const words = (key) => {
        const text = String(key ?? '').replace(/[._]+/g, ' ').trim();
        return text.charAt(0).toUpperCase() + text.slice(1);
    };
    const tones = { sent: 'success', failed: 'danger', queued: 'info', sending: 'info' };
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
            const data = await response.json();
            if (response.status === 403 && data.code === 'password_change_required') {
                window.location.assign('/auth/change-password');
                return;
            }
            if (!response.ok) throw new Error('Could not load notification history.');
            const items = Array.isArray(data.notifications) ? data.notifications : [];
            rows.innerHTML = items.length ? items.map((item) => `<tr>
                <td class="nowrap">${escapeHtml(when(item.created_at))}</td>
                <td class="nowrap"><span class="mono">${escapeHtml(item.recipient_phone)}</span><span class="cell-sub">${item.channel === 'telegram' ? 'Telegram' : 'SMS'} · ${escapeHtml(words(item.provider))}</span></td>
                <td>${escapeHtml(words(item.template_key))}<span class="cell-sub">${escapeHtml(item.message_preview)}</span></td>
                <td><span class="badge badge-${tones[item.status] || 'neutral'}">${escapeHtml(words(item.status))}</span>${item.provider_status ? `<span class="cell-sub">${escapeHtml(item.provider_status)}</span>` : ''}</td>
                <td class="num">${escapeHtml(item.attempt_count)}</td>
                <td>${escapeHtml(item.last_error || '—')}</td></tr>`).join('') : '<tr><td class="empty-state" colspan="6"><strong>No notifications yet</strong>Messages appear here as they are queued.</td></tr>';
            count.textContent = String(data.monthly_sent_count ?? 0);
            queued.textContent = String(items.filter((item) => item.status === 'queued' || item.status === 'sending').length);
            failed.textContent = String(items.filter((item) => item.status === 'failed').length);
            updated.textContent = `Updated ${new Date().toLocaleTimeString()}`;
            errorBox.hidden = true;
        } catch (error) {
            errorBox.textContent = error.message || 'Could not load notification history.';
            errorBox.hidden = false;
            if (!rows.children.length) rows.innerHTML = '<tr><td class="empty-state" colspan="6">History is temporarily unavailable.</td></tr>';
        }
    }

    refresh();
    window.setInterval(refresh, 30000);
})();
