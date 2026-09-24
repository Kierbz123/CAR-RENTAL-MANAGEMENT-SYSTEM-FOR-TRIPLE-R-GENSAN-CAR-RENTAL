<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class StaffUserRepository
{
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

    public function list(int $limit = 200): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->query("SELECT id, email, role, is_active, failed_login_count, locked_at, must_change_password, deleted_at, created_at FROM users ORDER BY email LIMIT {$limit}")->fetchAll();
    }

    public function create(string $email, string $passwordHash, string $role): int
    {
        $statement = $this->db->prepare('INSERT INTO users (email, password_hash, role, is_active, must_change_password) VALUES (:email, :password_hash, :role, 1, 1)');
        $statement->execute(['email' => mb_strtolower(trim($email)), 'password_hash' => $passwordHash, 'role' => $role]);
        return (int) $this->db->lastInsertId();
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
