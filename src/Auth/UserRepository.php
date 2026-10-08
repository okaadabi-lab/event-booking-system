<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Database;
use App\Support\Row;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * @return array{user: User, password_hash: string}|null
     */
    public function findWithHashByEmail(string $email): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        if ($row === null) {
            return null;
        }

        return ['user' => User::fromRow($row), 'password_hash' => Row::string($row, 'password_hash')];
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return array_map(
            User::fromRow(...),
            $this->db->fetchAll('SELECT * FROM users ORDER BY is_active DESC, role, name')
        );
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue(
            'SELECT COUNT(*) FROM users WHERE email = :email AND id <> :except',
            ['email' => $email, 'except' => $exceptId ?? 0]
        ) > 0;
    }

    public function create(string $name, string $email, Role $role, string $password): int
    {
        return $this->db->insert(
            'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)',
            ['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role->value]
        );
    }

    public function update(int $id, string $name, string $email, Role $role, bool $isActive): void
    {
        $this->db->run(
            'UPDATE users SET name = :name, email = :email, role = :role, is_active = :active WHERE id = :id',
            ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role->value, 'active' => (int) $isActive]
        );
        if (!$isActive) {
            $this->db->run('DELETE FROM sessions WHERE user_id = :id', ['id' => $id]);
        }
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = :hash WHERE id = :id',
            ['id' => $id, 'hash' => password_hash($password, PASSWORD_DEFAULT)]
        );
        $this->db->run('DELETE FROM sessions WHERE user_id = :id', ['id' => $id]);
    }

    public function rehashIfNeeded(int $id, string $password, string $hash): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->db->run(
                'UPDATE users SET password_hash = :hash WHERE id = :id',
                ['id' => $id, 'hash' => password_hash($password, PASSWORD_DEFAULT)]
            );
        }
    }

    public function countActiveOwners(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1");
    }
}
