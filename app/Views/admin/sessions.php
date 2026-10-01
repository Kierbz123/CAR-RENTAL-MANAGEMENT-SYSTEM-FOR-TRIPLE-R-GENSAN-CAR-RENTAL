<?php
declare(strict_types=1);

use TripleR\Security\Csrf;
use TripleR\Support\Format;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$csrfToken = Csrf::token();

View::begin('staff', ['title' => 'Sessions', 'crumbs' => [['Administration', null], ['Staff accounts', '/admin/users'], ['Sessions', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Active sessions</h1>
        <p class="page-lead">Browsers currently signed in as <strong><?= $e((string) $target['email']) ?></strong>. Ending a session signs that browser out.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-secondary" href="/admin/users">Back to staff accounts</a>
    </div>
</header>

<section class="panel" aria-labelledby="sessions-title">
    <h2 class="visually-hidden" id="sessions-title">Sessions</h2>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Signed in</th><th scope="col">Last seen</th><th scope="col">Expires</th><th scope="col">IP address</th><th scope="col">Browser</th><th scope="col" class="actions">Action</th></tr></thead>
            <tbody>
<?php foreach ($sessions as $session): ?>
                <tr>
                    <td class="nowrap"><?= $e(Format::datetime($session['created_at'])) ?></td>
                    <td class="nowrap"><?= $e(Format::datetime($session['last_seen_at'])) ?></td>
                    <td class="nowrap"><?= $e(Format::datetime($session['expires_at'])) ?></td>
                    <td class="mono"><?= $e((string) ($session['ip_address'] ?? '—')) ?></td>
                    <td><span class="cell-sub"><?= $e((string) ($session['user_agent'] ?? '—')) ?></span></td>
                    <td class="actions">
                        <form method="post" action="/admin/sessions/invalidate" data-confirm="End this session? That browser will be signed out." data-confirm-action="End session">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
                            <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                            <button class="button button-danger button-small" type="submit">End session</button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
<?php if ($sessions === []): ?>
                <tr><td class="empty-state" colspan="6"><strong>No active sessions</strong>This account isn’t signed in anywhere.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="panel-note">Times are shown in Manila time.</p>
</section>
<?php View::end(); ?>
