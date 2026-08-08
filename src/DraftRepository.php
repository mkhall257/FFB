<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the Season's Draft (one per Season, see ADR-0001). Holds
 * the Draft's configuration and lifecycle state; the pick board, queues, and
 * roster shape live in their own tables.
 */
final class DraftRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $leagueId, int $seasonId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM drafts WHERE league_id = ? AND season_id = ?'
        );
        $stmt->execute([$leagueId, $seasonId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The Season's Draft, creating it in the Setup state (with default config)
     * if it does not exist yet.
     *
     * @return array<string,mixed>
     */
    public function currentOrCreate(int $leagueId, int $seasonId): array
    {
        $existing = $this->find($leagueId, $seasonId);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO drafts (league_id, season_id) VALUES (?, ?)'
        );
        $stmt->execute([$leagueId, $seasonId]);

        /** @var array<string,mixed> $created */
        $created = $this->find($leagueId, $seasonId);

        return $created;
    }

    public function updateConfig(int $draftId, int $pickSeconds, bool $autopickOnExpiry, ?string $scheduledAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE drafts SET pick_seconds = ?, autopick_on_expiry = ?, scheduled_at = ? WHERE id = ?'
        );
        $stmt->execute([$pickSeconds, $autopickOnExpiry ? 1 : 0, $scheduledAt, $draftId]);
    }
}
