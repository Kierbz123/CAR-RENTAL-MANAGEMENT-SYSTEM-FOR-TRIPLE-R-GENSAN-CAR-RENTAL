<?php
declare(strict_types=1);

use TripleR\Support\View;

/** Shared error page. Expects $status (int) and $message (plain text). */
$e = static fn (mixed $value): string => View::e($value);
$titles = [
    403 => 'You can’t open this page',
    404 => 'Page not found',
    409 => 'That change conflicts with an existing record',
    415 => 'That file type isn’t supported',
    422 => 'That request couldn’t be completed',
    429 => 'Too many attempts',
    500 => 'Something went wrong',
];
$heading = $titles[$status] ?? ($status >= 500 ? 'Something went wrong' : 'That request couldn’t be completed');
$signedIn = View::user() !== null;
// A rejected form post is best fixed by going back to the form; a missing page is not.
$canGoBack = in_array($status, [409, 415, 422], true) || ($status === 403 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
// Only link back to a page on this site.
$fallback = $signedIn ? '/staff' : '/';
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$refererHost = (string) (parse_url($referer, PHP_URL_HOST) ?? '');
$refererScheme = (string) (parse_url($referer, PHP_URL_SCHEME) ?? '');
$sameSite = in_array($refererScheme, ['http', 'https'], true)
    && $refererHost !== ''
    && strcasecmp($refererHost, (string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) === 0;
$backHref = $sameSite ? $referer : $fallback;

View::begin('entry', ['title' => $heading, 'variant' => 'solo']);
?>
<p class="eyebrow">Error <?= (int) $status ?></p>
<h1><?= $e($heading) ?></h1>
<p class="entry-lead"><?= $e($message) ?></p>
<div class="entry-actions">
<?php if ($canGoBack): ?>
    <a class="button button-primary" href="<?= $e($backHref) ?>">Go back and try again</a>
<?php endif; ?>
    <a class="button <?= $canGoBack ? 'button-secondary' : 'button-primary' ?>" href="<?= $fallback ?>"><?= $signedIn ? 'Go to the workspace' : 'Go to the home page' ?></a>
</div>
<?php View::end(); ?>
