<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Reads the current League and Season. In v1 there is exactly one League and
 * one current Season, but the schema is multi-League/Season aware (ADR-0001)
 * so these are resolved rather than hardcoded.
 */
final class LeagueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function currentLeagueId(): int
    {
        $id = $this->pdo->query('SELECT id FROM leagues ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('No League has been configured. Run migrations first.');
        }

        return (int) $id;
    }

    public function currentSeasonId(): int
    {
        $id = $this->pdo
            ->query('SELECT id FROM seasons WHERE is_current = 1 ORDER BY id LIMIT 1')
            ->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('No current Season has been configured.');
        }

        return (int) $id;
    }
}
