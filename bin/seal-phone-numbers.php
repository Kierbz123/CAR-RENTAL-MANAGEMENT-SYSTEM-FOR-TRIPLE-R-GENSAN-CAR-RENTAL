<?php
/**
 * Replaces the plain mobile numbers that rows written before migration 026 still hold with their
 * sealed form: masked in the old column, encrypted and fingerprinted beside it (see
 * app/Services/PhoneVault.php). Covers the SMS queue, the inbound SMS ledger (its stored payload
 * too), the consent ledger and the daily SMS-limit counters.
 *
 * Run once after migrating, with the normal application settings (it needs CUSTOMER_PII_KEY).
 * Running it again changes nothing. The application works before it is run; it only removes the
 * plain numbers that are left.
 *   php bin/seal-phone-numbers.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Services\PhoneVault;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $db = Database::connection();
    $vault = new PhoneVault();
    $tables = [
        // table, key column, phone column, ciphertext column, fingerprint column
        ['notifications', 'id', 'recipient_phone', 'recipient_ciphertext', 'recipient_fingerprint'],
        ['inbound_sms_events', 'id', 'sender_number', 'sender_ciphertext', 'sender_fingerprint'],
        ['rules_acceptances', 'acceptance_id', 'phone', 'phone_ciphertext', 'phone_fingerprint'],
    ];
    foreach ($tables as [$table, $key, $phone, $cipher, $fingerprint]) {
        $sealed = 0;
        $skipped = [];
        $payload = $table === 'inbound_sms_events' ? ', raw_payload' : '';
        $update = $db->prepare("UPDATE {$table} SET {$phone} = :masked, {$cipher} = :cipher, {$fingerprint} = :fingerprint"
            . ($payload !== '' ? ', raw_payload = :payload' : '') . " WHERE {$key} = :id AND {$fingerprint} IS NULL");
        $lastId = 0;
        do {
            $rows = $db->query("SELECT {$key} AS row_id, {$phone} AS phone{$payload} FROM {$table} WHERE {$fingerprint} IS NULL AND {$key} > {$lastId} ORDER BY {$key} LIMIT 500")->fetchAll();
            foreach ($rows as $row) {
                $lastId = (int) $row['row_id'];
                try {
                    $stored = $vault->store((string) $row['phone']);
                } catch (InvalidArgumentException) {
                    $skipped[] = $lastId;
                    continue;
                }
                $values = ['masked' => $stored['masked'], 'cipher' => $stored['ciphertext'], 'fingerprint' => $stored['fingerprint'], 'id' => $lastId];
                if ($payload !== '') {
                    $values['payload'] = InboundSmsEventRepository::redactPayload((string) $row['raw_payload']);
                }
                $update->execute($values);
                $sealed += $update->rowCount();
            }
        } while ($rows !== []);
        echo "{$table}: sealed {$sealed} row(s)" . ($skipped === [] ? '' : '; left ' . count($skipped) . ' whose number could not be read (ids ' . implode(', ', array_slice($skipped, 0, 20)) . ')') . "\n";
    }

    // Daily SMS-limit counters were keyed "<number>|<date>"; they are now "<fingerprint>|<date>".
    $rekeyed = 0;
    $rename = $db->prepare("UPDATE IGNORE rate_counters SET counter_key = :new WHERE scope = 'message_daily' AND counter_key = :old");
    foreach ($db->query("SELECT counter_key FROM rate_counters WHERE scope = 'message_daily' AND counter_key LIKE '+%|%'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
        [$number, $day] = explode('|', (string) $old, 2);
        try {
            $rename->execute(['new' => $vault->fingerprint($number) . '|' . $day, 'old' => $old]);
            $rekeyed += $rename->rowCount();
        } catch (InvalidArgumentException) {
            continue;
        }
    }
    echo "rate_counters: re-keyed {$rekeyed} daily SMS counter(s)\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Sealing failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
