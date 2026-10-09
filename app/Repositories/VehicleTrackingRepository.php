<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

/**
 * Tracker links (rows of booking_access_tokens with purpose 'vehicle_tracker') and the latest
 * position of each vehicle (vehicle_positions, one row per vehicle).
 */
final class VehicleTrackingRepository
{
    public const PURPOSE = 'vehicle_tracker';

    public function __construct(private readonly PDO $db)
    {
    }

    /** Stores a new tracker link for the rental and switches off any earlier one, so one phone reports at a time. */
    public function replaceLink(int $agreementId, string $tokenHash, string $expiresAt): void
    {
        $this->db->beginTransaction();
        try {
            $this->revokeLinks($agreementId);
            $insert = $this->db->prepare('INSERT INTO booking_access_tokens (token_hash, purpose, booking_id, expires_at) VALUES (:hash, :purpose, :agreement, :expires)');
            $insert->execute(['hash' => $tokenHash, 'purpose' => self::PURPOSE, 'agreement' => $agreementId, 'expires' => $expiresAt]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return int how many links were switched off */
    public function revokeLinks(int $agreementId): int
    {
        $statement = $this->db->prepare('UPDATE booking_access_tokens SET used_at = UTC_TIMESTAMP(6) WHERE booking_id = :agreement AND purpose = :purpose AND used_at IS NULL');
        $statement->execute(['agreement' => $agreementId, 'purpose' => self::PURPOSE]);
        return $statement->rowCount();
    }

    /** The rental a tracker link belongs to, with its vehicle; null when the link is unknown, switched off or too old. */
    public function findByToken(string $tokenHash): ?array
    {
        $statement = $this->db->prepare('SELECT t.id AS token_id, r.agreement_id, r.booking_reference, r.status, r.rental_type, r.scheduled_return_at, v.vehicle_id, v.plate_number, v.make, v.model FROM booking_access_tokens t JOIN rental_agreements r ON r.agreement_id = t.booking_id JOIN vehicles v ON v.vehicle_id = r.vehicle_id WHERE t.token_hash = :hash AND t.purpose = :purpose AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6)');
        $statement->execute(['hash' => $tokenHash, 'purpose' => self::PURPOSE]);
        return $statement->fetch() ?: null;
    }

    public function hasLink(int $agreementId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM booking_access_tokens WHERE booking_id = :agreement AND purpose = :purpose AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute(['agreement' => $agreementId, 'purpose' => self::PURPOSE]);
        return $statement->fetchColumn() !== false;
    }

    /** The vehicle's stored position, whichever rental it was reported for. */
    public function position(int $vehicleId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM vehicle_positions WHERE vehicle_id = :vehicle');
        $statement->execute(['vehicle' => $vehicleId]);
        return $statement->fetch() ?: null;
    }

    /**
     * Replaces the vehicle's position with a newer one. $stoppedSince is a UTC time, or null
     * while the vehicle is moving.
     */
    public function savePosition(int $vehicleId, int $agreementId, string $latitude, string $longitude, ?int $accuracy, ?string $speed, ?int $heading, ?string $stoppedSince): void
    {
        $statement = $this->db->prepare('INSERT INTO vehicle_positions (vehicle_id, agreement_id, latitude, longitude, accuracy_m, speed_kph, heading_degrees, recorded_at, stopped_since) VALUES (:vehicle, :agreement, :latitude, :longitude, :accuracy, :speed, :heading, UTC_TIMESTAMP(6), :stopped) AS newer '
            . 'ON DUPLICATE KEY UPDATE agreement_id = newer.agreement_id, latitude = newer.latitude, longitude = newer.longitude, accuracy_m = newer.accuracy_m, speed_kph = newer.speed_kph, heading_degrees = newer.heading_degrees, recorded_at = newer.recorded_at, stopped_since = newer.stopped_since');
        $statement->execute(['vehicle' => $vehicleId, 'agreement' => $agreementId, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy, 'speed' => $speed, 'heading' => $heading, 'stopped' => $stoppedSince]);
    }

    /**
     * Every vehicle that is out on rental, with the position reported for that rental (if any),
     * how old it is, and whether a phone is connected. Longest overdue first.
     */
    public function vehiclesOut(): array
    {
        return $this->db->query("SELECT r.agreement_id, r.booking_reference, r.rental_type, r.scheduled_return_at, r.actual_pickup_at, c.full_name AS customer_name, v.vehicle_id, v.plate_number, v.make, v.model, "
            . "p.latitude, p.longitude, p.accuracy_m, p.speed_kph, p.heading_degrees, p.recorded_at, TIMESTAMPDIFF(SECOND, p.recorded_at, UTC_TIMESTAMP(6)) AS age_seconds, TIMESTAMPDIFF(SECOND, p.stopped_since, UTC_TIMESTAMP(6)) AS stopped_seconds, "
            . "TIMESTAMPDIFF(SECOND, r.scheduled_return_at, UTC_TIMESTAMP(6)) AS overdue_seconds, "
            . "EXISTS(SELECT 1 FROM booking_access_tokens t WHERE t.booking_id = r.agreement_id AND t.purpose = '" . self::PURPOSE . "' AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6)) AS has_link, "
            . "(SELECT MAX(t.created_at) FROM booking_access_tokens t WHERE t.booking_id = r.agreement_id AND t.purpose = '" . self::PURPOSE . "' AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6)) AS link_created_at "
            . "FROM rental_agreements r JOIN vehicles v ON v.vehicle_id = r.vehicle_id JOIN customers c ON c.customer_id = r.customer_id "
            . "LEFT JOIN vehicle_positions p ON p.vehicle_id = r.vehicle_id AND p.agreement_id = r.agreement_id "
            . "WHERE r.status = 'active' ORDER BY r.scheduled_return_at IS NULL, r.scheduled_return_at, r.agreement_id")->fetchAll();
    }
}
