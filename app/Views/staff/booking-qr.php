<?php
declare(strict_types=1);

use TripleR\Support\View;

/* A QR code for the public booking page, to print for the counter or a flyer. It opens the same page as the address. */
$e = static fn (mixed $value): string => View::e($value);

View::begin('staff', ['title' => 'Online booking QR code', 'crumbs' => [['Workspace', '/staff'], ['Online booking QR code', null]], 'scripts' => ['vendor/qrcode-generator.js', 'qr-render.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Online booking QR code</h1>
        <p class="page-lead">Customers who scan this land on the same booking page as the address below. Print it for the counter, a flyer or a vehicle.</p>
    </div>
    <div class="page-header-actions">
        <button class="button button-secondary" type="button" data-print hidden>Print</button>
    </div>
</header>
<section class="panel" aria-labelledby="qr-title">
    <div class="panel-heading"><h2 id="qr-title">Scan to book a vehicle</h2></div>
    <div class="panel-body">
        <div class="qr-code" data-qr="<?= $e($bookingUrl) ?>" role="img" aria-label="QR code that opens the online booking page"></div>
        <p class="connect-link"><a href="<?= $e($bookingUrl) ?>"><?= $e($bookingUrl) ?></a></p>
<?php if ($isLocalAddress): ?>
        <p class="callout" role="status">This address only works on this computer. Start the site with <span class="mono">bin\demo-online.ps1</span> to get a public address, then open this page from that address so the QR code works on a phone.</p>
<?php endif; ?>
    </div>
</section>
<?php View::end(); ?>
