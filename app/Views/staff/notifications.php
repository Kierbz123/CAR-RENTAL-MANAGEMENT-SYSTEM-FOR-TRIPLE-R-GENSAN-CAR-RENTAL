<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = \TripleR\Security\Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SMS delivery history | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/notifications.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/staff/notifications">Triple R Gensan</a>
    <div class="staff-actions">
        <span><?= $escape((string) $user['email']) ?></span>
        <?php if ($user['role'] === 'system_admin'): ?><a href="/admin/users">Manage users</a><?php endif; ?>
        <form method="post" action="/staff/logout">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <button class="button-secondary" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main class="page-shell" data-api-url="/api/staff/notifications">
    <section class="page-heading">
        <div>
            <p class="eyebrow">Staff console</p>
            <h1>SMS delivery history</h1>
            <p>Provider queue activity and delivery results.</p>
        </div>
        <div class="metric-card"><span>Provider accepted this month</span><strong id="monthly-count">—</strong></div>
    </section>
    <p id="load-error" class="alert" role="alert" hidden></p>
    <section class="panel" aria-labelledby="history-title">
        <div class="panel-heading"><h2 id="history-title">Recent notifications</h2><span id="last-updated" class="muted">Loading…</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Created</th><th>Recipient</th><th>Template</th><th>Message</th><th>Class</th><th>Status</th><th>Priority</th><th>Attempts</th><th>Provider</th><th>Last error</th></tr></thead>
                <tbody id="notification-rows"><tr><td colspan="10">Loading notifications…</td></tr></tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
