<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$deleted = $customer['deleted_at'] !== null;
$blacklisted = (int) $customer['is_blacklisted'] === 1;

View::begin('staff', ['title' => (string) $customer['full_name'], 'crumbs' => [['Customers', '/customers'], [(string) $customer['full_name'], null]], 'scripts' => ['customers.js']]);
?>
<div data-reveal-url="/customers/reveal" data-csrf="<?= $e($csrfToken) ?>">
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Customer</p>
        <h1><?= $e($customer['full_name']) ?></h1>
        <div class="page-meta">
<?php if ($deleted): ?>
            <span class="badge badge-neutral">Removed</span>
<?php else: ?>
            <span class="badge <?= $blacklisted ? 'badge-danger' : 'badge-success' ?>"><?= $blacklisted ? 'Blacklisted' : 'Can book' ?></span>
<?php endif; ?>
            <span><?= $e(Status::label($customer['customer_type'])) ?></span>
<?php if ($customer['company_name']): ?>
            <span><?= $e($customer['company_name']) ?></span>
<?php endif; ?>
        </div>
    </div>
<?php if (!$deleted): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/customers/edit?customer_id=<?= (int) $customer['customer_id'] ?>">Edit customer</a>
    </div>
<?php endif; ?>
</header>
</div>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($blacklisted && !$deleted): ?>
<p class="callout" role="status"><strong>Blacklisted since <?= $e(Format::datetime($customer['blacklisted_at'])) ?>.</strong> <?= $e($customer['blacklist_reason']) ?></p>
<?php endif; ?>
<?php if ($deleted): ?>
<p class="callout" role="status">This customer was removed on <?= $e(Format::datetime($customer['deleted_at'])) ?>. Past records are kept and can’t be changed.</p>
<?php endif; ?>

<div class="split">
    <div class="split-main">
        <section class="panel" aria-labelledby="contacts-title">
            <div class="panel-heading"><div><h2 id="contacts-title">Phone and email</h2><p>Stored encrypted and hidden until you choose Reveal.</p></div></div>
<?php if (!$deleted): ?>
            <form class="toolbar" method="post" action="/customers/contacts/add">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                <label class="field"><span class="field-label">Type</span><select name="contact_type"><option value="phone">Phone</option><option value="email">Email</option></select></label>
                <label class="field"><span class="field-label">Number or address</span><input name="contact_value" required maxlength="254"></label>
                <label class="check-field"><input type="checkbox" name="is_primary" value="1"> Make primary</label>
                <button class="button button-secondary" type="submit">Add contact</button>
            </form>
<?php endif; ?>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Type</th><th scope="col">Value</th><th scope="col">Primary</th><th scope="col" class="actions">Actions</th></tr></thead>
                    <tbody>
<?php foreach ($contacts as $contact): ?>
                        <tr>
                            <td><?= $e(Status::label($contact['contact_type'])) ?></td>
                            <td><span data-pii-value><?= $e($contact['masked_value']) ?></span> <button type="button" class="button button-secondary button-small" data-reveal-kind="contact" data-record-id="<?= (int) $contact['contact_id'] ?>">Reveal</button></td>
                            <td><?= (int) $contact['is_primary'] === 1 ? '<span class="badge badge-info">Primary</span>' : '—' ?></td>
                            <td class="actions">
<?php if (!$deleted): ?>
                                <div class="cell-actions">
                                    <details class="disclosure">
                                        <summary>Edit</summary>
                                        <form class="disclosure-body" method="post" action="/customers/contacts/update">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                                            <input type="hidden" name="contact_id" value="<?= (int) $contact['contact_id'] ?>">
                                            <label class="field"><span class="field-label">Type</span><select name="contact_type"><option value="phone"<?= $contact['contact_type'] === 'phone' ? ' selected' : '' ?>>Phone</option><option value="email"<?= $contact['contact_type'] === 'email' ? ' selected' : '' ?>>Email</option></select></label>
                                            <label class="field"><span class="field-label">New value</span><input name="contact_value" required maxlength="254"></label>
                                            <label class="check-field"><input type="checkbox" name="is_primary" value="1"<?= $contact['is_primary'] ? ' checked' : '' ?>> Primary</label>
                                            <div><button class="button button-primary button-small" type="submit">Save contact</button></div>
                                        </form>
                                    </details>
                                    <form method="post" action="/customers/contacts/remove" data-confirm="Remove this contact from the customer’s record?" data-confirm-action="Remove contact">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                                        <input type="hidden" name="contact_id" value="<?= (int) $contact['contact_id'] ?>">
                                        <button class="button button-danger button-small" type="submit">Remove</button>
                                    </form>
                                </div>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
