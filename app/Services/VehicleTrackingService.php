<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TripleR\Config;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\VehicleTrackingRepository;
use TripleR\Support\Format;

/**
 * Live tracking of rented vehicles, from real GPS positions.
 *
 * Staff create a tracker link for a rental; a phone travelling with the vehicle opens it at
 * /track and reports its position every few seconds. The map on /fleet/locations shows the
 * latest position of every vehicle that is out, and says so plainly when a vehicle is overdue,
 * outside the service area, or has stopped reporting.
 *
 * A link works only for its own rental, only while that rental is active, and only one link per
 * rental works at a time. The position is kept as the vehicle's latest only; no trail is stored.
 */
final class VehicleTrackingService
{
    /** Positions a phone may send per minute: one every five seconds, with room to spare. */
    private const PINGS_PER_MINUTE = 40;

    private array $settings;

    public function __construct(
        private readonly VehicleTrackingRepository $tracking,
        private readonly RentalRepository $agreements,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogRepository $securityLogs,
    ) {
        $this->settings = require APP_ROOT . '/config/tracking.php';
    }

    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * Creates the link a phone opens to report this rental's position, and switches off any
     * earlier link for it. The link is shown once and only its hash is stored.
     *
     * @return string the full address to open on the phone
     */
    public function createLink(int $agreementId): string
    {
        $agreement = $this->agreements->find($agreementId);
        if ($agreement === null) {
            throw new RuntimeException('Rental agreement not found.');
        }
        if (!in_array($agreement['status'], ['confirmed', 'active'], true)) {
            throw new RuntimeException('A phone can be connected once the reservation is confirmed, and until the vehicle is returned.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $days = max(1, min(90, (int) $this->settings['link_days']));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d H:i:s.u');
        $this->tracking->replaceLink($agreementId, hash('sha256', $token), $expires);
        // The token rides in the fragment, which browsers never send to a server or write to its logs.
        return rtrim(Config::require('APP_BASE_URL'), '/') . '/track#t=' . $token;
    }

    /** Switches off the rental's tracker link, so the connected phone stops reporting. */
    public function disconnect(int $agreementId): bool
    {
        return $this->tracking->revokeLinks($agreementId) > 0;
    }

    public function isConnected(int $agreementId): bool
    {
        return $this->tracking->hasLink($agreementId);
    }

    /**
     * What the tracker page shows for a link: the vehicle, the booking reference and whether
     * positions are wanted yet. Null when the link is not valid; that is logged.
     *
     * @return array{state:string,vehicle:string,reference:string,due_back:?string,interval:int}|null
     */
    public function session(string $token, string $ip = '', string $userAgent = ''): ?array
    {
        $link = $this->link($token, $ip, $userAgent);
        if ($link === null) {
            return null;
        }
        return [
            'state' => self::stateOf((string) $link['status']),
            'vehicle' => trim($link['plate_number'] . ' ' . $link['make'] . ' ' . $link['model']),
            'reference' => (string) $link['booking_reference'],
            'due_back' => $link['scheduled_return_at'] === null ? null : Format::datetime($link['scheduled_return_at']),
            'interval' => max(2, (int) $this->settings['interval_seconds']),
        ];
    }

    /**
     * Takes one position from the phone. Returns the state the phone should show: 'tracking'
     * (saved), 'waiting' (the vehicle has not been picked up yet, nothing saved) or 'ended'
     * (returned, nothing saved). Null when the link is not valid.
     */
    public function report(string $token, array $input, string $ip = '', string $userAgent = ''): ?string
    {
        $link = $this->link($token, $ip, $userAgent);
        if ($link === null) {
            return null;
        }
        $state = self::stateOf((string) $link['status']);
        if ($state !== 'tracking') {
            return $state;
        }
        if (!$this->rateLimiter->allow('tracker-report', (string) $link['token_id'], self::PINGS_PER_MINUTE, 60)) {
            throw new RuntimeException('Positions are arriving too fast. Slow down.');
        }
        $latitude = self::number($input['latitude'] ?? null);
        $longitude = self::number($input['longitude'] ?? null);
        if ($latitude === null || $longitude === null || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new RuntimeException('That is not a position on the map.');
        }
        $accuracy = self::number($input['accuracy'] ?? null);
        $speed = self::number($input['speed'] ?? null);      // metres per second, as phones report it
        $heading = self::number($input['heading'] ?? null);  // degrees clockwise from north

        // What the phone did not say is worked out from where the vehicle was last time.
        $previous = $this->tracking->position((int) $link['vehicle_id']);
        $samePreviousTrip = $previous !== null && (int) $previous['agreement_id'] === (int) $link['agreement_id'];
        $speedKph = $speed !== null && $speed >= 0 ? $speed * 3.6 : null;
        $headingDegrees = $heading !== null && $heading >= 0 ? (int) round($heading) % 360 : null;
        $stoppedSince = null;
        if ($samePreviousTrip) {
            $metres = self::metresBetween((float) $previous['latitude'], (float) $previous['longitude'], $latitude, $longitude);
            $seconds = max(0, time() - (new DateTimeImmutable((string) $previous['recorded_at'], new DateTimeZone('UTC')))->getTimestamp());
            $still = $metres <= (float) $this->settings['stopped_within_metres'];
            if ($speedKph === null && $seconds >= 1 && $seconds <= 300) {
                $speedKph = $still ? 0.0 : $metres / $seconds * 3.6;
            }
            if ($headingDegrees === null) {
                $headingDegrees = $still ? ($previous['heading_degrees'] === null ? null : (int) $previous['heading_degrees']) : self::bearing((float) $previous['latitude'], (float) $previous['longitude'], $latitude, $longitude);
            }
            if ($still) {
                $stoppedSince = (string) ($previous['stopped_since'] ?? $previous['recorded_at']);
            }
        }
        $this->tracking->savePosition(
            (int) $link['vehicle_id'],
            (int) $link['agreement_id'],
            number_format($latitude, 6, '.', ''),
            number_format($longitude, 6, '.', ''),
            $accuracy === null || $accuracy < 0 ? null : (int) min(65535, round($accuracy)),
            $speedKph === null ? null : number_format(min(300.0, $speedKph), 1, '.', ''),
            $headingDegrees,
            $stoppedSince,
        );
        return 'tracking';
    }

    /**
     * Everything the live map shows: one entry per vehicle out on rental, with its position
     * when a phone has reported one, and the words staff read ("Live · 32 km/h", "Last seen 4
     * minutes ago", "Overdue by 2 h 10 min").
     *
     * @return list<array<string,mixed>>
     */
    public function feed(): array
    {
        $quietAfter = (int) $this->settings['quiet_after_seconds'];
        $radius = (float) $this->settings['service_radius_km'];
        $vehicles = [];
        foreach ($this->tracking->vehiclesOut() as $row) {
            $position = null;
            $state = 'none';
            $outside = false;
            $distance = null;
            if ($row['latitude'] !== null) {
                $age = max(0, (int) $row['age_seconds']);
                $state = $age <= $quietAfter ? 'live' : 'quiet';
                $distance = self::metresBetween((float) $this->settings['center']['latitude'], (float) $this->settings['center']['longitude'], (float) $row['latitude'], (float) $row['longitude']) / 1000;
                $outside = $distance > $radius;
                $position = [
                    'latitude' => (float) $row['latitude'],
                    'longitude' => (float) $row['longitude'],
                    'accuracy' => $row['accuracy_m'] === null ? null : (int) $row['accuracy_m'],
                    'speed' => $row['speed_kph'] === null ? null : (float) $row['speed_kph'],
                    'heading' => $row['heading_degrees'] === null ? null : (int) $row['heading_degrees'],
                    'age_seconds' => $age,
                ];
            }
            $overdue = $row['overdue_seconds'] !== null && (int) $row['overdue_seconds'] > 0;
            $stopped = $row['stopped_seconds'] === null ? 0 : max(0, (int) $row['stopped_seconds']);
            $status = match ($state) {
                'live' => $stopped >= 300 ? 'Stopped for ' . self::duration($stopped) : 'Live' . ($position['speed'] !== null ? ' · ' . (int) round($position['speed']) . ' km/h' : ''),
                'quiet' => 'Last seen ' . self::duration($position['age_seconds']) . ' ago',
                default => (int) $row['has_link'] === 1 ? 'Waiting for the phone' : 'No phone connected',
            };
            $alerts = [];
            if ($overdue) {
                $alerts[] = 'Overdue by ' . self::duration((int) $row['overdue_seconds']);
            }
            if ($outside) {
                $alerts[] = 'Outside the service area, ' . (int) round((float) $distance) . ' km away';
            }
            $vehicles[] = [
                'agreement_id' => (int) $row['agreement_id'],
                'reference' => (string) $row['booking_reference'],
                'plate' => (string) $row['plate_number'],
                'vehicle' => trim($row['make'] . ' ' . $row['model']),
                'customer' => (string) $row['customer_name'],
                'rental_type' => (string) $row['rental_type'],
                'due_back' => $row['scheduled_return_at'] === null ? null : Format::datetime($row['scheduled_return_at']),
                'state' => $state,
                'tone' => $overdue ? 'danger' : ($outside ? 'warning' : ($state === 'live' ? 'success' : 'neutral')),
                'status' => $status,
                'alerts' => $alerts,
                'connected' => (int) $row['has_link'] === 1,
                'position' => $position,
                'detail_url' => '/rentals/detail?agreement_id=' . (int) $row['agreement_id'],
                'connect_url' => '/fleet/tracking/connect?agreement_id=' . (int) $row['agreement_id'],
            ];
        }
        return $vehicles;
    }

    /** The rental behind a token, or null (and a security-log entry) when the token opens nothing. */
    private function link(string $token, string $ip, string $userAgent): ?array
    {
        $link = preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token) === 1 ? $this->tracking->findByToken(hash('sha256', $token)) : null;
        if ($link === null) {
            $this->securityLogs->append('tracker.link_rejected', null, null, $ip, $userAgent);
        }
        return $link;
    }

