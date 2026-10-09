/* Rental pages: odometer fallback for pickup/return, and the new-reservation form. */
(() => {
    'use strict';

    // Pickup and return need an odometer reading. The agreement page has a field for it;
    // this prompt only covers a form that was rendered without one.
    document.querySelectorAll('form[action="/rentals/action"]').forEach((form) => {
        const action = form.querySelector('[name="action"]')?.value;
        if (!['pickup', 'return'].includes(action)) return;
        form.addEventListener('submit', (event) => {
            if (form.querySelector('[name="mileage"]')) return;
            const raw = window.prompt(`Enter the ${action} odometer reading in whole kilometers`);
            if (raw === null || !/^\d{1,10}$/.test(raw) || Number(raw) > 4294967295) {
                event.preventDefault();
                if (raw !== null) window.alert('Enter a valid whole-kilometer reading.');
                return;
            }
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'mileage';
            input.value = raw;
            form.append(input);
        });
    });

    // Tax is worked out by the server; choosing it fills in the amount and locks both fields.
    document.querySelectorAll('[data-charge-form]').forEach((chargeForm) => {
        const type = chargeForm.querySelector('[name="charge_type"]');
        const amount = chargeForm.querySelector('[name="amount"]');
        const description = chargeForm.querySelector('[name="description"]');
        if (!type || !amount || !description) return;
        const sync = () => {
            const tax = type.value === 'tax';
            if (tax || amount.readOnly) {
                amount.value = tax ? chargeForm.dataset.taxAmount : '';
                description.value = tax ? chargeForm.dataset.taxDescription : '';
            }
            amount.readOnly = tax;
            description.readOnly = tax;
        };
        type.addEventListener('change', sync);
        sync();
    });

    const form = document.querySelector('form[action="/rentals/reserve"]');
    if (!form) return;

    /* ---- Driver picker: only relevant to chauffeur rentals ---------------- */
    const typeSelect = form.querySelector('[data-rental-type]');
    const driverPicker = form.querySelector('[data-driver-picker]');
    const syncDriverPicker = () => {
        if (!typeSelect || !driverPicker) return;
        const chauffeur = typeSelect.value === 'chauffeur';
        driverPicker.hidden = !chauffeur;
        const select = driverPicker.querySelector('select');
        if (!chauffeur && select) select.value = '';
    };
    typeSelect?.addEventListener('change', syncDriverPicker);
    syncDriverPicker();

    /* ---- Live summary ------------------------------------------------------ */
    const money = (value) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const summary = (name) => document.querySelector(`[data-summary="${name}"]`);
    const vehicle = form.querySelector('[data-vehicle]');
    const start = form.querySelector('[data-start-date]');
    const end = form.querySelector('[data-end-date]');
    const deposit = form.querySelector('[data-deposit]');

    const updateSummary = () => {
        const rate = Number(vehicle?.selectedOptions[0]?.dataset.rate);
        const hasRate = Number.isFinite(rate) && vehicle.value !== '';
        let days = null;
        if (start?.value && end?.value) {
            const difference = Math.round((Date.parse(`${end.value}T00:00:00Z`) - Date.parse(`${start.value}T00:00:00Z`)) / 86400000);
            if (Number.isFinite(difference) && difference >= 0) days = Math.max(1, difference);
        }
        if (start?.value && end) end.min = start.value;
        const set = (name, text) => { const node = summary(name); if (node) node.textContent = text; };
        set('rate', hasRate ? `${money(rate)} / day` : '—');
        set('days', days === null ? '—' : String(days));
        set('base', hasRate && days !== null ? money(rate * days) : '—');
        const depositValue = Number(deposit?.value);
        set('deposit', deposit?.value !== '' && Number.isFinite(depositValue) ? money(depositValue) : '—');
    };
    [vehicle, start, end, deposit].forEach((control) => {
        control?.addEventListener('input', updateSummary);
        control?.addEventListener('change', updateSummary);
    });
    updateSummary();

    /* ---- Submit through the API so errors show without losing the form ---- */
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        const error = form.querySelector('[data-form-error]');
        const fail = (message) => {
            if (error) {
                error.textContent = message;
                error.hidden = false;
                error.scrollIntoView({ block: 'nearest' });
            }
            button.disabled = false;
            button.textContent = 'Create reservation';
        };
        button.disabled = true;
        button.textContent = 'Saving…';
        if (error) error.hidden = true;
        try {
            const values = Object.fromEntries(new FormData(form).entries());
            const response = await fetch('/api/rentals', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': values._csrf },
                body: JSON.stringify(values),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The reservation could not be created.');
            window.location.assign(`/rentals/detail?agreement_id=${encodeURIComponent(result.agreement_id)}`);
        } catch (failure) {
            fail(failure.message || 'The reservation could not be created.');
        }
    });
})();