<?php if (!$contacts): ?>
                        <tr><td class="empty-state" colspan="4">No phone or email recorded.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel" aria-labelledby="documents-title">
            <div class="panel-heading"><div><h2 id="documents-title">Identity documents</h2><p>Stored encrypted. Numbers are hidden until you choose Reveal.</p></div></div>
<?php if (!$deleted): ?>
            <form class="toolbar" method="post" action="/customers/documents/add">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                <label class="field"><span class="field-label">Document type</span>
                    <select name="document_type" required>
<?php foreach ($documentTypes as $type): ?>
                        <option value="<?= $e($type) ?>"><?= $e(Status::label($type)) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span class="field-label">Document number</span><input name="document_number" required maxlength="100" autocomplete="off"></label>
                <label class="field"><span class="field-label">Expiry date <span class="optional">(optional)</span></span><input type="date" name="expires_on"></label>
                <button class="button button-secondary" type="submit">Add document</button>
            </form>
<?php endif; ?>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Type</th><th scope="col">Number</th><th scope="col">Expiry</th><th scope="col" class="actions">Actions</th></tr></thead>
                    <tbody>
<?php foreach ($documents as $document): ?>
                        <tr>
                            <td><?= $e(Status::label($document['document_type'])) ?></td>
                            <td><span data-pii-value><?= $e($document['masked_value']) ?></span> <button type="button" class="button button-secondary button-small" data-reveal-kind="document" data-record-id="<?= (int) $document['document_id'] ?>">Reveal</button></td>
                            <td class="nowrap"><?= $e(Format::date($document['expires_on'])) ?></td>
                            <td class="actions">
<?php if (!$deleted): ?>
                                <details class="disclosure">
                                    <summary>Correct</summary>
                                    <form class="disclosure-body" method="post" action="/customers/documents/update">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                                        <input type="hidden" name="document_id" value="<?= (int) $document['document_id'] ?>">
                                        <label class="field"><span class="field-label">Document type</span>
                                            <select name="document_type" required>
<?php foreach ($documentTypes as $type): ?>
                                                <option value="<?= $e($type) ?>"<?= $document['document_type'] === $type ? ' selected' : '' ?>><?= $e(Status::label($type)) ?></option>
<?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="field"><span class="field-label">Correct number</span><input name="document_number" required maxlength="100" autocomplete="off"></label>
                                        <label class="field"><span class="field-label">Expiry date</span><input type="date" name="expires_on" value="<?= $e($document['expires_on'] ?? '') ?>"></label>
                                        <div><button class="button button-primary button-small" type="submit">Save correction</button></div>
                                    </form>
                                </details>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
<?php if (!$documents): ?>
                        <tr><td class="empty-state" colspan="4">No identity documents recorded.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
<?php if ($documentAudits): ?>
            <div class="panel-body">
                <details class="disclosure">
                    <summary>Document change history (<?= count($documentAudits) ?>)</summary>
                    <div class="disclosure-body">
                        <p class="muted">Only fingerprints are logged, never the document numbers themselves.</p>
                        <ol class="timeline">
<?php foreach ($documentAudits as $audit): ?>
                            <li>
                                <div class="timeline-title"><?= $e(Status::label($audit['operation'])) ?> · <?= $e(Status::label($audit['document_type'])) ?></div>
                                <div class="timeline-meta"><?= $e(Format::datetime($audit['created_at'])) ?> · <?= $e($audit['actor_email']) ?></div>
                                <div class="timeline-note mono"><?= $e($audit['old_fingerprint'] ?? '—') ?> → <?= $e($audit['new_fingerprint']) ?></div>
                            </li>
