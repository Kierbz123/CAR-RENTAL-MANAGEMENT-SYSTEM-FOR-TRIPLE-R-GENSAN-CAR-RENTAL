<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$pager = new Pager($customers);
$filtered = $search !== '' || $type !== '';

View::begin('staff', ['title' => 'Customers', 'crumbs' => [['Customers', null]], 'scripts' => ['customers.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Customers</h1>
        <p class="page-lead">Everyone who has rented or is about to, and whether they can book.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-primary" href="/customers/new"><?= Icon::svg('plus') ?>Add customer</a>
    </div>
</header>

<section class="panel" aria-labelledby="customer-records">
    <h2 class="visually-hidden" id="customer-records">Customer records</h2>
    <form class="toolbar" method="get" action="/customers" role="search">
        <label class="field">
            <span class="field-label">Search name or company</span>
            <input type="search" name="search" value="<?= $e($search) ?>" maxlength="160" placeholder="Name or company">
        </label>
        <label class="field">
            <span class="field-label">Type</span>
            <select name="type" data-auto-submit>
                <option value="">All types</option>
<?php foreach ($types as $t): ?>
                <option value="<?= $e($t) ?>"<?= $type === $t ? ' selected' : '' ?>><?= $e(Status::label($t)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit"><?= Icon::svg('search') ?>Search</button>
<?php if ($filtered): ?>
        <a class="button button-ghost" href="/customers">Clear</a>
<?php endif; ?>
        <span class="toolbar-summary"><?= $e(Format::plural(count($customers), 'customer')) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Name</th><th scope="col">Company</th><th scope="col">Type</th><th scope="col">Booking status</th><?php if ($telegramOn): ?><th scope="col">Telegram</th><?php endif; ?><th scope="col">Added</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $customer): $href = '/customers/detail?customer_id=' . (int) $customer['customer_id']; $blacklisted = (int) $customer['is_blacklisted'] === 1; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="cell-strong" href="<?= $e($href) ?>"><?= $e($customer['full_name']) ?></a></td>
                    <td><?= $e($customer['company_name'] ?? '—') ?></td>
                    <td><?= $e(Status::label($customer['customer_type'])) ?></td>
                    <td><span class="badge <?= $blacklisted ? 'badge-danger' : 'badge-success' ?>"><?= $blacklisted ? 'Blacklisted' : 'Can book' ?></span></td>
<?php if ($telegramOn): ?>
                    <td><?php if ((int) $customer['telegram_connected'] === 1): ?><span class="badge badge-success">Connected</span><?php else: ?><form method="post" action="/customers/telegram/code"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>"><button class="button button-secondary button-small" type="submit" aria-label="Show the Telegram QR code for <?= $e($customer['full_name']) ?>">Show QR code</button></form><?php endif; ?></td>
<?php endif; ?>
                    <td class="nowrap"><?= $e(Format::utcDate($customer['created_at'])) ?></td>
                    <td class="actions" data-label=""><a href="<?= $e($href) ?>" aria-label="Open <?= $e($customer['full_name']) ?>">Open</a></td>
                </tr>
<?php endforeach; ?>
<?php if (!$customers): ?>
                <tr><td class="empty-state" colspan="<?= $telegramOn ? 7 : 6 ?>"><strong>No customers found</strong><?php if ($filtered): ?><a href="/customers">Clear the search</a><?php else: ?>Add the first customer to get started.<?php endif; ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('customer') ?>
</section>
<?php View::end(); ?>