    private static function stateOf(string $agreementStatus): string
    {
        return match ($agreementStatus) {
            'active' => 'tracking',
            'reserved', 'confirmed' => 'waiting',
            default => 'ended',
        };
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float) $value) ? (float) $value : null;
    }

    /** Distance over the ground between two positions, in metres (haversine). */
    public static function metresBetween(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $a = sin(deg2rad($latitudeB - $latitudeA) / 2) ** 2 + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin(deg2rad($longitudeB - $longitudeA) / 2) ** 2;
        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Compass direction from the first position to the second: 0 north, 90 east. */
    private static function bearing(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): int
    {
        $y = sin(deg2rad($longitudeB - $longitudeA)) * cos(deg2rad($latitudeB));
        $x = cos(deg2rad($latitudeA)) * sin(deg2rad($latitudeB)) - sin(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * cos(deg2rad($longitudeB - $longitudeA));
        return ((int) round(rad2deg(atan2($y, $x))) + 360) % 360;
    }

    /** 45 → "45 seconds", 250 → "4 minutes", 7800 → "2 h 10 min". */
    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return Format::plural($seconds, 'second');
        }
        if ($seconds < 3600) {
            return Format::plural(intdiv($seconds, 60), 'minute');
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
        }
        return Format::plural(intdiv($seconds, 86400), 'day');
    }
}