<?php endforeach; ?>
                        </ol>
                    </div>
                </details>
            </div>
<?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="rentals-title">
            <div class="panel-heading"><h2 id="rentals-title">Rental history</h2></div>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Agreement</th><th scope="col">Status</th><th scope="col">Start</th><th scope="col">End</th></tr></thead>
                    <tbody>
<?php foreach ($rentals as $rental): $href = '/rentals/detail?agreement_id=' . (int) $rental['agreement_id']; ?>
                        <tr data-href="<?= $e($href) ?>">
                            <td><a class="cell-strong" href="<?= $e($href) ?>">#<?= (int) $rental['agreement_id'] ?></a></td>
                            <td><?= Status::badge('rental', $rental['status']) ?></td>
                            <td class="nowrap"><?= $e(Format::date($rental['start_date'])) ?></td>
                            <td class="nowrap"><?= $e(Format::date($rental['end_date'])) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$rentals): ?>
                        <tr><td class="empty-state" colspan="4">No rentals yet.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside class="split-side" aria-label="Notes and status">
        <section class="panel" aria-labelledby="notes-title">
            <div class="panel-heading"><div><h2 id="notes-title">Staff notes</h2><p>Notes are added, never edited.</p></div></div>
<?php if (!$deleted): ?>
            <form class="panel-body" method="post" action="/customers/notes/add">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                <label class="field"><span class="field-label">New note</span><textarea name="note_text" rows="3" maxlength="10000" required></textarea></label>
                <button class="button button-secondary" type="submit">Add note</button>
            </form>
<?php endif; ?>
            <div class="panel-body">
<?php if (!$notes): ?>
                <p class="muted">No notes yet.</p>
<?php else: ?>
                <ol class="timeline">
<?php foreach ($notes as $note): ?>
                    <li>
                        <div class="timeline-title"><?= $e(Status::label($note['note_type'])) ?></div>
                        <div class="timeline-meta"><?= $e(Format::datetime($note['created_at'])) ?> · <?= $e($note['author_email']) ?></div>
                        <div class="timeline-note"><?= nl2br($e($note['note_text'])) ?></div>
                    </li>
<?php endforeach; ?>
                </ol>
<?php endif; ?>
            </div>
        </section>

<?php if (!$deleted): ?>
        <section class="panel" aria-labelledby="status-title">
            <div class="panel-heading"><div><h2 id="status-title">Booking status</h2><p>Blacklisting blocks new bookings. Rentals already made stay valid.</p></div></div>
            <div class="panel-body">
<?php if ($blacklisted): ?>
                <form method="post" action="/customers/unblacklist" class="stack">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                    <label class="field"><span class="field-label">Reason for lifting the blacklist</span><textarea name="reason" maxlength="500" required rows="2"></textarea></label>
                    <button class="button button-secondary" type="submit">Lift blacklist</button>
                </form>
<?php else: ?>
                <form method="post" action="/customers/blacklist" class="stack">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                    <label class="field"><span class="field-label">Reason for blacklisting</span><textarea name="reason" maxlength="500" required rows="2"></textarea></label>
                    <button class="button button-danger" type="submit">Blacklist customer</button>
                </form>
<?php endif; ?>
            </div>
        </section>
        <section class="panel" aria-labelledby="remove-title">
            <div class="panel-heading"><div><h2 id="remove-title">Remove customer</h2><p>The record is kept for history but leaves every list. A customer with an open agreement can’t be removed.</p></div></div>
            <div class="panel-body">
                <form method="post" action="/customers/delete" data-confirm="Remove <?= $e($customer['full_name']) ?> from the customer list? Their past rentals stay on record." data-confirm-action="Remove customer">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                    <button class="button button-danger" type="submit">Remove customer</button>
                </form>
            </div>
        </section>
<?php endif; ?>
    </aside>
</div>
<?php View::end(); ?>
