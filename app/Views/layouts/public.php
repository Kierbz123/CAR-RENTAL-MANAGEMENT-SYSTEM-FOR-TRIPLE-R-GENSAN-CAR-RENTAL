<?php
declare(strict_types=1);

use TripleR\Support\Icon;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/**
 * Public site layout: header with section links, the page content, and the footer.
 * Options: title, description, nav (list of [label, href]).
 * Business details come from config/site.php.
 */
$e = static fn (mixed $value): string => View::e($value);
$title = (string) ($options['title'] ?? SiteProfile::get('brand.full_name'));
$description = (string) ($options['description'] ?? '');
$nav = $options['nav'] ?? [];
$brand = (string) SiteProfile::get('brand.name');
$fullName = (string) SiteProfile::get('brand.full_name');
$isDemo = (bool) SiteProfile::get('is_demo');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#132528">
    <title><?= $e($title) ?></title>
<?php if ($description !== ''): ?>
    <meta name="description" content="<?= $e($description) ?>">
    <meta property="og:description" content="<?= $e($description) ?>">
<?php endif; ?>
    <meta property="og:title" content="<?= $e($title) ?>">
    <meta property="og:type" content="website">
<?php if ($isDemo): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/landing.css">
    <script src="/assets/js/landing.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header" data-site-header>
    <a class="site-brand" href="#top" aria-label="<?= $e($fullName) ?> — back to top"><?= Icon::mark() ?><span><?= $e(strtoupper($brand)) ?></span></a>
    <button class="nav-toggle" type="button" aria-controls="site-nav" aria-expanded="false" data-nav-toggle><?= Icon::svg('menu') ?><span>Menu</span></button>
    <nav class="site-nav" id="site-nav" aria-label="Main" data-site-nav>
        <ul>
<?php foreach ($nav as [$label, $href]): ?>
            <li><a href="<?= $e($href) ?>"><?= $e($label) ?></a></li>
<?php endforeach; ?>
        </ul>
        <div class="site-nav-actions">
            <a class="link-quiet" href="/staff/login">Staff sign in</a>
            <a class="button button-primary" href="/book">Book now</a>
        </div>
    </nav>
</header>
<main id="main">
<?= $content ?>
</main>
<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-brand">
            <a class="site-brand" href="#top"><?= Icon::mark() ?><span><?= $e(strtoupper($brand)) ?></span></a>
            <p><?= $e($fullName) ?></p>
        </div>
        <address class="site-footer-contact">
<?php foreach ((array) SiteProfile::get('contact.address_lines', []) as $line): ?>
            <span><?= $e($line) ?></span>
<?php endforeach; ?>
            <a href="<?= $e(SiteProfile::get('contact.phone_href')) ?>"><?= $e(SiteProfile::get('contact.phone_display')) ?></a>
            <span><?= $e(SiteProfile::get('contact.hours')) ?></span>
<?php if (SiteProfile::get('contact.map_url')): ?>
            <a href="<?= $e(SiteProfile::get('contact.map_url')) ?>" target="_blank" rel="noopener">Find us on Google Maps</a>
<?php endif; ?>
        </address>
        <nav class="site-footer-links" aria-label="Footer">
<?php foreach ($nav as [$label, $href]): ?>
            <a href="<?= $e($href) ?>"><?= $e($label) ?></a>
<?php endforeach; ?>
            <a href="/staff/login">Staff sign in</a>
        </nav>
    </div>
    <div class="site-footer-base">
        <span>© <?= date('Y') ?> <?= $e($fullName) ?></span>
<?php if ($isDemo): ?>
        <span class="demo-notice">Demonstration site built for a school project. The vehicle classes, rates and photos shown are illustrative; call to confirm what is available.</span>
<?php endif; ?>
    </div>
</footer>
</body>
</html>
