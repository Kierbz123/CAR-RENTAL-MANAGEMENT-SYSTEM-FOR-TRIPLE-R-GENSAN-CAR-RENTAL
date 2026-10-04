(() => {
    'use strict';
    // The live map on /fleet/locations.
    //
    // It is this site's own small map, not a library: the map pictures ("tiles") are ordinary
    // 256-pixel images laid side by side, and each vehicle is a button moved with a transform.
    // Every few seconds it asks the server for the latest positions and glides each marker from
    // where it was to where it is, so a vehicle is never shown jumping.
    //
    // Styles are set through element.style, which the page's security policy allows; no markup
    // with inline styles is ever written.
    const root = document.querySelector('[data-fleet-map]');
    if (!root) return;

    const config = JSON.parse(root.dataset.fleetMap);
    const tilesLayer = root.querySelector('[data-map-tiles]');
    const overlay = root.querySelector('[data-map-overlay]');
    const markersLayer = root.querySelector('[data-map-markers]');
    const list = document.querySelector('[data-map-list]');
    const countBadge = document.querySelector('[data-map-count]');
    const updatedNote = document.querySelector('[data-map-updated]');

    const TILE = 256;
    const MIN_ZOOM = 5;
    const MAX_ZOOM = 18;
    const SVG = 'http://www.w3.org/2000/svg';
    const home = { lat: config.center.latitude, lng: config.center.longitude };

    let zoom = config.zoom;
    let center = { ...home };
    let width = 0;
    let height = 0;
    let drawQueued = false;
    let selected = null;      // The agreement whose vehicle is highlighted.
    let following = false;    // Keep the selected vehicle in the middle while it moves.
    let fittedOnce = false;
    let animating = false;
    const tiles = new Map();     // "zoom/x/y@column" -> <img>
    const vehicles = new Map();  // agreement id -> { data, marker, arrow, trailLine, shown, from, to, startedAt, trail }

    /* ---- Web Mercator: the projection every web map uses ---------------------------- */
    const worldSize = (z) => TILE * 2 ** z;
    function project(lat, lng, z) {
        const sine = Math.min(Math.max(Math.sin(lat * Math.PI / 180), -0.9999), 0.9999);
        return { x: (lng + 180) / 360 * worldSize(z), y: (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * worldSize(z) };
    }
    function unproject(x, y, z) {
        const n = Math.PI - 2 * Math.PI * y / worldSize(z);
        return { lat: 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))), lng: x / worldSize(z) * 360 - 180 };
    }
    const metresPerPixel = (lat, z) => 156543.03392 * Math.cos(lat * Math.PI / 180) / 2 ** z;
    function metresBetween(a, b) {
        const toRad = Math.PI / 180;
        const h = Math.sin((b.lat - a.lat) * toRad / 2) ** 2 + Math.cos(a.lat * toRad) * Math.cos(b.lat * toRad) * Math.sin((b.lng - a.lng) * toRad / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    /* ---- Drawing ----------------------------------------------------------------------- */
    const area = document.createElementNS(SVG, 'circle');
    area.setAttribute('class', 'fleet-map-area');
    overlay.append(area);

    // Redraws on the next frame. A tab in the background gets no frames, so a timer is the fallback.
    function requestDraw() {
        if (drawQueued) return;
        drawQueued = true;
        const run = () => {
            if (!drawQueued) return;
            drawQueued = false;
            draw();
        };
        window.requestAnimationFrame(run);
        window.setTimeout(run, 80);
    }

    function draw() {
        width = root.clientWidth;
        height = root.clientHeight;
        const middle = project(center.lat, center.lng, zoom);
        const left = middle.x - width / 2;
        const top = middle.y - height / 2;

        // Tiles: make the ones now in view, move them into place, drop the rest.
        const perSide = 2 ** zoom;
        const wanted = new Set();
        for (let column = Math.floor(left / TILE); column * TILE < left + width; column += 1) {
            for (let row = Math.floor(top / TILE); row * TILE < top + height; row += 1) {
                if (row < 0 || row >= perSide) continue;
                const x = ((column % perSide) + perSide) % perSide;
                const key = `${zoom}/${x}/${row}@${column}`;
                wanted.add(key);
                let image = tiles.get(key);
                if (!image) {
                    image = new Image();
                    image.className = 'fleet-map-tile';
                    image.alt = '';
                    image.draggable = false;
                    image.decoding = 'async';
                    // The tile server asks to be told which site is using it; only the origin is sent.
                    image.referrerPolicy = 'strict-origin-when-cross-origin';
                    image.addEventListener('error', () => image.classList.add('is-missing'));
                    image.src = config.tiles.replace('{z}', zoom).replace('{x}', x).replace('{y}', row);
                    tiles.set(key, image);
                    tilesLayer.append(image);
                }
                image.style.transform = `translate(${Math.round(column * TILE - left)}px, ${Math.round(row * TILE - top)}px)`;
            }
        }
        for (const [key, image] of tiles) {
            if (!wanted.has(key)) {
                image.remove();
                tiles.delete(key);
            }
        }

        // The service area: a circle around the office.
        const hub = project(home.lat, home.lng, zoom);
        area.setAttribute('cx', String(hub.x - left));
        area.setAttribute('cy', String(hub.y - top));
        area.setAttribute('r', String(config.radiusKm * 1000 / metresPerPixel(home.lat, zoom)));

        // Vehicles and the line of where each has been since this page was opened.
        for (const vehicle of vehicles.values()) {
            if (!vehicle.shown) {
                vehicle.marker.hidden = true;
                vehicle.trailLine.setAttribute('points', '');
                continue;
            }
            const point = project(vehicle.shown.lat, vehicle.shown.lng, zoom);
            vehicle.marker.hidden = false;
            vehicle.marker.style.transform = `translate(${Math.round(point.x - left)}px, ${Math.round(point.y - top)}px)`;
            const path = vehicle.trail.concat([vehicle.shown]).map((place) => {
                const spot = project(place.lat, place.lng, zoom);
                return `${(spot.x - left).toFixed(1)},${(spot.y - top).toFixed(1)}`;
            });
            vehicle.trailLine.setAttribute('points', path.join(' '));
        }
    }

    /* ---- Moving the map ---------------------------------------------------------------- */
    function panBy(dx, dy) {
        const middle = project(center.lat, center.lng, zoom);
        center = unproject(middle.x + dx, middle.y + dy, zoom);
        following = false;
        requestDraw();
    }

    // Zooms one step, keeping the place under (px, py) where it is on screen.
    function zoomBy(step, px = width / 2, py = height / 2) {
        const next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom + step));
        if (next === zoom) return;
        const middle = project(center.lat, center.lng, zoom);
        const anchor = unproject(middle.x + px - width / 2, middle.y + py - height / 2, zoom);
        zoom = next;
        const moved = project(anchor.lat, anchor.lng, zoom);
        center = unproject(moved.x - px + width / 2, moved.y - py + height / 2, zoom);
        requestDraw();
    }

    // Shows every vehicle that has a position, or the home view when none has.
    function fitAll() {
        const places = [...vehicles.values()].filter((vehicle) => vehicle.shown).map((vehicle) => vehicle.shown);
        following = false;
        if (places.length === 0) {
            center = { ...home };
            zoom = config.zoom;
            requestDraw();
            return;
        }
        const lats = places.map((place) => place.lat);
        const lngs = places.map((place) => place.lng);
        center = { lat: (Math.min(...lats) + Math.max(...lats)) / 2, lng: (Math.min(...lngs) + Math.max(...lngs)) / 2 };
        zoom = 16;
        while (zoom > MIN_ZOOM) {
            const a = project(Math.max(...lats), Math.min(...lngs), zoom);
            const b = project(Math.min(...lats), Math.max(...lngs), zoom);
            if (b.x - a.x <= root.clientWidth - 140 && b.y - a.y <= root.clientHeight - 140) break;
            zoom -= 1;
        }
        requestDraw();
    }

    const pointers = new Map();
    let pinchDistance = 0;
    const offsetOf = (event) => {
        const box = root.getBoundingClientRect();
        return { x: event.clientX - box.left, y: event.clientY - box.top };
    };
    root.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button, a')) return;
        root.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        root.classList.add('is-dragging');
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinchDistance = Math.hypot(a.x - b.x, a.y - b.y);
        }
    });
    root.addEventListener('pointermove', (event) => {
        const last = pointers.get(event.pointerId);
        if (!last) return;
        const now = { x: event.clientX, y: event.clientY };
        pointers.set(event.pointerId, now);
        if (pointers.size === 1) {
            panBy(last.x - now.x, last.y - now.y);
            return;
        }
        // Two fingers: each time they spread or close by half, zoom one step.
        const [a, b] = [...pointers.values()];
        const distance = Math.hypot(a.x - b.x, a.y - b.y);
        const box = root.getBoundingClientRect();
        if (pinchDistance > 0 && (distance / pinchDistance >= 1.5 || distance / pinchDistance <= 0.67)) {
            zoomBy(distance > pinchDistance ? 1 : -1, (a.x + b.x) / 2 - box.left, (a.y + b.y) / 2 - box.top);
            pinchDistance = distance;
        }
    });
    const release = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size === 0) root.classList.remove('is-dragging');
    };
    root.addEventListener('pointerup', release);
    root.addEventListener('pointercancel', release);

    let wheelTravel = 0;
    root.addEventListener('wheel', (event) => {
        event.preventDefault();
        wheelTravel += event.deltaY;
        if (Math.abs(wheelTravel) < 60) return;
        const at = offsetOf(event);
        zoomBy(wheelTravel < 0 ? 1 : -1, at.x, at.y);
        wheelTravel = 0;
    }, { passive: false });
    root.addEventListener('dblclick', (event) => {
        if (event.target.closest('button, a')) return;
        const at = offsetOf(event);
        zoomBy(1, at.x, at.y);
    });
    root.addEventListener('keydown', (event) => {
        if (event.target !== root) return;
        const moves = { ArrowLeft: [-80, 0], ArrowRight: [80, 0], ArrowUp: [0, -80], ArrowDown: [0, 80] };
        if (moves[event.key]) {
            event.preventDefault();
            panBy(...moves[event.key]);
        } else if (event.key === '+' || event.key === '=') {
            zoomBy(1);
        } else if (event.key === '-') {
            zoomBy(-1);
        }
    });
    root.querySelector('[data-map-zoom="in"]').addEventListener('click', () => zoomBy(1));
    root.querySelector('[data-map-zoom="out"]').addEventListener('click', () => zoomBy(-1));
    root.querySelector('[data-map-fit]').addEventListener('click', () => { select(null); fitAll(); });
    new ResizeObserver(requestDraw).observe(root);

    /* ---- Vehicles ---------------------------------------------------------------------- */
    function select(agreementId) {
        selected = agreementId;
        following = agreementId !== null;
        for (const [id, vehicle] of vehicles) vehicle.marker.classList.toggle('is-selected', id === selected);
        list.querySelectorAll('li[data-agreement]').forEach((row) => row.classList.toggle('is-selected', Number(row.dataset.agreement) === selected));
        const vehicle = vehicles.get(agreementId);
        if (vehicle && vehicle.shown) {
            center = { ...vehicle.shown };
            if (zoom < 15) zoom = 15;
        }
        requestDraw();
    }

    function makeVehicle(data) {
        const marker = document.createElement('button');
        marker.type = 'button';
        marker.hidden = true;
        const arrow = document.createElement('span');
        arrow.className = 'fleet-marker-arrow';
        const pin = document.createElement('span');
        pin.className = 'fleet-marker-pin';
        const label = document.createElement('span');
        label.className = 'fleet-marker-label';
        label.textContent = data.plate;
        marker.append(arrow, pin, label);
        marker.addEventListener('click', () => select(data.agreement_id));
        markersLayer.append(marker);
        const trailLine = document.createElementNS(SVG, 'polyline');
        trailLine.setAttribute('class', 'fleet-map-trail');
        overlay.append(trailLine);
        return { data, marker, arrow, trailLine, shown: null, from: null, to: null, startedAt: 0, trail: [] };
    }

    function animate(now) {
        let moving = false;
        for (const vehicle of vehicles.values()) {
            if (!vehicle.to) continue;
            const progress = Math.min(1, (now - vehicle.startedAt) / Math.min(config.interval * 1000, 4000));
            vehicle.shown = { lat: vehicle.from.lat + (vehicle.to.lat - vehicle.from.lat) * progress, lng: vehicle.from.lng + (vehicle.to.lng - vehicle.from.lng) * progress };
            if (progress >= 1) vehicle.to = null; else moving = true;
        }
        const followed = following ? vehicles.get(selected) : null;
        if (followed && followed.shown) center = { ...followed.shown };
        draw();
        animating = moving && [...vehicles.values()].some((vehicle) => vehicle.to);
        if (animating) window.requestAnimationFrame(animate);
    }

    function apply(feed) {
        const seen = new Set();
        // A glide that never got its frames (the tab was in the background) ends where it was going.
        for (const vehicle of vehicles.values()) {
            if (vehicle.to) {
                vehicle.shown = vehicle.to;
                vehicle.to = null;
            }
        }
        for (const data of feed) {
            seen.add(data.agreement_id);
            let vehicle = vehicles.get(data.agreement_id);
            if (!vehicle) {
                vehicle = makeVehicle(data);
                vehicles.set(data.agreement_id, vehicle);
            }
            vehicle.data = data;
            vehicle.marker.className = `fleet-marker fleet-marker--${data.tone}${data.agreement_id === selected ? ' is-selected' : ''}`;
            vehicle.marker.setAttribute('aria-label', `${data.plate} ${data.vehicle}: ${data.status}`);
            if (!data.position) {
                vehicle.shown = null;
                vehicle.to = null;
                continue;
            }
            const target = { lat: data.position.latitude, lng: data.position.longitude };
            vehicle.arrow.hidden = data.position.heading === null || data.state !== 'live';
            if (data.position.heading !== null) vehicle.arrow.style.transform = `rotate(${data.position.heading}deg)`;
            if (!vehicle.shown) {
                vehicle.shown = target;
            } else if (metresBetween(vehicle.shown, target) > 1) {
                // Remember where it was, then glide to where it is.
                if (vehicle.trail.length === 0 || metresBetween(vehicle.trail[vehicle.trail.length - 1], vehicle.shown) > 5) {
                    vehicle.trail.push({ ...vehicle.shown });
                    if (vehicle.trail.length > 300) vehicle.trail.shift();
                }
                vehicle.from = { ...vehicle.shown };
                vehicle.to = target;
                vehicle.startedAt = window.performance.now();
            }
        }
        // A vehicle that is no longer out (returned) leaves the map at once.
        for (const [id, vehicle] of vehicles) {
            if (seen.has(id)) continue;
            vehicle.marker.remove();
            vehicle.trailLine.remove();
            vehicles.delete(id);
            if (selected === id) select(null);
        }
        renderList(feed);
        if (!fittedOnce && [...vehicles.values()].some((vehicle) => vehicle.shown)) {
            fittedOnce = true;
            fitAll();
        }
        draw();
        if (!animating && [...vehicles.values()].some((vehicle) => vehicle.to)) {
            animating = true;
            window.requestAnimationFrame(animate);
        }
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function renderList(feed) {
        const withPosition = feed.filter((data) => data.position).length;
        countBadge.textContent = `${feed.length} out · ${withPosition} on the map`;
        countBadge.className = `badge ${feed.some((data) => data.tone === 'danger') ? 'badge-danger' : (feed.length ? 'badge-info' : 'badge-neutral')}`;
        if (feed.length === 0) {
            const empty = element('li', 'empty-state');
            empty.append(element('strong', '', 'No vehicle is out on rental'), document.createTextNode('A vehicle appears here when its pickup is recorded.'));
            list.replaceChildren(empty);
            return;
        }
        list.replaceChildren(...feed.map((data) => {
            const row = element('li', data.agreement_id === selected ? 'is-selected' : '');
            row.dataset.agreement = String(data.agreement_id);
            const main = element('div', 'item-main');
            const pick = element('button', 'fleet-map-pick', `${data.plate} ${data.vehicle}`);
            pick.type = 'button';
            pick.disabled = !data.position;
            pick.addEventListener('click', () => select(data.agreement_id));
            main.append(pick, element('span', 'item-sub', `${data.customer} · ${data.due_back ? 'due back ' + data.due_back : 'no return time set'}`));
            for (const alert of data.alerts) main.append(element('span', 'item-sub fleet-map-alert', alert));
            const links = element('span', 'item-sub');
            const agreement = element('a', '', 'Agreement');
            agreement.href = data.detail_url;
            const connect = element('a', '', data.connected ? 'Tracker phone' : 'Connect a phone');
            connect.href = data.connect_url;
            links.append(agreement, document.createTextNode(' · '), connect);
            main.append(links);
            row.append(main, element('span', `badge badge-${data.tone === 'neutral' ? 'neutral' : data.tone}`, data.status));
            return row;
        }));
    }

    /* ---- Asking for positions ---------------------------------------------------------- */
    let stopped = false;
    async function refresh() {
        if (stopped || document.hidden) return;
        try {
            const response = await fetch(config.feed, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.status === 401 || response.status === 403) {
                stopped = true;
                updatedNote.textContent = 'You are signed out. Sign in again to see positions.';
                return;
            }
            if (!response.ok) throw new Error('feed');
            apply((await response.json()).vehicles);
            updatedNote.textContent = `Updated ${new Date().toLocaleTimeString()}. Positions are asked for every ${config.interval} seconds.`;
        } catch {
            updatedNote.textContent = 'Positions could not be refreshed. Trying again…';
        }
    }

    // Rows written by the server work before the first answer arrives.
    list.addEventListener('click', (event) => {
        const pick = event.target.closest('[data-pick]');
        if (pick) select(Number(pick.closest('li').dataset.agreement));
    });
    document.addEventListener('visibilitychange', refresh);
    draw();
    refresh();
    window.setInterval(refresh, config.interval * 1000);
})();
