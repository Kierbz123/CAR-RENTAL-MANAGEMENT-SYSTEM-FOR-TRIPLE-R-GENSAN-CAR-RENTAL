<?php
/**
 * STOP-idempotency re-test.
 *
 * Exercises the exact production code path:
 *   1. Records current inbound_sms_events + rules_acceptances counts.
 *   2. Inserts ONE new STOP inbound event with a unique provider_message_id.
 *   3. Runs consumeStopEvents() — expects exactly 1 imported.
 *   4. Records counts again.
 *   5. Runs consumeStopEvents() a second time — expects exactly 0 imported (idempotent).
 *   6. Records counts a third time — must match step 4.
 *
 * Uses the live database; does NOT truncate anything.
 * Run: php bin/test-stop-idempotency.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\RulesAcceptanceRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$inbound = new InboundSmsEventRepository($db);
$rules   = new RulesAcceptanceRepository($db);

// Unique values never used anywhere else.
$uniqueMessageId = 'STOP-IDEM-TEST-' . bin2hex(random_bytes(8));
$testPhone       = '+639990000001';

echo "=== STOP Idempotency Re-Test ===" . PHP_EOL;
echo "provider_message_id: {$uniqueMessageId}" . PHP_EOL;
echo "phone:               {$testPhone}" . PHP_EOL . PHP_EOL;

// --- Step 1: Baseline counts ---
$inboundBefore = (int) $db->query("SELECT COUNT(*) FROM inbound_sms_events WHERE event_type='stop'")->fetchColumn();
$rulesBefore   = (int) $db->query("SELECT COUNT(*) FROM rules_acceptances")->fetchColumn();
echo "Step 1 — Before insertion:" . PHP_EOL;
echo "  inbound_sms_events (type=stop): {$inboundBefore}" . PHP_EOL;
echo "  rules_acceptances:              {$rulesBefore}" . PHP_EOL . PHP_EOL;

// --- Step 2: Insert one STOP event ---
$inserted = $inbound->append($uniqueMessageId, 'test', '{"test":true}', $testPhone, 'stop', 'STOP');
echo "Step 2 — INSERT IGNORE result: rowCount=" . ($inserted ? '1 (new row)' : '0 (duplicate)') . PHP_EOL;
$inboundAfterInsert = (int) $db->query("SELECT COUNT(*) FROM inbound_sms_events WHERE event_type='stop'")->fetchColumn();
echo "  inbound_sms_events (type=stop): {$inboundAfterInsert} (expected " . ($inboundBefore + 1) . ")" . PHP_EOL . PHP_EOL;

// --- Step 3: First consumeStopEvents() ---
$firstImport = $rules->consumeStopEvents();
$rulesAfterFirst = (int) $db->query("SELECT COUNT(*) FROM rules_acceptances")->fetchColumn();
echo "Step 3 — First consumeStopEvents():" . PHP_EOL;
echo "  Imported STOP events:  {$firstImport} (expected 1)" . PHP_EOL;
echo "  rules_acceptances now: {$rulesAfterFirst} (expected " . ($rulesBefore + 1) . ")" . PHP_EOL;

// Verify the actual row exists.
$proof = $db->prepare("SELECT acceptance_id, phone, action, provider_message_id FROM rules_acceptances WHERE provider_message_id = :msg");
$proof->execute(['msg' => $uniqueMessageId]);
$proofRow = $proof->fetch(PDO::FETCH_ASSOC);
echo "  Row proof: " . ($proofRow ? json_encode($proofRow) : 'NOT FOUND — ERROR') . PHP_EOL . PHP_EOL;

// --- Step 4: Second consumeStopEvents() (replay) ---
$secondImport = $rules->consumeStopEvents();
$rulesAfterSecond = (int) $db->query("SELECT COUNT(*) FROM rules_acceptances")->fetchColumn();
echo "Step 4 — Second consumeStopEvents() (replay):" . PHP_EOL;
echo "  Imported STOP events:  {$secondImport} (expected 0)" . PHP_EOL;
echo "  rules_acceptances now: {$rulesAfterSecond} (expected {$rulesAfterFirst})" . PHP_EOL . PHP_EOL;

// --- Step 5: Also verify INSERT IGNORE on inbound_sms_events deduplicates ---
$duplicateInsert = $inbound->append($uniqueMessageId, 'test', '{"test":true,"dup":true}', $testPhone, 'stop', 'STOP');
$inboundAfterDup = (int) $db->query("SELECT COUNT(*) FROM inbound_sms_events WHERE event_type='stop'")->fetchColumn();
echo "Step 5 — Duplicate inbound insert:" . PHP_EOL;
echo "  INSERT IGNORE result:            rowCount=" . ($duplicateInsert ? '1 (ERROR — should be 0)' : '0 (correctly deduplicated)') . PHP_EOL;
echo "  inbound_sms_events (type=stop): {$inboundAfterDup} (expected {$inboundAfterInsert})" . PHP_EOL . PHP_EOL;

// --- Verdict ---
$pass =
    $inserted === true
    && $firstImport === 1
    && $rulesAfterFirst === $rulesBefore + 1
    && $proofRow !== false
    && $secondImport === 0
    && $rulesAfterSecond === $rulesAfterFirst
    && $duplicateInsert === false
    && $inboundAfterDup === $inboundAfterInsert;

echo "=== VERDICT: " . ($pass ? 'PASS ✓' : 'FAIL ✗') . " ===" . PHP_EOL;
exit($pass ? 0 : 1);
