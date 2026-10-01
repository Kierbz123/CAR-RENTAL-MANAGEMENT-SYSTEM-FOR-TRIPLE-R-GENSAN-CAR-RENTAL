<?php
declare(strict_types=1);

use TripleR\Support\SiteProfile;
use TripleR\Support\View;

$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');

View::begin('entry', [
    'title' => 'Secure link',
    'variant' => 'solo',
    'scripts' => ['magic-links.js'],
]);
?>
<div data-redeem-url="/api/magic-links/redeem">
    <p class="eyebrow">Secure link</p>
    <h1>Continue securely</h1>
</div>
<p class="entry-lead" id="magic-link-status" role="status" aria-live="polite">Preparing your secure link…</p>
<button id="magic-link-continue" class="button button-primary" type="button" disabled>Continue</button>
<p class="entry-help">This one-time link was sent to you by Triple R. If it has expired, call the rental office on <a href="<?= View::e($phoneHref) ?>"><?= View::e($phone) ?></a> and ask for a new one.</p>
<?php View::end(); ?>
