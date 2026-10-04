/*
 * Public site behaviour: the loading screen, header and phone menu, eased wheel
 * scrolling, entrances and reveals, the count-up numbers, the office clock, and
 * the moving light backdrop in the hero. Every page is complete without this file; the 3D
 * library is fetched only after the page has loaded and only when it can run.
 *
 * landing-boot.js runs first and puts .js (and, on a fresh visit, .intro) on <html>.
 * The motion is declared in the markup:
 *   data-enter="<ms>"     appears after the loading screen, <ms> later
 *   data-split="lines"    a heading whose lines rise one after another
 *   data-split="words"    a sentence whose words rise one after another
 *   class="reveal"        fades up when scrolled into view (data-delay="<ms>" staggers a group)
 *   data-count="<n>"      counts from 0 to <n> as it scrolls from the bottom of the screen to the middle
 */
(() => {
    'use strict';

    const root = document.documentElement;
    root.classList.add('js', 'is-booted');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const finePointer = window.matchMedia('(pointer: fine)').matches;

    /* ---- Scroll lock (loading screen, phone menu) ----------------------- */
    const locks = new Set();
    const setLock = (name, on) => {
        if (on) locks.add(name); else locks.delete(name);
        root.classList.toggle('is-locked', locks.size > 0);
    };

    /* ---- Eased wheel scrolling -------------------------------------------
     * The wheel moves a target and the page glides to it. Everything else (keys,
     * scrollbar, touch, links to sections) scrolls the normal way and cancels the glide. */
    if (finePointer && !reducedMotion) {
        let target = window.scrollY;
        let current = target;
        let frame = 0;
        const limit = () => Math.max(0, root.scrollHeight - window.innerHeight);
        const glide = () => {
            current += (target - current) * .11;
            if (Math.abs(target - current) < .5) {
                current = target;
                frame = 0;
            } else {
                frame = window.requestAnimationFrame(glide);
            }
            window.scrollTo({ top: current, behavior: 'instant' });
        };
        const stop = () => {
            if (frame) window.cancelAnimationFrame(frame);
            frame = 0;
        };
        window.addEventListener('wheel', (event) => {
            if (event.ctrlKey || event.defaultPrevented || locks.size > 0) return;
            if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
            if (event.target instanceof Element && event.target.closest('select, textarea')) return;
            event.preventDefault();
            const unit = event.deltaMode === 1 ? 40 : event.deltaMode === 2 ? window.innerHeight : 1;
            if (!frame) {
                current = window.scrollY;
                target = current;
            }
            target = Math.max(0, Math.min(limit(), target + event.deltaY * unit));
            if (!frame) frame = window.requestAnimationFrame(glide);
        }, { passive: false });
        ['keydown', 'pointerdown', 'touchstart'].forEach((type) => window.addEventListener(type, stop, { passive: true }));
    }

    /* ---- Header and phone menu ------------------------------------------- */
    const header = document.querySelector('[data-site-header]');
    const toggle = document.querySelector('[data-nav-toggle]');
    const toggleLabel = document.querySelector('[data-nav-toggle-label]');
    const nav = document.querySelector('[data-site-nav]');

    const syncHeader = () => header?.classList.toggle('is-scrolled', window.scrollY > 24);
    window.addEventListener('scroll', syncHeader, { passive: true });
    syncHeader();

    const menuOpen = () => Boolean(header?.classList.contains('is-open'));
    const setMenu = (open) => {
        if (!header || !toggle) return;
        header.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (toggleLabel) toggleLabel.textContent = open ? 'Close' : 'Menu';
        setLock('menu', open);
        // The links start hidden; one frame later they are told to arrive, so the change is animated.
        if (open) window.requestAnimationFrame(() => window.requestAnimationFrame(() => header.classList.toggle('is-entered', menuOpen())));
        else header.classList.remove('is-entered');
    };
    toggle?.addEventListener('click', () => setMenu(!menuOpen()));
    nav?.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('a')) setMenu(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menuOpen()) {
            setMenu(false);
            toggle?.focus();
        }
    });
    window.matchMedia('(min-width: 861px)').addEventListener('change', (event) => {
        if (event.matches && menuOpen()) setMenu(false);
    });

    /* ---- Entrances, split headings and reveals --------------------------- */
    document.querySelectorAll('[data-enter]').forEach((element) => {
        element.style.transitionDelay = `${Number(element.dataset.enter) || 0}ms`;
    });
    document.querySelectorAll('.reveal[data-delay]').forEach((element) => {
        element.style.transitionDelay = `${Number(element.dataset.delay) || 0}ms`;
    });

    // Wraps every word of a heading in two spans: an outer box that clips and an inner one that moves.
    const wrapWords = (element) => {
        const inners = [];
        const walk = (node) => {
            [...node.childNodes].forEach((child) => {
                if (child.nodeType === Node.TEXT_NODE) {
                    const pieces = document.createDocumentFragment();
                    child.textContent.split(/(\s+)/).forEach((piece) => {
                        if (piece === '') return;
                        if (/^\s+$/.test(piece)) {
                            pieces.append(' ');
                            return;
                        }
                        const outer = document.createElement('span');
                        const inner = document.createElement('span');
                        outer.className = 'w';
                        inner.className = 'w-in';
                        inner.textContent = piece;
                        outer.append(inner);
                        pieces.append(outer);
                        inners.push(inner);
                    });
                    child.replaceWith(pieces);
                } else if (child.nodeType === Node.ELEMENT_NODE && child.tagName !== 'BR') {
                    walk(child);
                }
            });
        };
        walk(element);
        return inners;
    };

    const splits = [...document.querySelectorAll('[data-split]')];
    splits.forEach((element) => {
        if (reducedMotion) {
            element.classList.add('is-split', 'is-revealed');
            return;
        }
        const byWord = element.dataset.split === 'words';
        const delay = Number(element.dataset.splitDelay) || (byWord ? 0 : 120);
        const stagger = Number(element.dataset.splitStagger) || (byWord ? 35 : 90);
        const inners = wrapWords(element);
        let line = -1;
        let lineTop = null;
        inners.forEach((inner, index) => {
            // Words that share a top edge are on the same line and move together.
            const top = inner.parentElement.offsetTop;
            if (lineTop === null || Math.abs(top - lineTop) > 4) {
                line += 1;
                lineTop = top;
            }
            inner.style.transitionDelay = `${delay + (byWord ? index : line) * stagger}ms`;
        });
        element.classList.add('is-split');
    });

    const reveals = [...document.querySelectorAll('.reveal')];
    const watchReveals = () => {
        if (!('IntersectionObserver' in window) || reducedMotion) {
            reveals.forEach((element) => element.classList.add('is-visible'));
            splits.forEach((element) => element.classList.add('is-revealed'));
            return;
        }
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add(entry.target.hasAttribute('data-split') ? 'is-revealed' : 'is-visible');
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: .08 });
        [...reveals, ...splits].forEach((element) => {
            // What is already on screen appears at once; the rest waits to be scrolled to.
            const box = element.getBoundingClientRect();
            if (box.top < window.innerHeight * .92 && box.bottom > 0) element.classList.add(element.hasAttribute('data-split') ? 'is-revealed' : 'is-visible');
            else observer.observe(element);
        });
    };

    /* ---- Loading screen ---------------------------------------------------
     * Counts 000 to 100, then lifts away. The page's entrances wait for it (is-ready). */
    const loader = document.querySelector('[data-loader]');
    const ready = () => {
        if (root.classList.contains('is-ready')) return;
        root.classList.add('is-ready');
        watchReveals();
    };
    if (loader && root.classList.contains('intro')) {
        const FILL_MS = 1300;
        const fill = loader.querySelector('[data-loader-fill]');
        const count = loader.querySelector('[data-loader-count]');
        const easeInOutCubic = (t) => (t < .5 ? 4 * t * t * t : 1 - ((-2 * t + 2) ** 3) / 2);
        if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual';
        window.scrollTo({ top: 0, behavior: 'instant' });
        setLock('intro', true);

        const leave = () => {
            let gone = false;
            const finish = () => {
                if (gone) return;
                gone = true;
                ready();
                loader.remove();
                root.classList.remove('intro');
            };
            loader.classList.add('is-leaving');
            setLock('intro', false);
            loader.addEventListener('transitionend', (event) => {
                if (event.target === loader && event.propertyName === 'transform') finish();
            });
            // The page starts arriving while the screen is still lifting.
            window.setTimeout(ready, 420);
            window.setTimeout(finish, 1300);
        };
        let startedAt = 0;
        const fillUp = (now) => {
            startedAt = startedAt || now;
            const t = Math.min(1, (now - startedAt) / FILL_MS);
            const progress = Math.round(easeInOutCubic(t) * 100);
            if (fill) fill.style.transform = `scaleX(${progress / 100})`;
            if (count) count.textContent = String(progress).padStart(3, '0');
            if (t < 1) window.requestAnimationFrame(fillUp);
            else leave();
        };
        window.requestAnimationFrame(fillUp);
    } else {
        root.classList.remove('intro');
        ready();
    }

    /* ---- Numbers that count up with the scroll --------------------------- */
    const counters = [...document.querySelectorAll('[data-count]')];
    if (counters.length && !reducedMotion) {
        let queued = false;
        const count = () => {
            queued = false;
            const height = window.innerHeight;
            counters.forEach((element) => {
                const box = element.getBoundingClientRect();
                // 0 when the number's top edge reaches the bottom of the screen, 1 when its middle reaches the middle.
                const end = height / 2 - box.height / 2;
                const progress = Math.min(1, Math.max(0, (height - box.top) / (height - end)));
                element.textContent = String(Math.round(progress * Number(element.dataset.count)));
            });
        };
        const queue = () => {
            if (queued) return;
            queued = true;
            window.requestAnimationFrame(count);
        };
        window.addEventListener('scroll', queue, { passive: true });
        window.addEventListener('resize', queue);
        count();
    }

    /* ---- Office clock in the hero ----------------------------------------- */
    const clock = document.querySelector('[data-clock]');
    const openHour = Number(clock?.dataset.openHour);
    const closeHour = Number(clock?.dataset.closeHour);
    if (clock && closeHour > openHour) {
        const manila = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
        const hourName = (hour) => `${hour % 12 || 12} ${hour % 24 < 12 ? 'AM' : 'PM'}`;
        const showTime = () => {
            const part = Object.fromEntries(manila.formatToParts(new Date()).map((item) => [item.type, item.value]));
            const period = String(part.dayPeriod || '').toLowerCase();
            const hour = (Number(part.hour) % 12) + (period === 'pm' ? 12 : 0);
            const open = hour >= openHour && hour < closeHour;
            clock.textContent = `${part.hour}:${part.minute} ${period} in Gensan · ${open ? `Open until ${hourName(closeHour)}` : `Opens ${hourName(openHour)}`}`;
            clock.classList.toggle('is-open', open);
        };
        showTime();
        window.setInterval(showTime, 1000);
    }

    /* ---- Mark the current section in the menu ---------------------------- */
    const links = [...document.querySelectorAll('.site-nav li a[href^="#"]')];
    const sections = links.map((link) => document.querySelector(link.getAttribute('href'))).filter(Boolean);
    if ('IntersectionObserver' in window && sections.length) {
        const current = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                links.forEach((link) => {
                    if (link.getAttribute('href') === `#${entry.target.id}`) link.setAttribute('aria-current', 'true');
                    else link.removeAttribute('aria-current');
                });
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        sections.forEach((section) => current.observe(section));
    }

    /* ---- Hero scene -------------------------------------------------------- */
    const hero = document.querySelector('[data-hero]');
    const canvas = document.getElementById('hero-scene');

    const startScene = () => {
        const THREE = window.THREE;
        const context = canvas.getContext('webgl2', { alpha: false, antialias: true, powerPreference: 'high-performance' });
        if (!THREE || !context) return;

        const isCompact = () => hero.clientWidth <= 767;
        const renderer = new THREE.WebGLRenderer({ canvas, context, antialias: true, alpha: false, powerPreference: 'high-performance' });
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 2.2;

        const scene = new THREE.Scene();
        scene.background = new THREE.Color('#0d1b1e');
        scene.fog = new THREE.FogExp2('#0d1b1e', .01);
        const camera = new THREE.PerspectiveCamera(50, 1, .1, 100);
        camera.position.set(0, .35, 4.2);
        scene.add(camera);
        const clock = new THREE.Clock();

        // Slow-moving light field, in the brand's ink and amber.
        const uniforms = {
            uTime: { value: 0 },
            uResolution: { value: new THREE.Vector2(1, 1) },
            uMouse: { value: new THREE.Vector2(0, 0) },
            uScroll: { value: 0 },
        };
        const backgroundMaterial = new THREE.ShaderMaterial({
            depthWrite: false,
            depthTest: false,
            uniforms,
            vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
            fragmentShader: `
                varying vec2 vUv;
                uniform float uTime;
                uniform float uScroll;
                uniform vec2 uResolution;
                uniform vec2 uMouse;
                void main(){
                    vec2 uv = (gl_FragCoord.xy - .5 * uResolution.xy) / uResolution.y;
                    float aspect = uResolution.x / uResolution.y;
                    float time = uTime * .08;
                    float deform = uScroll * 5.0;
                    vec2 warped = uv;
                    warped.x += sin(uv.y * 2.5 + time * .2 + deform) * .35;
                    warped.y += cos(uv.x * 2.5 - time * .15 - deform * .8) * .35;
                    warped.x += sin(uv.y * 1.2 - time * .1 - deform * 1.5) * .25;
                    warped.y += cos(uv.x * 1.2 + time * .18 + deform * 1.2) * .25;
                    warped += vec2(uScroll * .04, -uScroll * .02) + vec2(uMouse.x * aspect * .05, uMouse.y * .05);
                    float w1 = sin(dot(warped, vec2(cos(.6), sin(.6))) * 2.4 + time);
                    float w2 = cos(dot(warped, vec2(cos(-.7), sin(-.7))) * 3.2 - time * 1.4 + w1 * .4);
                    float w3 = sin(dot(warped, vec2(cos(1.2), sin(1.2))) * 4.0 + time * 1.8 + w2 * .5);
                    float field = w1 * .50 + w2 * .35 + w3 * .15;
                    float sheen = pow(max(0.0, 1.0 - abs(field - .1)), 2.5);
                    float specular = pow(max(0.0, 1.0 - abs(field - .15)), 8.0);
                    float crest = sheen * .5 + specular * .9;
                    vec3 shadow = vec3(.035, .075, .085);
                    vec3 waveA = vec3(.075, .145, .157);
                    vec3 waveB = vec3(.055, .110, .120);
                    vec3 warmCrest = vec3(.42, .29, .14);
                    vec3 coolCrest = vec3(.16, .30, .30);
                    vec3 color = shadow;
                    color = mix(color, waveB, smoothstep(-.6, .2, field));
                    color = mix(color, waveA, smoothstep(0.0, .8, field));
                    color += mix(warmCrest, coolCrest, smoothstep(0.0, 1.0, uScroll)) * crest * 1.1;
                    color *= 1.0 - dot(uv, uv) * .12;
                    gl_FragColor = vec4(color, 1.0);
                }
            `,
        });
        const background = new THREE.Mesh(new THREE.PlaneGeometry(30, 30), backgroundMaterial);
        background.position.set(0, 0, -8);
        background.frustumCulled = false;
        background.renderOrder = -10;
        camera.add(background);

        // Drifting points of light, like road lights passing.
        const particleCount = isCompact() ? 150 : 450;
        const positions = new Float32Array(particleCount * 3);
        const colors = new Float32Array(particleCount * 3);
        const drift = [];
        for (let i = 0; i < particleCount; i += 1) {
            const offset = i * 3;
            positions[offset] = (Math.random() - .5) * 6.5;
            positions[offset + 1] = (Math.random() - .5) * 5 - .5;
            positions[offset + 2] = (Math.random() - .5) * 6.5;
            const warm = Math.random() < .7;
            colors[offset] = warm ? 1 : .6;
            colors[offset + 1] = warm ? .62 + Math.random() * .12 : .85;
            colors[offset + 2] = warm ? .3 + Math.random() * .1 : .8;
            drift.push({
                x: (Math.random() - .5) * .4,
                y: .15 + Math.random() * .3,
                z: (Math.random() - .5) * .4,
                swaySpeed: .5 + Math.random() * 1.5,
                swayRadius: .05 + Math.random() * .15,
                phase: Math.random() * Math.PI * 2,
            });
        }
        const sprite = document.createElement('canvas');
        sprite.width = sprite.height = 16;
        const spriteContext = sprite.getContext('2d');
        const gradient = spriteContext.createRadialGradient(8, 8, 0, 8, 8, 8);
        gradient.addColorStop(0, 'rgba(255,255,255,1)');
        gradient.addColorStop(.25, 'rgba(255,255,255,.85)');
        gradient.addColorStop(.6, 'rgba(255,255,255,.3)');
        gradient.addColorStop(1, 'rgba(0,0,0,0)');
        spriteContext.fillStyle = gradient;
        spriteContext.fillRect(0, 0, 16, 16);
        const particleGeometry = new THREE.BufferGeometry();
        particleGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        particleGeometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        scene.add(new THREE.Points(particleGeometry, new THREE.PointsMaterial({ size: .025, vertexColors: true, transparent: true, opacity: .85, depthWrite: false, blending: THREE.AdditiveBlending, map: new THREE.CanvasTexture(sprite) })));

        const layout = () => {
            const width = hero.clientWidth;
            const height = hero.clientHeight;
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, width <= 767 ? 1.5 : 2));
            renderer.setSize(width, height, false);
            renderer.getDrawingBufferSize(uniforms.uResolution.value);
        };

        let pointerX = 0;
        let pointerY = 0;
        let easedX = 0;
        let easedY = 0;
        let targetScroll = 0;
        let scroll = 0;
        if (window.matchMedia('(pointer: fine)').matches && !reducedMotion) {
            window.addEventListener('pointermove', (event) => {
                pointerX = (event.clientX / window.innerWidth) * 2 - 1;
                pointerY = (event.clientY / window.innerHeight) * 2 - 1;
            }, { passive: true });
        }
        const readScroll = () => { targetScroll = Math.min(1, Math.max(0, window.scrollY / Math.max(1, hero.clientHeight))); };
        window.addEventListener('scroll', readScroll, { passive: true });
        readScroll();

        const cameraTarget = new THREE.Vector3();
        const lookTarget = new THREE.Vector3();
        const placeCamera = (progress, snap) => {
            const phi = progress * .9;
            cameraTarget.set(4.2 * Math.sin(phi), .35 + progress * .5, 4.2 * Math.cos(phi));
            if (snap) camera.position.copy(cameraTarget);
            else camera.position.lerp(cameraTarget, .06);
            lookTarget.set(0, -.15, 0);
            camera.lookAt(lookTarget);
        };

        const drawFrame = () => {
            const delta = Math.min(clock.getDelta(), .05);
            const time = clock.getElapsedTime();
            scroll += (targetScroll - scroll) * .06;
            easedX += (pointerX - easedX) * .05;
            easedY += (pointerY - easedY) * .05;
            const velocity = Math.abs(targetScroll - scroll);
            for (let i = 0; i < particleCount; i += 1) {
                const offset = i * 3;
                const d = drift[i];
                const speed = 1 + velocity * 9;
                positions[offset] += (d.x * speed + Math.sin(time * d.swaySpeed + d.phase) * d.swayRadius) * delta;
                positions[offset + 1] += d.y * delta * speed;
                positions[offset + 2] += (d.z * speed + Math.cos(time * d.swaySpeed + d.phase) * d.swayRadius) * delta;
                if (positions[offset + 1] > 3 || Math.abs(positions[offset]) > 3.5 || Math.abs(positions[offset + 2]) > 3.5) {
                    positions[offset + 1] = -2.5;
                    positions[offset] = (Math.random() - .5) * 3;
                    positions[offset + 2] = (Math.random() - .5) * 3;
                }
            }
            particleGeometry.attributes.position.needsUpdate = true;
            placeCamera(scroll, false);
            uniforms.uTime.value = time;
            uniforms.uMouse.value.set(easedX, -easedY);
            uniforms.uScroll.value = scroll;
            renderer.render(scene, camera);
        };

        const drawStill = () => {
            placeCamera(0, true);
            renderer.render(scene, camera);
        };

        // Animate only while the hero is on screen and the tab is visible.
        let visible = true;
        let frame = 0;
        const loop = () => {
            frame = 0;
            if (!visible || document.hidden) return;
            drawFrame();
            frame = window.requestAnimationFrame(loop);
        };
        const wake = () => {
            if (reducedMotion) { drawStill(); return; }
            if (!frame) { clock.getDelta(); frame = window.requestAnimationFrame(loop); }
        };
        new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; if (visible) wake(); }).observe(hero);
        document.addEventListener('visibilitychange', wake);
        new ResizeObserver(() => { layout(); if (reducedMotion) drawStill(); }).observe(hero);

        layout();
        placeCamera(0, true);
        if (reducedMotion) drawStill(); else wake();
        canvas.classList.add('is-ready');
    };

    if (hero && canvas) {
        const probe = document.createElement('canvas').getContext('webgl2');
        if (probe) {
            probe.getExtension('WEBGL_lose_context')?.loseContext();
            // Fetch the 3D library after the page itself has loaded, so it never delays the content.
            const load = () => {
                const script = document.createElement('script');
                script.src = '/assets/js/vendor/three.min.js';
                script.async = true;
                script.addEventListener('load', () => {
                    try { startScene(); } catch (error) { console.warn('Hero scene unavailable; showing the still background.', error); }
                });
                document.head.append(script);
            };
            if (document.readyState === 'complete') load();
            else window.addEventListener('load', load, { once: true });
        }
    }
})();
