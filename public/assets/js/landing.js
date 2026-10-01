/*
 * Public landing page behaviour: header state, phone menu, section reveal, and
 * the 3D wheel in the hero. The page is complete without this file; the 3D
 * library is fetched only after the page has loaded and only when it can run.
 */
(() => {
    'use strict';

    const root = document.documentElement;
    root.classList.add('js');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---- Header ---------------------------------------------------------- */
    const header = document.querySelector('[data-site-header]');
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('[data-site-nav]');

    const syncHeader = () => header?.classList.toggle('is-scrolled', window.scrollY > 24);
    window.addEventListener('scroll', syncHeader, { passive: true });
    syncHeader();

    const setMenu = (open) => {
        header?.classList.toggle('is-open', open);
        toggle?.setAttribute('aria-expanded', String(open));
    };
    toggle?.addEventListener('click', () => setMenu(!header.classList.contains('is-open')));
    nav?.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('a')) setMenu(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && header?.classList.contains('is-open')) {
            setMenu(false);
            toggle?.focus();
        }
    });

    /* ---- Reveal sections and mark the current one in the menu ------------- */
    const reveals = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window && !reducedMotion) {
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                revealObserver.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: .08 });
        reveals.forEach((element) => revealObserver.observe(element));
    } else {
        reveals.forEach((element) => element.classList.add('is-visible'));
    }

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
        renderer.shadowMap.enabled = !isCompact();
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;

        const scene = new THREE.Scene();
        scene.background = new THREE.Color('#0d1b1e');
        scene.fog = new THREE.FogExp2('#0d1b1e', .01);
        const camera = new THREE.PerspectiveCamera(50, 1, .1, 100);
        camera.position.set(0, .35, 4.2);
        scene.add(camera);
        const clock = new THREE.Clock();

        // Slow-moving light field behind the wheel, in the brand's ink and amber.
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

        scene.add(new THREE.AmbientLight('#ffffff', .1));
        const keyLight = new THREE.SpotLight('#ffffff', 18);
        keyLight.position.set(4, 6, 3);
        keyLight.angle = Math.PI / 4;
        keyLight.penumbra = .9;
        keyLight.castShadow = renderer.shadowMap.enabled;
        if (keyLight.castShadow) {
            keyLight.shadow.mapSize.set(2048, 2048);
            keyLight.shadow.camera.near = 1;
            keyLight.shadow.camera.far = 15;
            keyLight.shadow.bias = -.001;
        }
        scene.add(keyLight);
        const rimLight = new THREE.DirectionalLight('#e3f2ff', 10);
        rimLight.position.set(-5, 3, -4);
        scene.add(rimLight);
        const warmFill = new THREE.DirectionalLight('#ffe2bd', 1.2);
        warmFill.position.set(-2, -4, 2);
        scene.add(warmFill);

        // The wheel.
        const pivot = new THREE.Group();
        scene.add(pivot);
        const chrome = new THREE.MeshStandardMaterial({ color: 0xc4ccd0, metalness: .92, roughness: .42 });
        const darkSteel = new THREE.MeshStandardMaterial({ color: 0x4d565d, metalness: .89, roughness: .36 });
        const brightSteel = new THREE.MeshStandardMaterial({ color: 0xe3e8e8, metalness: .95, roughness: .24 });
        const rubber = new THREE.MeshStandardMaterial({ color: 0x111416, metalness: .2, roughness: .72 });
        const wheel = new THREE.Group();
        pivot.add(wheel);
        const spinner = new THREE.Group();
        wheel.add(spinner);
        spinner.add(new THREE.Mesh(new THREE.TorusGeometry(1.55, .21, 24, 112), rubber));
        const outerRim = new THREE.Mesh(new THREE.TorusGeometry(1.24, .1, 18, 96), chrome);
        outerRim.position.z = .09;
        spinner.add(outerRim);
        const innerRim = new THREE.Mesh(new THREE.TorusGeometry(.48, .07, 14, 64), brightSteel);
        innerRim.position.z = .11;
        spinner.add(innerRim);
        const hub = new THREE.Mesh(new THREE.CylinderGeometry(.25, .25, .2, 32), darkSteel);
        hub.rotation.x = Math.PI / 2;
        hub.position.z = .13;
        spinner.add(hub);
        for (let i = 0; i < 12; i += 1) {
            const angle = (i / 12) * Math.PI * 2;
            const spoke = new THREE.Mesh(new THREE.CylinderGeometry(.035, .075, 1.33, 8), i % 2 ? chrome : brightSteel);
            spoke.position.set(Math.cos(angle) * .84, Math.sin(angle) * .84, .13);
            spoke.rotation.z = angle - Math.PI / 2;
            spinner.add(spoke);
        }
        for (let i = 0; i < 10; i += 1) {
            const angle = (i / 10) * Math.PI * 2;
            const bolt = new THREE.Mesh(new THREE.SphereGeometry(.035, 10, 8), brightSteel);
            bolt.position.set(Math.cos(angle) * .34, Math.sin(angle) * .34, .24);
            spinner.add(bolt);
        }
        wheel.rotation.y = -.22;
        wheel.rotation.x = .18;
        wheel.traverse((object) => {
            if (object.isMesh) {
                object.castShadow = renderer.shadowMap.enabled;
                object.receiveShadow = renderer.shadowMap.enabled;
            }
        });

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

        // Where the wheel sits depends on the shape of the hero, so it never hides the copy.
        // On landscape screens its left edge is pinned to 57% of the width; the copy stays in the left half.
        const WHEEL_RADIUS = 1.76;
        const VIEW_HEIGHT_AT_WHEEL = Math.tan((25 * Math.PI) / 180) * 4.2 * 2;
        const layout = () => {
            const width = hero.clientWidth;
            const height = hero.clientHeight;
            const aspect = width / height;
            camera.aspect = aspect;
            camera.updateProjectionMatrix();
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, width <= 767 ? 1.5 : 2));
            renderer.setSize(width, height, false);
            renderer.getDrawingBufferSize(uniforms.uResolution.value);
            if (aspect < .8) {
                // Portrait: wheel in the upper right, copy along the bottom.
                pivot.position.set(.45, 1, 0);
                wheel.scale.setScalar(.58);
            } else {
                const unitsPerPixel = VIEW_HEIGHT_AT_WHEEL / height;
                const scale = Math.min(1.04, Math.max(.62, .55 + (aspect - .8) * .62));
                const leftEdge = (.57 - .5) * width * unitsPerPixel;
                pivot.position.set(leftEdge + WHEEL_RADIUS * scale, -.3, 0);
                wheel.scale.setScalar(scale);
            }
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
            pivot.rotation.y = easedX * .25;
            pivot.rotation.x = easedY * .15;
            spinner.rotation.z -= delta * (.12 + velocity * 6);
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
