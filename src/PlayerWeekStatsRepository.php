<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Raw weekly stat lines in two states (sleeper Live, nflverse Official). Reads
 * resolve to the Official line when present, else the Live line (ADR-0005).
 */
final class PlayerWeekStatsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,float|int> $stats
     */
    public function upsert(int $seasonId, int $week, string $playerId, string $source, array $stats): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO player_week_stats (season_id, week, player_id, source, stats)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE stats = VALUES(stats)'
        );
        $stmt->execute([$seasonId, $week, $playerId, $source, json_encode($stats, JSON_THROW_ON_ERROR)]);
    }

    /**
     * player_id => stat line, preferring the nflverse (Official) row over sleeper.
     *
     * @return array<string, array<string,float>>
     */
    public function resolvedForWeek(int $seasonId, int $week): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT player_id, source, stats FROM player_week_stats'
            . ' WHERE season_id = ? AND week = ?'
            // sleeper first so a later nflverse row overwrites it.
            . " ORDER BY FIELD(source, 'sleeper', 'nflverse')"
        );
        $stmt->execute([$seasonId, $week]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['stats'], true) ?: [];
            $out[(string) $row['player_id']] = array_map('floatval', $decoded);
        }

        return $out;
    }
}
