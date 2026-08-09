<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads user accounts (Commissioner and Manager). Passwords are
 * always stored hashed; the plaintext never touches the database.
 */
final class UserRepository
{
    private const ROLES = ['commissioner', 'manager'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Create a user, hashing the password. Returns the new user id.
     */
    public function create(
        int $leagueId,
        string $username,
        string $password,
        string $role,
        string $displayName,
    ): int {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown role: {$role}");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (league_id, username, password_hash, role, display_name)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leagueId,
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $displayName,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Find an active user by username within a League, or null if none.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveByUsername(int $leagueId, string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE league_id = ? AND username = ? AND is_active = 1'
        );
        $stmt->execute([$leagueId, $username]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Find a user by id, or null if none.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Replace a user's password (hashed). Scoped to a League as a safety check.
     */
    public function resetPassword(int $leagueId, int $userId, string $newPassword): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ? AND league_id = ?'
        );
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, $leagueId]);
    }

    /**
     * Update a user's display name.
     */
    public function updateDisplayName(int $userId, string $displayName): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
        $stmt->execute([$displayName, $userId]);
    }

    /**
     * Activate or deactivate a user. Scoped to a League as a safety check.
     */
    public function setActive(int $leagueId, int $userId, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_active = ? WHERE id = ? AND league_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $userId, $leagueId]);
    }
}
