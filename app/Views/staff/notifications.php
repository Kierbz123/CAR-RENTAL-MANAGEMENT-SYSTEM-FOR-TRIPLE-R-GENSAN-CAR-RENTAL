<?php
declare(strict_types=1);

use TripleR\Support\View;

View::begin('staff', ['title' => 'Notifications', 'crumbs' => [['Administration', null], ['Notifications', null]], 'scripts' => ['notifications.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Notifications</h1>
        <p class="page-lead">Messages queued to customers by SMS or Telegram, and what the provider reported back. Refreshes every 30 seconds.</p>
    </div>
</header>
<div class="stat-grid" data-api-url="/api/staff/notifications">
    <div class="stat-card">
        <span class="stat-label">Accepted this month</span>
        <span class="stat-value" id="monthly-count">—</span>
        <span class="stat-hint">Messages the provider accepted</span>
    </div>
</div>
<p id="load-error" class="alert" role="alert" hidden></p>
<section class="panel" aria-labelledby="history-title">
    <div class="panel-heading"><div><h2 id="history-title">Recent notifications</h2></div><span id="last-updated" class="muted" role="status">Loading…</span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Created</th><th scope="col">Recipient</th><th scope="col">Channel</th><th scope="col">Template</th><th scope="col">Message</th><th scope="col">Class</th><th scope="col">Status</th><th scope="col">Priority</th><th scope="col">Attempts</th><th scope="col">Provider</th><th scope="col">Last error</th></tr></thead>
            <tbody id="notification-rows"><tr><td class="empty-state" colspan="11">Loading notifications…</td></tr></tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
