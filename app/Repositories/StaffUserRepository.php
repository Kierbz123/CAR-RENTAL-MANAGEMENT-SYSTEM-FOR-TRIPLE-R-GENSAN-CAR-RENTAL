<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class StaffUserRepository
{
    /** The account automated actions are recorded under (migration 028). It cannot sign in. */
    public const SYSTEM_EMAIL = 'system@triple-r.invalid';

    public function __construct(private readonly PDO $db)
    {
    }

    public function findActiveByEmailForUpdate(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, password_hash, role, failed_login_count, locked_at, must_change_password FROM users WHERE email = :email AND is_active = 1 AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, password_hash, role, locked_at, must_change_password FROM users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function findForAdmin(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, role, is_active, deleted_at FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function createAdmin(string $email, string $password): bool
    {
        $statement = $this->db->prepare("INSERT IGNORE INTO users (email, password_hash, role, is_active, must_change_password) VALUES (:email, :password_hash, 'system_admin', 1, 1)");
        $statement->execute([
            'email' => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        return $statement->rowCount() === 1;
    }

    public function recordFailedLogin(int $userId, int $threshold): bool
    {
        $statement = $this->db->prepare('UPDATE users SET locked_at = IF(failed_login_count + 1 >= :threshold, UTC_TIMESTAMP(6), locked_at), failed_login_count = failed_login_count + 1 WHERE id = :id AND locked_at IS NULL AND is_active = 1 AND deleted_at IS NULL');
        $statement->execute(['threshold' => max(1, $threshold), 'id' => $userId]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        $check = $this->db->prepare('SELECT locked_at IS NOT NULL FROM users WHERE id = :id');
        $check->execute(['id' => $userId]);
        return (bool) $check->fetchColumn();
    }

    public function clearFailedLogins(int $userId): void
    {
        $statement = $this->db->prepare('UPDATE users SET failed_login_count = 0 WHERE id = :id AND locked_at IS NULL');
        $statement->execute(['id' => $userId]);
    }

    /**
     * The id automated actions are recorded under: the system account, or on a database from
     * before migration 028 the first active administrator, as before.
     */
    public function systemActorId(): ?int
    {
        $statement = $this->db->prepare('SELECT id FROM users WHERE email = :email');
        $statement->execute(['email' => self::SYSTEM_EMAIL]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            // Before migration 028 there is no system account; the first administrator stands in.
            $id = $this->db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        }
        return $id === false ? null : (int) $id;
    }

    public function isSystemAccount(int $userId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM users WHERE id = :id AND email = :email');
        $statement->execute(['id' => $userId, 'email' => self::SYSTEM_EMAIL]);
        return $statement->fetchColumn() !== false;
    }

    /** Accounts that can sign in plus the system account; with $deactivated, the ones switched off instead. */
    public function list(int $limit = 200, int $offset = 0, bool $deactivated = false): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        // Working accounts by role, then deactivated ones, then the system account: the order the list groups them in.
        $system = self::SYSTEM_EMAIL;
        return $this->db->query("SELECT id, email, role, driver_id, (SELECT full_name FROM drivers WHERE drivers.driver_id = users.driver_id) AS driver_name, is_active, failed_login_count, locked_at, must_change_password, deleted_at, created_at FROM users WHERE " . self::shown($deactivated) . " ORDER BY CASE WHEN email = '{$system}' THEN 2 WHEN is_active = 1 AND deleted_at IS NULL THEN 0 ELSE 1 END, FIELD(role, 'system_admin', 'fleet_manager', 'front_desk', 'driver'), email LIMIT {$limit} OFFSET {$offset}")->fetchAll();
    }

    public function count(bool $deactivated = false): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users WHERE ' . self::shown($deactivated))->fetchColumn();
    }

    private static function shown(bool $deactivated): string
    {
        return ($deactivated ? 'NOT ' : '') . "((is_active = 1 AND deleted_at IS NULL) OR email = '" . self::SYSTEM_EMAIL . "')";
    }

    /** $driverId is given for a driver's account and for no other. */
    public function create(string $email, string $passwordHash, string $role, ?int $driverId = null): int
    {
        $statement = $this->db->prepare('INSERT INTO users (email, password_hash, role, driver_id, is_active, must_change_password) VALUES (:email, :password_hash, :role, :driver_id, 1, 1)');
        $statement->execute(['email' => mb_strtolower(trim($email)), 'password_hash' => $passwordHash, 'role' => $role, 'driver_id' => $driverId]);
        return (int) $this->db->lastInsertId();
    }

    /** Active drivers who have never had an account. A driver gets one account, which is reactivated rather than replaced. */
    public function driversWithoutAccount(): array
    {
        return $this->db->query("SELECT d.driver_id, d.full_name FROM drivers d LEFT JOIN users u ON u.driver_id = d.driver_id WHERE u.id IS NULL AND d.deleted_at IS NULL AND d.status = 'active' ORDER BY d.full_name, d.driver_id")->fetchAll();
    }

    /** The account that signs in as this driver, working or deactivated, if there is one. */
    public function accountForDriver(int $driverId): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, is_active, deleted_at FROM users WHERE driver_id = :driver_id LIMIT 1');
        $statement->execute(['driver_id' => $driverId]);
        return $statement->fetch() ?: null;
    }

    public function setRole(int $userId, string $role): bool
    {
        $statement = $this->db->prepare('UPDATE users SET role = :role WHERE id = :id AND deleted_at IS NULL');
        $statement->execute(['role' => $role, 'id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function updateProfile(int $userId, string $email, string $role): void
    {
        $statement = $this->db->prepare('UPDATE users SET email = :email, role = :role WHERE id = :id');
        $statement->execute(['email' => mb_strtolower(trim($email)), 'role' => $role, 'id' => $userId]);
        if ($statement->rowCount() === 0) {
            $check = $this->db->prepare('SELECT id FROM users WHERE id = :id');
            $check->execute(['id' => $userId]);
            if ($check->fetchColumn() === false) {
                throw new \OutOfBoundsException('User not found.');
            }
        }
    }

    public function deactivate(int $userId): bool
    {
        $statement = $this->db->prepare('UPDATE users SET is_active = 0, deleted_at = UTC_TIMESTAMP(6) WHERE id = :id AND is_active = 1 AND deleted_at IS NULL');
        $statement->execute(['id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function reactivate(int $userId): bool
    {
        $statement = $this->db->prepare('UPDATE users SET is_active = 1, deleted_at = NULL WHERE id = :id AND (is_active = 0 OR deleted_at IS NOT NULL)');
        $statement->execute(['id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function unlock(int $userId): bool
    {
        $statement = $this->db->prepare('UPDATE users SET failed_login_count = 0, locked_at = NULL WHERE id = :id AND locked_at IS NOT NULL');
        $statement->execute(['id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function setMustChangePassword(int $userId, string $passwordHash): bool
    {
        $statement = $this->db->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 1 WHERE id = :id AND deleted_at IS NULL');
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function changePassword(int $userId, string $passwordHash): bool
    {
        $statement = $this->db->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :id AND is_active = 1 AND deleted_at IS NULL AND locked_at IS NULL');
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
        return $statement->rowCount() === 1;
    }
}
