<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = \TripleR\Security\Csrf::token();
$displayDate = static function (?string $value): string {
    if ($value === null) {
        return '—';
    }
    $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s T');
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessions | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="topbar"><a class="brand" href="/staff/notifications">Triple R Gensan</a><a href="/admin/users">Back to users</a></header>
<main class="page-shell">
    <section class="page-heading"><div><p class="eyebrow">System administration</p><h1>Active sessions</h1><p><?= $escape((string) $target['email']) ?></p></div></section>
    <section class="panel"><div class="table-wrap"><table>
        <thead><tr><th>Created</th><th>Last seen</th><th>Expires</th><th>IP</th><th>User agent</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $session): ?>
            <tr><td><?= $escape($displayDate((string) $session['created_at'])) ?></td><td><?= $escape($displayDate((string) $session['last_seen_at'])) ?></td><td><?= $escape($displayDate((string) $session['expires_at'])) ?></td><td><?= $escape((string) ($session['ip_address'] ?? '')) ?></td><td><?= $escape((string) ($session['user_agent'] ?? '')) ?></td><td>
            <form method="post" action="/admin/sessions/invalidate" data-auth-form><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>"><input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>"><button class="button-small button-danger" type="submit">Invalidate</button></form>
            </td></tr>
        <?php endforeach; ?>
        <?php if ($sessions === []): ?><tr><td colspan="6">No active sessions found.</td></tr><?php endif; ?>
        </tbody>
    </table></div></section>
</main>
</body>
</html>
