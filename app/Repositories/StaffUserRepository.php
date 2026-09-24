<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class StaffUserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findActiveByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, password_hash, role FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function findActiveById(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    public function createAdmin(string $email, string $password): bool
    {
        $statement = $this->db->prepare("INSERT IGNORE INTO users (email, password_hash, role, is_active) VALUES (:email, :password_hash, 'system_admin', 1)");
        $statement->execute([
            'email' => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        return $statement->rowCount() === 1;
    }
}
