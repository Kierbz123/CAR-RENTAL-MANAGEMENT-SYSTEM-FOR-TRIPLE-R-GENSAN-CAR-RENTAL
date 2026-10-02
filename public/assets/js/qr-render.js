(() => {
    'use strict';
    // Draws a QR code inside every element that carries the text in a data-qr attribute.
    // Needs vendor/qrcode-generator.js loaded first. Built with DOM calls and attributes only:
    // the page's security policy forbids inline styles and data: images, which is how the
    // library's own renderers work.
    const SVG = 'http://www.w3.org/2000/svg';
    const QUIET_ZONE = 4; // Blank modules around the code; scanners need them.

    function draw(holder) {
        if (typeof window.qrcode !== 'function' || !holder.dataset.qr) return;
        const code = window.qrcode(0, 'M');
        code.addData(holder.dataset.qr);
        code.make();
        const count = code.getModuleCount();
        const size = count + QUIET_ZONE * 2;
        let path = '';
        for (let row = 0; row < count; row += 1) {
            for (let column = 0; column < count; column += 1) {
                if (code.isDark(row, column)) path += `M${column + QUIET_ZONE},${row + QUIET_ZONE}h1v1h-1z`;
            }
        }
        const svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
        svg.setAttribute('shape-rendering', 'crispEdges');
        svg.setAttribute('aria-hidden', 'true');
        const background = document.createElementNS(SVG, 'rect');
        background.setAttribute('width', String(size));
        background.setAttribute('height', String(size));
        background.setAttribute('fill', '#ffffff');
        const modules = document.createElementNS(SVG, 'path');
        modules.setAttribute('d', path);
        modules.setAttribute('fill', '#000000');
        svg.append(background, modules);
        holder.replaceChildren(svg);
    }

    document.querySelectorAll('[data-qr]').forEach(draw);
})();
