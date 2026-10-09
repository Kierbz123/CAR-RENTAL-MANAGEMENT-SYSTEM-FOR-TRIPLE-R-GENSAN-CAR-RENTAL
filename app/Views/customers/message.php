<?php
declare(strict_types=1);

use TripleR\Services\NotificationService;
use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$id = (int) $customer['customer_id'];
$name = (string) $customer['full_name'];
$back = '/customers/detail?customer_id=' . $id;

View::begin('staff', ['title' => 'Message ' . $name, 'crumbs' => [['Customers', '/customers'], [$name, $back], ['Message', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Customer</p>
        <h1>Send a message</h1>
        <p class="page-lead">Write to <?= $e($name) ?> directly. The message goes to their Telegram when they are connected, otherwise by SMS to their primary phone.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-ghost" href="<?= $e($back) ?>">Back to customer</a>
    </div>
</header>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if (!$hasPhone): ?>
<p class="callout" role="status"><strong>This customer cannot be messaged yet.</strong> They need a primary phone number. <a href="<?= $e($back) ?>">Add one on their customer page</a>, then come back here.</p>
<?php elseif ($channel === null): ?>
<p class="callout" role="status"><strong>A message cannot be sent yet.</strong> <?= $e($name) ?> is not connected to Telegram, and SMS is not set up on this installation. <a href="<?= $e($back) ?>#telegram">Connect them to Telegram on their customer page</a>, then come back here.</p>
<?php else: ?>
<form class="panel" method="post" action="/customers/message/send">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="customer_id" value="<?= $id ?>">
    <input type="hidden" name="nonce" value="<?= $e($nonce) ?>">
    <div class="panel-body">
        <label class="field"><span class="field-label">Message</span><textarea name="message" rows="5" maxlength="<?= NotificationService::DIRECT_MAX_LENGTH ?>" required autofocus><?= $e($draft) ?></textarea><small class="field-hint"><?= $channel === 'telegram' ? 'Goes to this customer’s Telegram.' : 'Goes by SMS to this customer’s primary phone.' ?> Up to <?= NotificationService::DIRECT_MAX_LENGTH ?> characters.</small></label>
    </div>
    <div class="form-actions">
        <button class="button button-primary" type="submit">Send message</button>
        <a class="button button-ghost" href="<?= $e($back) ?>">Cancel</a>
    </div>
</form>
<?php endif; ?>
<section class="panel" aria-labelledby="sent-title">
    <div class="panel-heading">
        <div><h2 id="sent-title">Messages sent</h2><p>Newest first. A message is delivered a few seconds after it is queued.</p></div>
        <a class="button button-secondary button-small" href="/customers/message?customer_id=<?= $id ?>">Refresh</a>
    </div>
    <div class="panel-body">
<?php if (!$messages): ?>
        <p class="muted">No messages have been sent to this customer from here.</p>
<?php else: ?>
        <ol class="timeline">
<?php foreach ($messages as $message): ?>
            <li>
                <div class="timeline-title"><?= Status::badge('message', $message['status']) ?> <?= $message['channel'] === 'telegram' ? 'Telegram' : 'SMS' ?></div>
                <div class="timeline-meta"><?= $e(Format::datetime($message['created_at'])) ?></div>
                <div class="timeline-note"><?= nl2br($e($message['text'])) ?></div>
<?php if ($message['status'] !== 'sent' && $message['last_error']): ?>
                <div class="timeline-meta"><?= $e($message['last_error']) ?></div>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ol>
<?php endif; ?>
    </div>
</section>
<?php View::end(); ?>
