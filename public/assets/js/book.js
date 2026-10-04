/*
 * Booking page (/book). The page works without this file: the server checks the dates
 * and each vehicle shows its own amounts. With it, the return date cannot be picked
 * before the pickup date, the number of days shows as the dates are chosen, and the
 * "Your booking" panel follows the vehicle that is selected.
 */
(() => {
    'use strict';

    const DAY_MS = 86400000;
    const MAX_DAYS = 30;

    /* ---- Dates ----------------------------------------------------------- */
    const start = document.querySelector('[data-date-start]');
    const end = document.querySelector('[data-date-end]');
    const note = document.querySelector('[data-date-note]');
    const plainNote = note ? note.textContent : '';
    // Date inputs give YYYY-MM-DD; reading it as UTC keeps the arithmetic free of time zones.
    const toDate = (value) => (/^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00Z`) : null);
    const toValue = (date) => date.toISOString().slice(0, 10);

    const syncDates = () => {
        if (!start || !end) return;
        const from = toDate(start.value);
        if (from) {
            end.min = toValue(new Date(from.getTime() + DAY_MS));
            end.max = toValue(new Date(from.getTime() + MAX_DAYS * DAY_MS));
            if (end.value !== '' && end.value <= start.value) end.value = '';
        }
        const to = toDate(end.value);
        if (!note) return;
        const days = from && to ? Math.round((to - from) / DAY_MS) : 0;
        const counted = days >= 1 && days <= MAX_DAYS;
        note.textContent = counted ? `${days} ${days === 1 ? 'day' : 'days'}, returned at the same time you collect it.` : plainNote;
        note.classList.toggle('is-set', counted);
    };
    start?.addEventListener('change', () => {
        syncDates();
        // Picking the pickup date leads straight to the return date.
        if (end && end.value === '' && start.value !== '') end.focus();
    });
    end?.addEventListener('change', syncDates);
    syncDates();

    /* ---- Summary follows the selected vehicle ---------------------------- */
    const form = document.querySelector('[data-booking-form]');
    if (form) {
        const fields = ['name', 'total', 'downpayment', 'balance'];
        const show = (input) => {
            fields.forEach((field) => {
                const cell = form.querySelector(`[data-summary="${field}"]`);
                const value = input.dataset[field];
                if (!cell || value === undefined || cell.textContent === value) return;
                cell.textContent = value;
                // Restart the short "tick" animation so the change is noticed.
                cell.classList.remove('is-updated');
                void cell.offsetWidth;
                cell.classList.add('is-updated');
            });
        };
        form.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.name === 'vehicle_id') show(event.target);
        });
    }
})();
