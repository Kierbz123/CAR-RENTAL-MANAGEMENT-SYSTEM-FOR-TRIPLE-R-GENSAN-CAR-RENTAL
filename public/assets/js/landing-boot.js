/*
 * Runs before the public pages are drawn, so nothing flashes: it marks the page as
 * scripted (.js) and decides whether the loading screen plays (.intro).
 *
 * The loading screen plays on a fresh visit or a reload of a page that has one
 * (<html data-intro>). It is skipped when the visitor arrives from another page of
 * this site, uses Back or Forward, follows a link to a section, or prefers reduced motion.
 *
 * If landing.js never starts (blocked or failed to load), both marks are removed after
 * four seconds and the page shows as plain HTML.
 */
(() => {
    'use strict';

    const root = document.documentElement;
    root.classList.add('js');

    const entry = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
    let fromThisSite = false;
    try {
        fromThisSite = document.referrer !== '' && new URL(document.referrer).origin === window.location.origin;
    } catch (error) {
        fromThisSite = false;
    }
    const fresh = !entry || entry.type === 'reload' || (entry.type === 'navigate' && !fromThisSite);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (root.hasAttribute('data-intro') && fresh && !reducedMotion && window.location.hash === '') {
        root.classList.add('intro');
    }

    window.setTimeout(() => {
        if (!root.classList.contains('is-booted')) root.classList.remove('js', 'intro');
    }, 4000);
})();
