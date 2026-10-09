/*
 * Staff workspace behaviour. The sidebar, breadcrumb and page are rendered on the
 * server; this file only adds conveniences on top, so every page still works
 * with scripts turned off (links, forms and plain posts).
 */
(() => {
    'use strict';

    const root = document.documentElement;
    root.classList.add('js');
    const body = document.body;

    /* ---- Phone drawer -------------------------------------------------- */
    const sidebar = document.getElementById('app-sidebar');
    const openButton = document.querySelector('[data-drawer-open]');
    const closeLink = document.querySelector('[data-drawer-close]');
    const drawerQuery = window.matchMedia('(max-width: 900px)');
    let lastFocus = null;

    const focusable = (container) => [...container.querySelectorAll('a[href], button:not([disabled]), select, input:not([type="hidden"]), textarea, [tabindex]:not([tabindex="-1"])')]
        .filter((element) => element.offsetParent !== null);

    const setDrawer = (open) => {
        if (!sidebar || !openButton) return;
        body.classList.toggle('drawer-open', open);
        openButton.setAttribute('aria-expanded', String(open));
        if (open) {
            lastFocus = document.activeElement;
            focusable(sidebar)[0]?.focus();
        } else if (lastFocus instanceof HTMLElement) {
            lastFocus.focus();
            lastFocus = null;
        }
    };

    openButton?.addEventListener('click', (event) => {
        event.preventDefault();
        setDrawer(!body.classList.contains('drawer-open'));
    });
    closeLink?.addEventListener('click', (event) => {
        event.preventDefault();
        setDrawer(false);
    });
    drawerQuery.addEventListener('change', () => setDrawer(false));
    sidebar?.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || !body.classList.contains('drawer-open')) return;
        const items = focusable(sidebar);
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    /* ---- Confirmation dialog ------------------------------------------- */
    const confirmMessage = (form) => {
        if (form.dataset.confirm) return form.dataset.confirm;
        const action = form.getAttribute('action') || '';
        if (action === '/customers/blacklist') return 'Blacklist this customer? Future bookings will be blocked.';
        if (action === '/rentals/driver/remove') return 'Remove this driver assignment? The reservation stays, and needs a new driver before it can be confirmed.';
        if (action === '/rentals/action') {
            const value = form.querySelector('[name="action"]')?.value;
            if (value === 'cancel') return 'Cancel this rental agreement? This cannot be undone.';
            if (value === 'no_show') return 'Mark this rental as a no-show? This cannot be undone.';
        }
        return '';
    };

    const closeDialog = (backdrop, restore) => {
        backdrop.remove();
        if (restore instanceof HTMLElement) restore.focus();
    };

    const openConfirm = (form, message, trigger) => {
        const tone = form.dataset.confirmTone === 'neutral' ? 'button-primary' : 'button-danger';
        const backdrop = document.createElement('div');
        backdrop.className = 'app-dialog-backdrop';
        const dialog = document.createElement('section');
        dialog.className = 'app-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'app-dialog-title');
        dialog.setAttribute('aria-describedby', 'app-dialog-message');
        const title = document.createElement('h2');
        title.id = 'app-dialog-title';
        title.textContent = 'Please confirm';
        const text = document.createElement('p');
        text.id = 'app-dialog-message';
        text.textContent = message;
        const actions = document.createElement('div');
        actions.className = 'app-dialog-actions';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'button button-secondary';
        cancel.textContent = 'Keep as is';
        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = `button ${tone}`;
        confirm.textContent = form.dataset.confirmAction || 'Yes, continue';
        actions.append(cancel, confirm);
        dialog.append(title, text, actions);
        backdrop.append(dialog);
        body.append(backdrop);

        const close = () => closeDialog(backdrop, trigger);
        cancel.addEventListener('click', close);
        backdrop.addEventListener('click', (event) => { if (event.target === backdrop) close(); });
        backdrop.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key !== 'Tab') return;
            if (event.shiftKey && document.activeElement === cancel) { event.preventDefault(); confirm.focus(); }
            else if (!event.shiftKey && document.activeElement === confirm) { event.preventDefault(); cancel.focus(); }
        });
        confirm.addEventListener('click', () => {
            form.dataset.confirmed = 'true';
            backdrop.remove();
            form.requestSubmit(form.querySelector('[type="submit"], button:not([type])') ?? undefined);
        });
        cancel.focus();
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.confirmed !== 'true') {
            const message = confirmMessage(form);
            if (message) {
                event.preventDefault();
                event.stopImmediatePropagation();
                openConfirm(form, message, document.activeElement instanceof HTMLElement ? document.activeElement : null);
                return;
            }
        }
        // Show that the request is on its way and prevent a double submit.
        if (form.method.toLowerCase() === 'post' && !event.defaultPrevented) {
            const button = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button[type="submit"], button:not([type])');
            if (button && form.dataset.busy !== 'off') {
                window.setTimeout(() => {
                    if (event.defaultPrevented) return;
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                }, 0);
            }
        }
    }, true);

    /* ---- Toasts for one-off success messages --------------------------- */
    const toastRegion = () => {
        let region = document.querySelector('.app-toast-region');
        if (!region) {
            region = document.createElement('div');
            region.className = 'app-toast-region';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            body.append(region);
        }
        return region;
    };

    const showToast = (message) => {
        const toast = document.createElement('div');
        toast.className = 'app-toast';
        toast.textContent = message;
        toastRegion().append(toast);
        const dismiss = () => {
            toast.classList.add('is-exiting');
            window.setTimeout(() => toast.remove(), 140);
        };
        const timer = window.setTimeout(dismiss, 6000);
        toast.addEventListener('click', () => { window.clearTimeout(timer); dismiss(); });
    };
    window.appToast = showToast;

    document.querySelectorAll('.notice[data-toast]').forEach((notice) => {
        const message = notice.textContent.trim();
        if (!message) return;
        notice.remove();
        showToast(message);
    });

    /* ---- A line across the top while the next page loads ------------------ */
    const progress = document.createElement('div');
    progress.className = 'app-progress';
    progress.setAttribute('aria-hidden', 'true');
    body.append(progress);
    let leavingTimer = 0;
    const setLeaving = (on) => {
        window.clearTimeout(leavingTimer);
        root.classList.toggle('is-leaving', on);
        // A download or a refused navigation never leaves the page, so the line clears itself.
        if (on) leavingTimer = window.setTimeout(() => setLeaving(false), 8000);
    };
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, window.location.href);
        const samePage = url.pathname === window.location.pathname && url.search === window.location.search;
        if (url.origin !== window.location.origin || (samePage && url.hash !== '')) return;
        setLeaving(true);
    });
    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented && event.target instanceof HTMLFormElement && event.target.target !== '_blank') setLeaving(true);
    });
    // Coming back with the Back button restores the page as it was left; clear the line.
    window.addEventListener('pageshow', () => setLeaving(false));

    /* ---- Take a photo ---------------------------------------------------- */
    // "Take photo" beside a photo field opens the camera of the computer or phone. The picture
    // taken goes into that field as if the file had been chosen, and the form is sent.
    const openCamera = async (button) => {
        const form = button.form;
        const input = form?.querySelector('input[type="file"]');
        if (!input) return;
        if (!navigator.mediaDevices?.getUserMedia) {
            // No live camera for this page (an old browser, or a page not opened over https).
            // A phone still opens its own camera app from the file field; a computer shows its file window.
            input.setAttribute('capture', 'environment');
            input.click();
            input.removeAttribute('capture');
            return;
        }

        const make = (tag, className, text) => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (text) element.textContent = text;
            return element;
        };
        const backdrop = make('div', 'app-dialog-backdrop');
        const dialog = make('section', 'app-dialog app-dialog--camera');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'camera-title');
        const title = make('h2', '', 'Take a photo');
        title.id = 'camera-title';
        const status = make('p', '', 'Starting the camera. Your browser may ask for permission.');
        status.setAttribute('role', 'status');
        const video = make('video', 'camera-view');
        video.autoplay = true;
        video.muted = true;
        video.playsInline = true;
        const still = make('canvas', 'camera-view');
        still.hidden = true;
        const actions = make('div', 'app-dialog-actions');
        const cancel = make('button', 'button button-secondary', 'Cancel');
        const retake = make('button', 'button button-secondary', 'Retake');
        const shoot = make('button', 'button button-primary', 'Take photo');
        const use = make('button', 'button button-primary', 'Use this photo');
        [cancel, retake, shoot, use].forEach((control) => { control.type = 'button'; });
        retake.hidden = true;
        use.hidden = true;
        shoot.disabled = true;
        actions.append(cancel, retake, shoot, use);
        dialog.append(title, status, video, still, actions);
        backdrop.append(dialog);
        body.append(backdrop);
        cancel.focus();

        let stream = null;
        const close = () => {
            stream?.getTracks().forEach((track) => track.stop());
            closeDialog(backdrop, button);
        };
        // Shows the live picture, or the picture just taken, with the buttons that go with it.
        const show = (taken) => {
            video.hidden = taken;
            still.hidden = !taken;
            shoot.hidden = taken;
            retake.hidden = !taken;
            use.hidden = !taken;
            (taken ? use : shoot).focus();
        };
        cancel.addEventListener('click', close);
        backdrop.addEventListener('click', (event) => { if (event.target === backdrop) close(); });
        backdrop.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key !== 'Tab') return;
            const controls = [...actions.querySelectorAll('button:not([hidden]):not(:disabled)')];
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });
        shoot.addEventListener('click', () => {
            still.width = video.videoWidth;
            still.height = video.videoHeight;
            still.getContext('2d').drawImage(video, 0, 0);
            show(true);
        });
        retake.addEventListener('click', () => show(false));
        use.addEventListener('click', () => {
            still.toBlob((blob) => {
                if (!blob) { status.textContent = 'The photo could not be saved. Try again.'; return; }
                const files = new DataTransfer();
                files.items.add(new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' }));
                input.files = files.files;
                close();
                form.requestSubmit(form.querySelector('[type="submit"]') ?? undefined);
            }, 'image/jpeg', 0.9);
        });

        try {
            // The back camera on a phone; a computer has one camera and uses that.
            stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1600 } } });
            if (!backdrop.isConnected) { stream.getTracks().forEach((track) => track.stop()); return; }
            video.srcObject = stream;
            status.textContent = 'Hold steady, then choose Take photo.';
            shoot.disabled = false;
            shoot.focus();
        } catch (error) {
            status.textContent = error.name === 'NotAllowedError'
                ? 'The camera is blocked for this site. Allow it from the address bar, then try again, or choose a file instead.'
                : 'No camera was found on this device. Choose a file instead.';
        }
    };
    document.querySelectorAll('[data-take-photo]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => openCamera(button));
    });

    /* ---- Lists ----------------------------------------------------------- */
    // Filters apply as soon as a choice is made; the Apply button remains for use without scripts.
    document.querySelectorAll('[data-auto-submit]').forEach((control) => {
        control.addEventListener('change', () => control.form?.requestSubmit());
    });

    // A whole row opens its record. The visible link in the row stays for keyboard users.
    document.addEventListener('click', (event) => {
        const row = event.target instanceof Element ? event.target.closest('tr[data-href]') : null;
        if (!row || event.target.closest('a, button, input, select, textarea, label, summary, details')) return;
        if (window.getSelection()?.toString()) return;
        setLeaving(true);
        window.location.assign(row.dataset.href);
    });

    // Copy column names onto cells so tables can turn into stacked cards on phones.
    const labelTable = (table) => {
        const headers = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
        table.querySelectorAll('tbody tr').forEach((row) => {
            [...row.children].forEach((cell, index) => {
                if (!cell.hasAttribute('data-label')) cell.setAttribute('data-label', headers[index] ?? '');
            });
        });
    };
    document.querySelectorAll('table[data-stack]').forEach((table) => {
        labelTable(table);
        const tbody = table.tBodies[0];
        if (tbody) new MutationObserver(() => labelTable(table)).observe(tbody, { childList: true });
    });

    /* ---- Small touches ---------------------------------------------------- */
    document.querySelectorAll('[data-status-changed]').forEach((badge) => {
        badge.classList.add('badge-status-morph');
        badge.addEventListener('animationend', () => badge.classList.remove('badge-status-morph'), { once: true });
    });

    // Dashboard numbers count up once on arrival. The real number is written at the end
    // whatever happens to the animation, so a figure is never left half-counted.
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('[data-count-up]').forEach((element) => {
        const target = Number(element.textContent.trim());
        if (reducedMotion || !Number.isInteger(target) || target < 2) return;
        const DURATION = 700;
        let startedAt = 0;
        let settled = false;
        const step = (now) => {
            if (settled) return;
            startedAt = startedAt || now;
            const t = Math.min(1, (now - startedAt) / DURATION);
            element.textContent = String(Math.round(target * (1 - (1 - t) ** 3)));
            if (t < 1) window.requestAnimationFrame(step);
        };
        window.requestAnimationFrame(step);
        window.setTimeout(() => {
            settled = true;
            element.textContent = String(target);
        }, DURATION + 400);
    });

    document.querySelectorAll('[data-print]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => window.print());
    });
})();
