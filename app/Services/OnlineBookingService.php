<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Support\PhoneNumber;

/**
 * Booking by the customer, from the public site, with no account.
 *
 * It only gathers and checks what the customer typed. The reservation itself is made by
 * RentalService, the same path staff use, so availability, pricing, the downpayment and the
 * hold follow one set of rules. Online bookings are self-drive; a rental with a driver is
 * arranged with the office.
 */
final class OnlineBookingService
{
    public const POLICY_KEY = 'downpayment_policy';
    private const MAX_DAYS = 30;
    private const MAX_DAYS_AHEAD = 90;
    private const EARLIEST_PICKUP = '06:00';
    private const LATEST_PICKUP = '21:00';

    public function __construct(
        private readonly PDO $db,
        private readonly RentalService $rentals,
        private readonly RentalRepository $agreements,
        private readonly VehicleRepository $vehicles,
        private readonly BookingOverlapService $overlaps,
        private readonly CustomerService $customers,
        private readonly RulesAcceptanceRepository $rules,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** The policy text the customer must accept, as currently published. */
    public function policy(): array
    {
        $policy = $this->rules->currentVersion(self::POLICY_KEY);
        if ($policy === null) {
            throw new RuntimeException('Online booking is not available right now. Please call the rental office.');
        }
        return $policy;
    }

    /** @return list<string> pickup times offered, every half hour within opening hours */
    public function pickupTimes(): array
    {
        $times = [];
        for ($minutes = 6 * 60; $minutes <= 21 * 60; $minutes += 30) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        return $times;
    }

    /**
     * Checks the two dates and returns them with the number of days billed.
     *
     * @return array{start:string,end:string,days:int}
     */
    public function dates(string $start, string $end): array
    {
        $manila = new DateTimeZone('Asia/Manila');
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $manila);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $manila);
        if ($from === false || $to === false || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end) {
            throw new RuntimeException('Choose a pickup date and a return date.');
        }
        $today = new DateTimeImmutable('today', $manila);
        if ($from < $today) {
            throw new RuntimeException('The pickup date cannot be in the past.');
        }
        if ($from > $today->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            throw new RuntimeException('Online bookings can be made up to ' . self::MAX_DAYS_AHEAD . ' days ahead. For later dates, please call the rental office.');
        }
        if ($to <= $from) {
            throw new RuntimeException('The return date must be after the pickup date.');
        }
        $days = (int) $from->diff($to)->days;
        if ($days > self::MAX_DAYS) {
            throw new RuntimeException('Online bookings are for up to ' . self::MAX_DAYS . ' days. For a longer rental, please call the rental office.');
        }
        return ['start' => $start, 'end' => $end, 'days' => $days];
    }

    /** Vehicles free for the whole period, each with its total and the 30% downpayment. */
    public function availableVehicles(string $start, string $end): array
    {
        $period = $this->dates($start, $end);
        $available = [];
        foreach ($this->vehicles->availableForBooking() as $vehicle) {
            if ($this->overlaps->vehicleConflicts((int) $vehicle['vehicle_id'], $start, $end)) {
                continue;
            }
            $totalCents = $period['days'] * self::cents((string) $vehicle['daily_rate']);
            if ($totalCents <= 0) {
                continue;
            }
            $downpaymentCents = intdiv($totalCents * RentalService::DOWNPAYMENT_PERCENT + 50, 100);
            $available[] = [
                'vehicle_id' => (int) $vehicle['vehicle_id'],
                'name' => trim($vehicle['make'] . ' ' . $vehicle['model'] . ' ' . $vehicle['model_year']),
                'details' => ucfirst((string) $vehicle['body_type']) . ' · ' . ucfirst((string) $vehicle['transmission']) . ' · ' . (int) $vehicle['seating_capacity'] . ' seats · ' . ucfirst((string) $vehicle['fuel_type']),
                'daily_rate' => (string) $vehicle['daily_rate'],
                'total' => self::amount($totalCents),
                'downpayment' => self::amount($downpaymentCents),
                'balance' => self::amount($totalCents - $downpaymentCents),
            ];
        }
        usort($available, static fn (array $a, array $b): int => [(float) $a['daily_rate'], $a['name']] <=> [(float) $b['daily_rate'], $b['name']]);
        return $available;
    }

    /**
     * Makes the reservation and records that the customer accepted the downpayment policy.
     *
     * @return int the new agreement's id
     */
    public function book(array $input, string $ip, string $userAgent): int
    {
        if (!$this->rateLimiter->allow('online-booking-ip', $ip, 10, 3600)) {
            throw new RuntimeException('Too many bookings were started from this connection. Please try again in an hour, or call the rental office.');
        }
        $period = $this->dates((string) ($input['start_date'] ?? ''), (string) ($input['end_date'] ?? ''));
        $vehicleId = filter_var($input['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
        if ($vehicleId === false || $vehicleId < 1) {
            throw new RuntimeException('Choose a vehicle.');
        }
        $time = (string) ($input['pickup_time'] ?? '');
        if (!in_array($time, $this->pickupTimes(), true)) {
            throw new RuntimeException('Choose a pickup time between ' . self::EARLIEST_PICKUP . ' and ' . self::LATEST_PICKUP . '.');
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($input['full_name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new RuntimeException('Enter your full name.');
        }
        try {
            $phone = PhoneNumber::normalize((string) ($input['phone'] ?? ''));
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('Enter a valid mobile number, for example 0917 123 4567.');
        }
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254)) {
            throw new RuntimeException('Enter a valid email address, or leave it blank.');
        }
        $policy = $this->policy();
        if (($input['accept_policy'] ?? '') !== '1') {
            throw new RuntimeException('To book, you need to accept the downpayment policy.');
        }
        if ((int) ($input['policy_version_id'] ?? 0) !== (int) $policy['rules_version_id']) {
            throw new RuntimeException('The downpayment policy was updated while you were booking. Please read it again and accept it.');
        }
        if (!$this->rateLimiter->allow('online-booking-phone', $phone, 3, 86400)) {
            throw new RuntimeException('This mobile number has made several bookings today. Please call the rental office to book.');
        }

        // Checked before a customer record is made, so a lost race for a vehicle leaves nothing behind.
        if ($this->overlaps->vehicleConflicts((int) $vehicleId, $period['start'], $period['end'])) {
            throw new RuntimeException('That vehicle was just booked for those dates. Please choose another vehicle or other dates.');
        }
        $actor = $this->systemActor();
        $customer = $this->customers->findByPhone($phone);
        if ($customer !== null && (int) $customer['is_blacklisted'] === 1) {
            throw new RuntimeException('This booking can’t be made online. Please call the rental office.');
        }
        if ($customer !== null && $this->hasUnpaidReservation((int) $customer['customer_id'])) {
            throw new RuntimeException('This mobile number already has a reservation waiting for its downpayment. Use “Find my booking” to open it, or call the rental office.');
        }
        $customerId = $customer !== null
            ? (int) $customer['customer_id']
            : $this->customers->create(['customer_type' => 'online', 'full_name' => $name, 'phone' => $phone, 'email' => $email], $actor);

        $agreementId = $this->rentals->create([
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'rental_type' => 'self_drive',
            'start_date' => $period['start'],
            'end_date' => $period['end'],
            'scheduled_pickup_at' => $period['start'] . 'T' . $time,
            'scheduled_return_at' => $period['end'] . 'T' . $time,
            'deposit_amount' => '0',
        ], $actor, 'online');

        try {
            $this->rules->recordAcceptance((int) $policy['rules_version_id'], $agreementId, $phone, $ip, $userAgent);
        } catch (\Throwable $error) {
            // A booking must never stand without its recorded consent.
            error_log('Policy acceptance could not be recorded for agreement ' . $agreementId . ': ' . get_class($error));
            $this->rentals->transition($agreementId, 'cancel', $actor, 'The customer’s acceptance of the downpayment policy could not be recorded');
            throw new RuntimeException('Your booking could not be completed. Please try again.');
        }
        return $agreementId;
    }

    /** The booking with this reference, if the phone number is one of that customer's own. */
    public function findBooking(string $reference, string $phone, string $ip): ?int
    {
        if (!$this->rateLimiter->allow('booking-lookup-ip', $ip, 10, 900)) {
            throw new RuntimeException('Too many attempts. Please wait 15 minutes, or call the rental office.');
        }
        $reference = strtoupper(preg_replace('/[\s-]+/', '', $reference) ?? '');
        if (preg_match('/^[A-Z0-9]{8}$/', $reference) !== 1) {
            return null;
        }
        $agreementId = $this->agreements->findIdByReference($reference);
        $agreement = $agreementId === null ? null : $this->agreements->find($agreementId);
        if ($agreement === null || !$this->customers->phoneBelongsTo((int) $agreement['customer_id'], $phone)) {
            return null;
        }
        return $agreementId;
    }

    private function hasUnpaidReservation(int $customerId): bool
    {
        $statement = $this->db->prepare("SELECT 1 FROM rental_agreements WHERE customer_id = :customer AND status = 'reserved' AND downpayment_status = 'due' AND booking_source = 'online' LIMIT 1");
        $statement->execute(['customer' => $customerId]);
        return $statement->fetchColumn() !== false;
    }

    /** Records made by the system on a customer's behalf are attributed to the first active administrator. */
    private function systemActor(): int
    {
        $actor = $this->db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        if ($actor === false) {
            throw new RuntimeException('Online booking is not available right now. Please call the rental office.');
        }
        return (int) $actor;
    }

    private static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
