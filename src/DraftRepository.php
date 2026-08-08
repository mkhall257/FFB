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

    public function setState(int $draftId, string $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE drafts SET state = ? WHERE id = ?');
        $stmt->execute([$state, $draftId]);
    }

    /**
     * Put the Draft Live: first Team on the clock, deadline set from the pick
     * timer.
     */
    public function start(int $draftId, int $pickSeconds): void
    {
        $deadline = date('Y-m-d H:i:s', time() + $pickSeconds);
        $stmt = $this->pdo->prepare(
            "UPDATE drafts SET state = 'live', current_pick_no = 1, current_deadline = ?,"
            . ' started_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$deadline, $draftId]);
    }

    /**
     * Replace the draft order with the given Team ids, positioned 1..N in the
     * order supplied.
     *
     * @param list<int> $teamIds
     */
    public function setOrder(int $draftId, array $teamIds): void
    {
        $this->pdo->prepare('DELETE FROM draft_order WHERE draft_id = ?')->execute([$draftId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO draft_order (draft_id, position, team_id) VALUES (?, ?, ?)'
        );
        $position = 1;
        foreach ($teamIds as $teamId) {
            $insert->execute([$draftId, $position, $teamId]);
            $position++;
        }
    }

    /**
     * The ordered Team ids (position 1..N).
     *
     * @return list<int>
     */
    public function orderTeamIds(int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT team_id FROM draft_order WHERE draft_id = ? ORDER BY position'
        );
        $stmt->execute([$draftId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The draft order with Team names, for display.
     *
     * @return list<array<string,mixed>>
     */
    public function order(int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.position, o.team_id, o.auto_draft, t.name AS team_name'
            . ' FROM draft_order o JOIN teams t ON t.id = o.team_id'
            . ' WHERE o.draft_id = ? ORDER BY o.position'
        );
        $stmt->execute([$draftId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }
}
