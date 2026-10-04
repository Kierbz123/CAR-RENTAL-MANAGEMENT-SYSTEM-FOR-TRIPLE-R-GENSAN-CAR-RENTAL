<?php
declare(strict_types=1);

use TripleR\Support\Icon;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/**
 * Entry layout for pages outside the staff workspace: sign-in, password change,
 * secure links, the customer booking page and error pages. Dark, like the public
 * site; the arrival of the brand panel and the card is CSS only (app.css, "Motion").
 *
 * Options: title, description, scripts, variant ('split' shows the brand panel
 * beside the card; 'solo' is a single centred card), headline, blurb,
 * back ([href, label]) for the link under the card, wide (true for a card with room for a list),
 * manifest (address of a web app manifest, for a page a phone can add to its home screen).
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
    <meta name="theme-color" content="#0d1b1e">
    <title><?= $e($title) ?> | Triple R Gensan</title>
<?php if (!empty($options['description'])): ?>
    <meta name="description" content="<?= $e($options['description']) ?>">
<?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
<?php if (!empty($options['manifest'])): ?>
    <link rel="manifest" href="<?= $e($options['manifest']) ?>">
<?php endif; ?>
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
            <p class="entry-brand-headline"><?= View::rise((string) ($options['headline'] ?? 'Every booking, every vehicle, one workspace.')) ?></p>
            <p class="entry-brand-blurb"><?= $e($options['blurb'] ?? 'Reservations, fleet readiness, drivers and payments for the Triple R team.') ?></p>
        </div>
        <p class="entry-brand-foot"><span><?= $e(SiteProfile::get('contact.city', '')) ?></span><span><?= $e(SiteProfile::get('contact.hours', '')) ?></span></p>
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
