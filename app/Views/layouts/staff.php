<?php
declare(strict_types=1);

use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Support\Icon;
use TripleR\Support\Navigation;
use TripleR\Support\StatusPresenter;
use TripleR\Support\View;

/**
 * Staff workspace layout: sidebar, top bar with breadcrumb, and the page content.
 *
 * Options: title (string), crumbs (list of [label, href|null]), scripts (list of
 * file names under /assets/js), wide (bool, removes the content max-width).
 */
$e = static fn (mixed $value): string => View::e($value);
$title = (string) ($options['title'] ?? 'Staff workspace');
$crumbs = $options['crumbs'] ?? [];
$scripts = $options['scripts'] ?? [];
$role = (string) ($user['role'] ?? '');
// A driver's home is their own trips, not the staff workspace.
$home = Access::home($role);
$homeLabel = $role === 'driver' ? 'My trips' : 'Workspace';
$groups = $user !== null ? Navigation::groupsFor($role, View::currentPath()) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> | Triple R Gensan</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app-shell.js" defer></script>
<?php foreach ($scripts as $script): ?>
    <script src="/assets/js/<?= $e($script) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="app">
<a class="skip-link" href="#main">Skip to content</a>
<aside class="app-sidebar" id="app-sidebar" aria-label="Staff navigation">
    <a class="app-sidebar-brand" href="<?= $e($home) ?>" aria-label="Triple R Gensan, <?= $e(strtolower($homeLabel)) ?>">
        <?= Icon::mark() ?>
        <span class="app-brand-name">TRIPLE R<small>GENSAN · OPERATIONS</small></span>
    </a>
    <nav class="app-nav" aria-label="Main">
<?php foreach ($groups as $heading => $items): ?>
        <p class="app-nav-heading"><?= $e($heading) ?></p>
        <ul>
<?php foreach ($items as $item): ?>
            <li><a href="<?= $e($item['href']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>><?= Icon::svg($item['icon']) ?><span><?= $e($item['label']) ?></span></a></li>
<?php endforeach; ?>
        </ul>
<?php endforeach; ?>
    </nav>
<?php if ($user !== null): ?>
    <div class="app-sidebar-user">
        <div class="app-sidebar-user-text">
            <strong title="<?= $e($user['email']) ?>"><?= $e($user['email']) ?></strong>
            <span><?= $e(StatusPresenter::label($role)) ?></span>
        </div>
        <form method="post" action="/staff/logout" data-confirm="Sign out of the staff workspace?" data-confirm-action="Sign out" data-confirm-tone="neutral">
            <input type="hidden" name="_csrf" value="<?= $e(Csrf::token()) ?>">
            <button class="app-signout" type="submit"><?= Icon::svg('logout') ?><span>Sign out</span></button>
        </form>
    </div>
<?php endif; ?>
</aside>
<a class="app-backdrop" href="#main" tabindex="-1" aria-hidden="true" data-drawer-close></a>
<div class="app-main">
    <header class="app-topbar">
        <a class="app-menu-button" href="#app-sidebar" role="button" aria-controls="app-sidebar" aria-expanded="false" data-drawer-open><?= Icon::svg('menu') ?><span>Menu</span></a>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?= $e($home) ?>"><?= $e($homeLabel) ?></a></li>
<?php foreach ($crumbs as $index => $crumb): [$label, $href] = [$crumb[0], $crumb[1] ?? null]; $last = $index === array_key_last($crumbs); ?>
                <li<?= $last ? ' aria-current="page"' : '' ?>><?php if ($href !== null && !$last): ?><a href="<?= $e($href) ?>"><?= $e($label) ?></a><?php else: ?><?= $e($label) ?><?php endif; ?></li>
<?php endforeach; ?>
            </ol>
        </nav>
    </header>
    <main class="page-shell<?= !empty($options['wide']) ? ' page-shell--wide' : '' ?>" id="main" tabindex="-1">
<?= $content ?>
    </main>
</div>
</body>
</html>
