<?php
declare(strict_types=1);

use TripleR\Support\Icon;
use TripleR\Support\View;

/**
 * Entry layout for pages outside the staff workspace: sign-in, password change,
 * secure links, the customer booking page and error pages.
 *
 * Options: title, description, scripts, variant ('split' shows the brand panel
 * beside the card; 'solo' is a single centred card), headline, blurb,
 * back ([href, label]) for the link under the card, wide (true for a card with room for a list).
 */
$e = static fn (mixed $value): string => View::e($value);
$title = (string) ($options['title'] ?? 'Triple R Gensan');
$variant = ($options['variant'] ?? 'solo') === 'split' ? 'split' : 'solo';
$scripts = $options['scripts'] ?? [];
$back = $options['back'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> | Triple R Gensan</title>
<?php if (!empty($options['description'])): ?>
    <meta name="description" content="<?= $e($options['description']) ?>">
<?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
<?php foreach ($scripts as $script): ?>
    <script src="/assets/js/<?= $e($script) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="entry entry--<?= $variant ?>">
<div class="entry-shell">
<?php if ($variant === 'split'): ?>
    <aside class="entry-brand" aria-label="Triple R Gensan Car Rental">
        <a class="entry-brand-logo" href="/"><?= Icon::mark() ?><span class="app-brand-name">TRIPLE R<small>GENSAN · CAR RENTAL</small></span></a>
        <div class="entry-brand-body">
            <p class="entry-brand-headline"><?= $e($options['headline'] ?? 'Every booking, every vehicle, one workspace.') ?></p>
            <p class="entry-brand-blurb"><?= $e($options['blurb'] ?? 'Reservations, fleet readiness, drivers and maintenance for the Triple R team.') ?></p>
        </div>
        <svg class="entry-brand-art" viewBox="0 0 400 400" aria-hidden="true" focusable="false">
            <g fill="none" stroke="currentColor">
                <circle cx="200" cy="200" r="182" stroke-width="26" opacity=".5"/>
                <circle cx="200" cy="200" r="146" stroke-width="6"/>
                <circle cx="200" cy="200" r="56" stroke-width="5"/>
                <circle cx="200" cy="200" r="24" stroke-width="10"/>
                <g stroke-width="7" stroke-linecap="round">
                    <path d="M200 144V58M200 256v86M144 200H58M256 200h86"/>
                    <path d="m160 160-61-61M240 240l61 61M160 240l-61 61M240 160l61-61"/>
                </g>
            </g>
        </svg>
    </aside>
<?php endif; ?>
    <main class="entry-main" id="main">
<?php if ($variant === 'solo'): ?>
        <a class="entry-solo-logo" href="/"><?= Icon::mark() ?><span class="app-brand-name">TRIPLE R<small>GENSAN · CAR RENTAL</small></span></a>
<?php endif; ?>
        <div class="entry-card<?= !empty($options['wide']) ? ' entry-card--wide' : '' ?>">
<?= $content ?>
        </div>
<?php if (is_array($back)): ?>
        <p class="entry-foot"><a href="<?= $e($back[0]) ?>"><?= $e($back[1]) ?></a></p>
<?php endif; ?>
    </main>
</div>
</body>
</html>
