(() => {
    'use strict';
    // The tracker page a phone opens at /track. It shares the phone's GPS position with the
    // rental office while the vehicle is on rental.
    //
    // The link staff create ends in "#t=<token>". The token is kept on this phone and sent in
    // the X-Tracker-Token header of every request; it is the only thing that says which rental
    // this phone reports for. Nothing is sent until the person presses Start and the browser's
    // own location prompt is answered with Allow.
    const root = document.querySelector('[data-tracker]');
    if (!root) return;

    const TOKEN_KEY = 'tripleR.tracker.token';
    const SHARING_KEY = 'tripleR.tracker.sharing';
    const sessionUrl = root.dataset.sessionUrl;
    const reportUrl = root.dataset.reportUrl;
    const views = root.querySelectorAll('[data-tracker-view]');
    const statusBox = root.querySelector('[data-tracker-status]');
    const statusText = root.querySelector('[data-tracker-status-text]');
    const problemBox = root.querySelector('[data-tracker-problem]');
    const startButton = root.querySelector('[data-tracker-start]');
    const stopButton = root.querySelector('[data-tracker-stop]');
    const readout = root.querySelector('[data-tracker-readout]');

    let token = null;
    let intervalSeconds = 5;
    let watchId = null;
    let timer = null;
    let latest = null;       // The newest position the phone has given us.
    let lastSentAt = 0;
    let sending = false;
    let sentCount = 0;
    let wakeLock = null;

    // Storage can be switched off (private browsing); the tracker then works until the page is closed.
    const store = {
        get(key) { try { return window.localStorage.getItem(key); } catch { return null; } },
        set(key, value) { try { window.localStorage.setItem(key, value); } catch { /* kept in memory only */ } },
        remove(key) { try { window.localStorage.removeItem(key); } catch { /* nothing to remove */ } },
    };

    const show = (name) => views.forEach((view) => { view.hidden = view.dataset.trackerView !== name; });
    const field = (name, value) => {
        const node = root.querySelector(`[data-tracker-field="${name}"]`);
        if (node) node.textContent = value;
    };
    const setStatus = (state, text) => {
        statusBox.dataset.state = state;
        statusText.textContent = text;
    };
    const setProblem = (text) => {
        problemBox.textContent = text || '';
        problemBox.hidden = !text;
    };
    const number = (value) => (typeof value === 'number' && Number.isFinite(value) ? value : null);

    async function call(method, url, body) {
        const response = await fetch(url, {
            method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Tracker-Token': token || '' },
            body: body === undefined ? undefined : JSON.stringify(body),
            cache: 'no-store',
        });
        let data = {};
        try { data = await response.json(); } catch { /* an empty or broken answer is treated as no data */ }
        return { status: response.status, data };
    }

    function forgetLink(reason) {
        stopSharing();
        store.remove(TOKEN_KEY);
        token = null;
        root.querySelector('[data-tracker-unlinked-reason]').textContent = reason;
        show('unlinked');
    }

    function endRental() {
        stopSharing();
        store.remove(TOKEN_KEY);
        token = null;
        show('ended');
    }

    async function keepScreenOn() {
        // Phones stop reporting positions when the screen locks, so ask to keep it on while sharing.
        if (!('wakeLock' in navigator) || wakeLock !== null || document.visibilityState !== 'visible') return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => { wakeLock = null; });
        } catch { wakeLock = null; }
    }

    function startSharing() {
        setProblem('');
        if (!window.isSecureContext) {
            setProblem('Location sharing needs a secure (https) address. Open the tracker from the link or QR code the rental office gave you.');
            return;
        }
        if (!('geolocation' in navigator)) {
            setProblem('This browser cannot share a location. Try Chrome or Safari on your phone.');
            return;
        }
        if (watchId !== null) return;
        store.set(SHARING_KEY, '1');
        startButton.hidden = true;
        stopButton.hidden = false;
        readout.hidden = false;
        setStatus('waiting', 'Finding your position…');
        watchId = navigator.geolocation.watchPosition(onPosition, onPositionError, { enableHighAccuracy: true, maximumAge: 0, timeout: 30000 });
        timer = window.setInterval(sendIfDue, 1000);
        keepScreenOn();
    }

    function stopSharing() {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        if (timer !== null) window.clearInterval(timer);
        if (wakeLock !== null) wakeLock.release().catch(() => {});
        watchId = null;
        timer = null;
        latest = null;
        store.remove(SHARING_KEY);
        startButton.hidden = false;
        stopButton.hidden = true;
        readout.hidden = sentCount === 0;
        setStatus('idle', 'Not sharing');
    }

    function onPosition(position) {
        const coords = position.coords;
        const first = latest === null;
        latest = {
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: number(coords.accuracy),
            speed: number(coords.speed),
            heading: number(coords.heading),
        };
        field('accuracy', latest.accuracy === null ? '—' : `± ${Math.round(latest.accuracy)} m`);
        field('speed', latest.speed === null ? '—' : `${Math.round(latest.speed * 3.6)} km/h`);
        if (first) sendIfDue();
    }

    function onPositionError(error) {
        if (error.code === error.PERMISSION_DENIED) {
            stopSharing();
            setProblem('Location permission was refused. Allow location for this site in your browser’s settings, then press Start again.');
            return;
        }
        // No fix yet (indoors, GPS switched off): keep listening and say so.
        setStatus('problem', 'Waiting for a GPS position…');
        setProblem('The phone has no position yet. Check that Location is switched on, and move near a window or outdoors.');
    }

    async function sendIfDue() {
        if (latest === null || sending || Date.now() - lastSentAt < intervalSeconds * 1000) return;
        sending = true;
        lastSentAt = Date.now();
        try {
            const { status, data } = await call('POST', reportUrl, latest);
            if (status === 401) {
                forgetLink(data.error || 'This tracker link is not valid any more. Ask the rental office for a new one.');
            } else if (status !== 200) {
                setStatus('problem', 'Position not accepted');
                setProblem(data.error || 'The rental office’s system did not accept the position. It will be tried again.');
            } else if (data.state === 'ended') {
                endRental();
            } else if (data.state === 'waiting') {
                setProblem('');
                setStatus('waiting', 'Connected. Sharing starts when the pickup is recorded.');
            } else {
                sentCount += 1;
                setProblem('');
                setStatus('sharing', 'Sharing location');
                field('sent_at', new Date().toLocaleTimeString());
                field('count', String(sentCount));
            }
        } catch {
            setStatus('problem', 'No connection. Trying again…');
        } finally {
            sending = false;
        }
    }

    async function openLink() {
        // A fresh link arrives after the "#"; keep it and clear the address bar so it is not shared by accident.
        const fromLink = new URLSearchParams(window.location.hash.slice(1)).get('t');
        if (fromLink) {
            store.set(TOKEN_KEY, fromLink);
            window.history.replaceState(null, '', window.location.pathname);
        }
        token = fromLink || store.get(TOKEN_KEY);
        if (!token) {
            show('unlinked');
            return;
        }
        let answer;
        try {
            answer = await call('GET', sessionUrl);
        } catch {
            root.querySelector('[data-tracker-unlinked-reason]').textContent = 'The rental office’s system could not be reached. Check your internet connection and reload this page.';
            show('unlinked');
            return;
        }
        if (answer.status !== 200) {
            forgetLink(answer.data.error || 'This tracker link is not valid any more. Ask the rental office for a new one.');
            return;
        }
        if (answer.data.state === 'ended') {
            endRental();
            return;
        }
        intervalSeconds = Math.max(2, Number(answer.data.interval) || 5);
        field('vehicle', answer.data.vehicle);
        field('reference', answer.data.reference);
        field('due_back', answer.data.due_back || 'Not set');
        show('ready');
        // Sharing was on when the page was last open (a reload, or the phone woke up): carry on.
        if (store.get(SHARING_KEY) === '1') startSharing();
    }

    startButton.addEventListener('click', startSharing);
    stopButton.addEventListener('click', stopSharing);
    document.addEventListener('visibilitychange', () => { if (watchId !== null) keepScreenOn(); });
    // A link opened while this page is already showing only changes the part after the "#".
    window.addEventListener('hashchange', () => { if (window.location.hash.includes('t=')) openLink(); });
    openLink();
})();
