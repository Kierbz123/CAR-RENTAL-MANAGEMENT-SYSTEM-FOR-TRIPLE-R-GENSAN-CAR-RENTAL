<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use RuntimeException;
use DateTimeImmutable;
use DateTimeZone;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\VehicleRepository;

final class ChauffeurService
{
    public function __construct(private readonly PDO $db, private readonly RentalRepository $rentals, private readonly ChargeRepository $charges, private readonly VehicleRepository $vehicles, private readonly BookingOverlapService $overlaps) {}

    public function assignDriver(int $agreementId, int $driverId, int $actor): void
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            $snapshot = $this->rentals->find($agreementId);
            if (!$snapshot) throw new RuntimeException('Rental agreement not found.');
            if ($snapshot['rental_type'] !== 'chauffeur') throw new RuntimeException('Only chauffeur rentals can be assigned a driver.');
            if (!in_array($snapshot['status'], ['reserved', 'confirmed'], true)) throw new RuntimeException('Cannot assign a driver after the agreement is active or terminal.');

            $this->rentals->lockVehicle((int)$snapshot['vehicle_id']);
            $this->rentals->lockCustomer((int)$snapshot['customer_id']);
            $driver = $this->rentals->lockDriver($driverId);
            if (!$driver || $driver['status'] !== 'active') throw new RuntimeException('Choose an active driver.');
            if (!$this->licenseIsValidToday($driver)) throw new RuntimeException('The driver license has expired. Choose a driver with a valid license.');

            // Check overlap
            if ($this->overlaps->driverConflicts($driverId, $snapshot['start_date'], $snapshot['end_date'], $agreementId)) {
                throw new RuntimeException('This driver already has an overlapping rental assignment.');
            }

            $r = $this->rentals->lockAgreement($agreementId);
            if (!$r || $r['status'] !== $snapshot['status']) throw new RuntimeException('The agreement changed in another request. Reload and try again.');

            $vehicle = $this->vehicles->find((int)$r['vehicle_id']);
            if ($vehicle['chauffeur_daily_rate'] === null) throw new RuntimeException('This vehicle is not available for chauffeur rentals.');

            if ((string)$r['driver_id'] !== (string)$driverId) {
                // Remove old fee if exists
                $this->reverseChauffeurFee($agreementId, $actor);
                
                // Add new fee
                $fee = (int)$r['rental_days'] * $this->toCents((string)$vehicle['chauffeur_daily_rate']);
                $formattedFee = $this->centsToMoney($fee);
                $this->charges->appendCharge($agreementId, 'chauffeur_fee', $formattedFee, 'Chauffeur fee (' . $r['rental_days'] . ' days at ₱' . $vehicle['chauffeur_daily_rate'] . '/day)', $actor);
                
                // Update agreement
                $q = $this->db->prepare('UPDATE rental_agreements SET driver_id=:driver WHERE agreement_id=:id');
                $q->execute(['driver' => $driverId, 'id' => $agreementId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function removeDriver(int $agreementId, int $actor): void
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            $snapshot = $this->rentals->find($agreementId);
            if (!$snapshot) throw new RuntimeException('Rental agreement not found.');
            if ($snapshot['rental_type'] !== 'chauffeur') throw new RuntimeException('Only chauffeur rentals have assigned drivers.');
            if ($snapshot['status'] !== 'reserved') throw new RuntimeException('Driver can only be removed from reserved agreements (confirmed agreements require a driver reassignment instead).');

            $this->rentals->lockVehicle((int)$snapshot['vehicle_id']);
            $this->rentals->lockCustomer((int)$snapshot['customer_id']);
            if ($snapshot['driver_id'] !== null) $this->rentals->lockDriver((int)$snapshot['driver_id']);
            $r = $this->rentals->lockAgreement($agreementId);
            if (!$r || $r['status'] !== $snapshot['status']) throw new RuntimeException('The agreement changed in another request. Reload and try again.');

            if ($r['driver_id'] !== null) {
                $this->reverseChauffeurFee($agreementId, $actor);
                $q = $this->db->prepare('UPDATE rental_agreements SET driver_id=NULL WHERE agreement_id=:id');
                $q->execute(['id' => $agreementId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function validateForConfirmation(array $agreement, ?array $driver): void
    {
        if ($agreement['rental_type'] === 'chauffeur') {
            if ($agreement['driver_id'] === null) {
                throw new RuntimeException('A driver must be assigned before confirming a chauffeur agreement.');
            }
            if (!$driver || (int)$driver['driver_id'] !== (int)$agreement['driver_id'] || $driver['status'] !== 'active') {
                throw new RuntimeException('The assigned driver is no longer active. Reassign an active driver before confirming.');
            }
            if (!$this->licenseIsValidToday($driver)) {
                throw new RuntimeException('The assigned driver license has expired. Reassign a driver with a valid license before confirming.');
            }
            if ($this->overlaps->driverConflicts((int)$agreement['driver_id'], $agreement['start_date'], $agreement['end_date'], (int)$agreement['agreement_id'])) {
                throw new RuntimeException('The assigned driver has an overlapping assignment. Reassign the driver before confirming.');
            }
        }
    }

    private function licenseIsValidToday(array $driver): bool
    {
        $manila = new DateTimeZone('Asia/Manila');
        $expiryValue = (string)($driver['license_expiry'] ?? '');
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiryValue, $manila);
        return $expiry !== false
            && $expiry->format('Y-m-d') === $expiryValue
            && $expiry >= new DateTimeImmutable('today', $manila);
    }

    private function reverseChauffeurFee(int $agreementId, int $actor): void
    {
        $charges = $this->charges->forAgreement($agreementId);
        foreach ($charges as $c) {
            if ($c['charge_type'] === 'chauffeur_fee' && $c['entry_kind'] === 'charge' && !$c['is_reversed']) {
                $locked = $this->charges->lockOriginalForReversal($agreementId, (int)$c['charge_id']);
                if ($locked) {
                    $this->charges->appendReversal($agreementId, $locked, 'Reversal: Driver assignment removed or changed', $actor);
                }
            }
        }
    }

    private function toCents(string $amount): int { [$whole,$fraction]=array_pad(explode('.', $amount,2),2,'0');return ((int)$whole*100)+(int)str_pad(substr($fraction,0,2),2,'0'); }
    private function centsToMoney(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
}
