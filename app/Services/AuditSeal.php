<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;

/**
 * Tamper evidence for the history tables.
 *
 * Triggers stop the application from changing history, but an account that can drop triggers
 * (the migration account) could still change or remove rows without a trace. A seal is a running
 * SHA-256 over every row of a table in primary-key order, stored in audit_seals with the row
 * count and the last id. Recomputing later must give the same hash for the same rows; any row
 * changed, removed or inserted out of order before a seal's last id makes it differ.
 *
 * Only tables whose rows never change are sealed (payments and the two SMS/consent ledgers allow
 * one legitimate update per row and are left out). Copy the hashes bin/audit-seal.php prints
 * somewhere the database accounts cannot reach (a log host, an email) so the seals themselves
 * can be checked too.
 */
final class AuditSeal
{
    /** Table => primary key. */
    public const TABLES = [
        'security_logs' => 'id',
        'status_logs' => 'status_log_id',
        'rental_charges' => 'charge_id',
        'customer_notes' => 'note_id',
        'customer_identity_document_audit_logs' => 'audit_id',
        'vehicle_mileage_logs' => 'mileage_log_id',
        'damage_reports' => 'report_id',
        'damage_liability_decisions' => 'decision_id',
        'record_lifecycle_logs' => 'log_id',
    ];
    private const START = '0000000000000000000000000000000000000000000000000000000000000000';
    private const BATCH = 2000;

    public function __construct(private readonly PDO $db) {}

    /**
     * Seals every table as it is now.
     *
     * @return list<array{table:string,last_row_id:int,row_count:int,chain_hash:string}>
     */
    public function sealAll(): array
    {
        $insert = $this->db->prepare('INSERT INTO audit_seals (table_name, last_row_id, row_count, chain_hash) VALUES (:table, :last, :count, :hash)');
        $seals = [];
        foreach (self::TABLES as $table => $key) {
            $state = ['hash' => self::START, 'count' => 0, 'last' => 0];
            $this->walk($table, $key, null, static function (int $id, string $hash, int $count) use (&$state): void {
                $state = ['hash' => $hash, 'count' => $count, 'last' => $id];
            });
            $insert->execute(['table' => $table, 'last' => $state['last'], 'count' => $state['count'], 'hash' => $state['hash']]);
            $seals[] = ['table' => $table, 'last_row_id' => $state['last'], 'row_count' => $state['count'], 'chain_hash' => $state['hash']];
        }
        return $seals;
    }

    /**
     * Recomputes every table and compares with every seal recorded for it.
     *
     * @return list<string> one line per seal that no longer matches; empty when all match
     */
    public function verifyAll(): array
    {
        $problems = [];
        $seals = $this->db->prepare('SELECT seal_id, last_row_id, row_count, chain_hash, sealed_at FROM audit_seals WHERE table_name = :table ORDER BY last_row_id, seal_id');
        foreach (self::TABLES as $table => $key) {
            $seals->execute(['table' => $table]);
            $pending = $seals->fetchAll();
            if ($pending === []) {
                continue;
            }
            $index = 0;
            $check = static function (int $id, string $hash, int $count) use (&$pending, &$index, &$problems, $table): void {
                // A seal whose last row was passed without being seen: that row is gone.
                while ($index < count($pending) && (int) $pending[$index]['last_row_id'] < $id) {
                    $seal = $pending[$index++];
                    $problems[] = "{$table}: seal #{$seal['seal_id']} of {$seal['sealed_at']} names row {$seal['last_row_id']}, which is no longer there";
                }
                while ($index < count($pending) && (int) $pending[$index]['last_row_id'] === $id) {
                    $seal = $pending[$index++];
                    if ($hash !== $seal['chain_hash'] || $count !== (int) $seal['row_count']) {
                        $problems[] = "{$table}: seal #{$seal['seal_id']} of {$seal['sealed_at']} (through row {$id}) no longer matches; "
                            . "{$count} rows now, {$seal['row_count']} when sealed";
                    }
                }
            };
            $this->walk($table, $key, (int) end($pending)['last_row_id'], $check);
            // An empty table, and seals whose last row is gone, are checked here.
            for (; $index < count($pending); $index++) {
                $seal = $pending[$index];
                if (!((int) $seal['row_count'] === 0 && (int) $seal['last_row_id'] === 0)) {
                    $problems[] = "{$table}: seal #{$seal['seal_id']} of {$seal['sealed_at']} names row {$seal['last_row_id']}, which is no longer there";
                }
            }
        }
        return $problems;
    }

    /** Feeds the running hash after each row (in key order, up to $through) to $after(id, hash, count). */
    private function walk(string $table, string $key, ?int $through, callable $after): void
    {
        $hash = self::START;
        $count = 0;
        $last = 0;
        $select = $this->db->prepare("SELECT * FROM {$table} WHERE {$key} > :last" . ($through === null ? '' : " AND {$key} <= :through") . " ORDER BY {$key} LIMIT " . self::BATCH);
        do {
            $select->execute($through === null ? ['last' => $last] : ['last' => $last, 'through' => $through]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $last = (int) $row[$key];
                $values = array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);
                $hash = hash('sha256', $hash . "\n" . json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                $after($last, $hash, ++$count);
            }
        } while (count($rows) === self::BATCH);
    }
}
