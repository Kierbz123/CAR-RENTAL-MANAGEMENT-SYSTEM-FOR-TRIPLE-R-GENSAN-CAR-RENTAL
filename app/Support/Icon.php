<?php
declare(strict_types=1);

namespace TripleR\Support;

/** Small inline SVG icons (24px grid, stroked) so pages need no icon font or external request. */
final class Icon
{
    private const PATHS = [
        'home' => '<path d="M4 11.5 12 5l8 6.5"/><path d="M6.5 10v8.5h11V10"/><path d="M10 18.5v-5h4v5"/>',
        'document' => '<path d="M7 3.5h7l4 4V20a.5.5 0 0 1-.5.5h-10A.5.5 0 0 1 7 20z"/><path d="M14 3.5v4h4"/><path d="M9.5 12h5M9.5 15.5h5"/>',
        'users' => '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19c.6-3 2.8-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/><path d="M15.5 6a2.8 2.8 0 0 1 0 5.4M17 14.7c1.9.5 3.1 1.9 3.5 4.3"/>',
        'car' => '<path d="M4.5 16.5v-3.2c0-.6.3-1.1.8-1.4l1-.6 1.5-3.6c.3-.7 1-1.2 1.8-1.2h4.8c.8 0 1.5.5 1.8 1.2l1.5 3.6 1 .6c.5.3.8.8.8 1.4v3.2"/><path d="M3.5 16.5h17"/><circle cx="8" cy="16.5" r="1.7"/><circle cx="16" cy="16.5" r="1.7"/><path d="M7 11.3h10"/>',
        'pin' => '<path d="M12 20.5s6-5.4 6-10.3a6 6 0 0 0-12 0c0 4.9 6 10.3 6 10.3z"/><circle cx="12" cy="10" r="2.2"/>',
        'id' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><circle cx="8.8" cy="11" r="2"/><path d="M5.8 16c.5-1.5 1.6-2.2 3-2.2s2.5.7 3 2.2"/><path d="M14.5 10h3.5M14.5 13.5h3.5"/>',
        'wrench' => '<path d="M14.5 5.2a4 4 0 0 0-4.9 5.1L4.2 15.7a1.5 1.5 0 0 0 0 2.1l2 2a1.5 1.5 0 0 0 2.1 0l5.4-5.4a4 4 0 0 0 5.1-4.9l-2.6 2.6-2.3-.6-.6-2.3z"/>',
        'bell' => '<path d="M6.5 16.5V11a5.5 5.5 0 0 1 11 0v5.5l1.5 1.5h-14z"/><path d="M10 20a2.1 2.1 0 0 0 4 0"/>',
        'shield' => '<path d="M12 3.5 5 6v5.5c0 4.2 2.8 7.4 7 9 4.2-1.6 7-4.8 7-9V6z"/><path d="m9.2 12 2 2 3.6-4"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-up-right' => '<path d="M7 17 17 7M8 7h9v9"/>',
        'arrow-down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'logout' => '<path d="M14 4.5h4.5v15H14"/><path d="M4.5 12H15M11 8l4 4-4 4"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.5-4.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>',
        'alert' => '<path d="M12 4 3.5 19h17z"/><path d="M12 10v4.5M12 16.8v.2"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'cash' => '<rect x="3.5" y="6.5" width="17" height="11" rx="1.5"/><circle cx="12" cy="12" r="2.4"/><path d="M6.8 9.8v.1M17.2 14.1v.1"/>',
        'phone' => '<path d="M6.5 4h3l1.5 4-2 1.5a11 11 0 0 0 5.5 5.5l1.5-2 4 1.5v3a1.5 1.5 0 0 1-1.6 1.5A15.5 15.5 0 0 1 5 5.6 1.5 1.5 0 0 1 6.5 4z"/>',
        'calendar' => '<rect x="4" y="5.5" width="16" height="14.5" rx="2"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/>',
        'printer' => '<path d="M7 9V4.5h10V9"/><rect x="4" y="9" width="16" height="7.5" rx="1.5"/><path d="M7 14h10v5.5H7z"/>',
    ];

    public static function svg(string $name, string $class = 'icon'): string
    {
        $paths = self::PATHS[$name] ?? '';
        return '<svg class="' . View::e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /** The three-slash brand mark shared by the favicon, the sidebar and the public pages. */
    public static function mark(string $class = 'brand-mark'): string
    {
        return '<span class="' . View::e($class) . '" aria-hidden="true"><i></i><i></i><i></i></span>';
    }
}
