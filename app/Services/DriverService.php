<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use TripleR\Repositories\DriverRepository;

final class DriverService
{
    public function __construct(private readonly PDO $db, private readonly DriverRepository $drivers, private readonly DriverPiiCipher $cipher) {}

    public function validate(array $input, ?array $existing = null): array
    {
        $name = trim((string)($input['full_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Enter a driver name up to 160 characters.');
        $rawLicense = trim((string)($input['license_number'] ?? ''));
        if ($rawLicense === '' && $existing === null) throw new RuntimeException('Enter the driver license number.');
        if ($rawLicense === '') {
            $licenseCipher = $existing['license_number_ciphertext'];
            $licenseFingerprint = $existing['license_number_fingerprint'];
        } else {
            $normalized = DriverPiiCipher::normalizeLicense($rawLicense);
            $licenseCipher = $this->cipher->encrypt($rawLicense, 'driver-license');
            $licenseFingerprint = $this->cipher->licenseFingerprint($normalized);
        }
        $expiry = trim((string)($input['license_expiry'] ?? ''));
        if ($expiry === '' && $existing !== null) $expiry = $existing['license_expiry'];
        $expiry = $this->validDate($expiry, 'license expiry');

        $address = trim((string)($input['address'] ?? ''));
        if (($input['clear_address'] ?? '') === '1') {
            $addressCipher = null;
        } elseif ($address === '') {
            $addressCipher = $existing['address_ciphertext'] ?? null;
        } else {
            $addressCipher = $this->cipher->encrypt($this->bounded($address, 1000, 'address'), 'driver-address');
        }

        $emergencyName = trim((string)($input['emergency_contact_name'] ?? ''));
        $emergencyPhone = trim((string)($input['emergency_contact_phone'] ?? ''));
        if (($input['clear_emergency_contact'] ?? '') === '1') {
            $emergencyNameCipher = $emergencyPhoneCipher = null;
        } elseif ($emergencyName === '' && $emergencyPhone === '' && $existing !== null) {
            $emergencyNameCipher = $existing['emergency_contact_name_ciphertext'];
            $emergencyPhoneCipher = $existing['emergency_contact_phone_ciphertext'];
        } elseif ($emergencyName === '' && $emergencyPhone === '') {
            $emergencyNameCipher = $emergencyPhoneCipher = null;
        } elseif ($emergencyName === '' || $emergencyPhone === '') {
            throw new RuntimeException('Enter both emergency contact name and phone number.');
        } else {
            $emergencyNameCipher = $this->cipher->encrypt($this->bounded($emergencyName, 160, 'emergency contact name'), 'driver-emergency-name');
            $normalizedPhone = DriverPiiCipher::normalizeContact('phone', $emergencyPhone);
            $emergencyPhoneCipher = $this->cipher->encrypt($normalizedPhone, 'driver-emergency-phone');
        }

        $notes = trim((string)($input['notes'] ?? ''));
        if (mb_strlen($notes) > 5000) throw new RuntimeException('Notes are limited to 5,000 characters. Do not enter license or contact details in notes.');
        return ['full_name'=>$name,'license_ciphertext'=>$licenseCipher,'license_fingerprint'=>$licenseFingerprint,'license_expiry'=>$expiry,'address_ciphertext'=>$addressCipher,'emergency_name_ciphertext'=>$emergencyNameCipher,'emergency_phone_ciphertext'=>$emergencyPhoneCipher,'notes'=>$notes === '' ? null : $notes];
    }

    public function create(array $input, int $actor): int
    {
        $data = $this->validate($input);
        $contacts = [];
        foreach (['phone','email'] as $type) {
            $value = trim((string)($input[$type] ?? ''));
            if ($value !== '') $contacts[] = [$type, $this->contactCipher($type, $value)];
        }
        return $this->transaction(function() use ($data,$contacts,$actor): int {
            $id = $this->drivers->create($data);
            $this->drivers->appendStatus($id, null, 'active', $actor);
            foreach ($contacts as [$type,$cipher]) $this->drivers->addContact($id, $type, $cipher, true);
            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $this->transaction(function() use ($id,$input): void {
            $existing = $this->drivers->find($id, true);
            if (!$existing) throw new RuntimeException('Driver not found.');
            $this->drivers->update($id, $this->validate($input, $existing));
        });
    }

    public function addContact(int $driverId, string $type, string $value, bool $primary): int
    {
        $cipher = $this->contactCipher($type, $value);
        return $this->transaction(function() use ($driverId,$type,$cipher,$primary): int {
            if (!$this->drivers->find($driverId, true)) throw new RuntimeException('Driver not found.');
            return $this->drivers->addContact($driverId,$type,$cipher,$primary);
        });
    }

    public function updateContact(int $driverId, int $contactId, string $type, string $value, bool $primary): void
    {
        $cipher = $this->contactCipher($type, $value);
        $this->transaction(function() use ($driverId,$contactId,$type,$cipher,$primary): void {
            if (!$this->drivers->find($driverId,true) || !$this->drivers->findContact($contactId,$driverId)) throw new RuntimeException('Driver contact not found.');
            $this->drivers->updateContact($contactId,$driverId,$type,$cipher,$primary);
        });
    }

    public function removeContact(int $driverId, int $contactId): void
    {
        $this->transaction(function() use ($driverId,$contactId): void {
            if (!$this->drivers->find($driverId,true) || !$this->drivers->findContact($contactId,$driverId)) throw new RuntimeException('Driver contact not found.');
            $this->drivers->removeContact($contactId,$driverId);
        });
    }

    public function changeStatus(int $id, string $status, int $actor): void
    {
        if (!in_array($status,['active','inactive'],true)) throw new RuntimeException('Choose active or inactive status.');
        $this->transaction(function() use ($id,$status,$actor): void {
            $driver = $this->drivers->find($id,true);
            if (!$driver) throw new RuntimeException('Driver not found.');
            if ($driver['status'] === $status) throw new RuntimeException('Driver already has that status.');
            $this->db->prepare('UPDATE drivers SET status=:status WHERE driver_id=:id AND deleted_at IS NULL')->execute(['status'=>$status,'id'=>$id]);
            $this->drivers->appendStatus($id,$driver['status'],$status,$actor);
        });
    }

    public function softDelete(int $id): void
    {
        $this->transaction(function() use ($id): void {
            if (!$this->drivers->find($id,true)) throw new RuntimeException('Driver not found.');
            if ($this->drivers->hasOpenAgreement($id)) throw new RuntimeException('Driver has an open rental agreement and cannot be removed.');
            $this->db->prepare('UPDATE drivers SET deleted_at=UTC_TIMESTAMP(6) WHERE driver_id=:id AND deleted_at IS NULL')->execute(['id'=>$id]);
        });
    }

    public function selectableForAssignment(): array
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        return $this->drivers->selectableForAssignment($today);
    }

    public function reveal(int $driverId, string $kind, ?int $recordId = null): string
    {
        $driver = $this->drivers->find($driverId, false, true);
        if (!$driver) throw new RuntimeException('Driver not found.');
        return match ($kind) {
            'license' => $this->cipher->decrypt($driver['license_number_ciphertext'],'driver-license'),
            'address' => $driver['address_ciphertext'] === null ? '' : $this->cipher->decrypt($driver['address_ciphertext'],'driver-address'),
            'emergency_name' => $driver['emergency_contact_name_ciphertext'] === null ? '' : $this->cipher->decrypt($driver['emergency_contact_name_ciphertext'],'driver-emergency-name'),
            'emergency_phone' => $driver['emergency_contact_phone_ciphertext'] === null ? '' : $this->cipher->decrypt($driver['emergency_contact_phone_ciphertext'],'driver-emergency-phone'),
            'contact' => $this->revealContact($driverId, $recordId),
            default => throw new RuntimeException('Unsupported driver value.'),
        };
    }

    public function masked(string $ciphertext, string $kind, ?string $contactType = null): string
    {
        $context = match ($kind) {
            'license' => 'driver-license', 'address' => 'driver-address', 'emergency_name' => 'driver-emergency-name',
            'emergency_phone' => 'driver-emergency-phone', 'contact' => 'driver-contact:' . $contactType,
        };
        $value = $this->cipher->decrypt($ciphertext,$context);
        if ($kind === 'address') return 'Address on file';
        if ($kind === 'emergency_name') return 'Name on file';
        return $kind === 'license' ? '****' . substr(DriverPiiCipher::normalizeLicense($value),-4) : DriverPiiCipher::mask((string)$contactType,$value);
    }

    private function revealContact(int $driverId, ?int $contactId): string
    {
        if ($contactId === null) throw new RuntimeException('Driver contact not found.');
        $contact = $this->drivers->findContact($contactId,$driverId);
        if (!$contact) throw new RuntimeException('Driver contact not found.');
        return $this->cipher->decrypt($contact['contact_ciphertext'],'driver-contact:' . $contact['contact_type']);
    }

    private function contactCipher(string $type, string $value): string
    {
        $normalized = DriverPiiCipher::normalizeContact($type,$value);
        return $this->cipher->encrypt($normalized,'driver-contact:' . $type);
    }

    private function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try { $result=$work(); $this->db->commit(); return $result; }
        catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function validDate(string $value, string $label): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Enter a valid ' . $label . ' date.');
        return $value;
    }

    private function bounded(string $value, int $max, string $label): string
    {
        if (mb_strlen($value) > $max) throw new RuntimeException('The ' . $label . ' is too long.');
        return $value;
    }
}
