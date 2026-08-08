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
     * Pause the Draft, banking the seconds left on the clock.
     */
    public function pause(int $draftId, int $remainingSeconds): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE drafts SET state = 'paused', paused_remaining = ?, current_deadline = NULL WHERE id = ?"
        );
        $stmt->execute([$remainingSeconds, $draftId]);
    }

    /**
     * Resume a paused Draft, restoring the banked time (or a full timer).
     */
    public function resume(int $draftId): void
    {
        $stmt = $this->pdo->prepare('SELECT pick_seconds, paused_remaining FROM drafts WHERE id = ?');
        $stmt->execute([$draftId]);
        $row = $stmt->fetch();

        $remaining = $row !== false && $row['paused_remaining'] !== null
            ? (int) $row['paused_remaining']
            : (int) ($row['pick_seconds'] ?? 0);
        $deadline = date('Y-m-d H:i:s', time() + $remaining);

        $this->pdo->prepare(
            "UPDATE drafts SET state = 'live', current_deadline = ?, paused_remaining = NULL WHERE id = ?"
        )->execute([$deadline, $draftId]);
    }

    /**
     * Add seconds to the running clock (live) or to the banked time (paused).
     */
    public function addTime(int $draftId, int $seconds, bool $paused): void
    {
        $sql = $paused
            ? 'UPDATE drafts SET paused_remaining = COALESCE(paused_remaining, 0) + ? WHERE id = ?'
            : 'UPDATE drafts SET current_deadline = DATE_ADD(current_deadline, INTERVAL ? SECOND) WHERE id = ?';
        $this->pdo->prepare($sql)->execute([$seconds, $draftId]);
    }

    /**
     * Move the clock to $pickNo with a fresh deadline from the pick timer.
     */
    public function advanceTo(int $draftId, int $pickNo, int $pickSeconds): void
    {
        $deadline = date('Y-m-d H:i:s', time() + $pickSeconds);
        $stmt = $this->pdo->prepare(
            'UPDATE drafts SET current_pick_no = ?, current_deadline = ? WHERE id = ?'
        );
        $stmt->execute([$pickNo, $deadline, $draftId]);
    }

    /**
     * Put the clock back on an earlier pick (undo), reopening the Draft to Live
     * if it had completed.
     */
    public function revertTo(int $draftId, int $overallPick, int $pickSeconds): void
    {
        $deadline = date('Y-m-d H:i:s', time() + $pickSeconds);
        $stmt = $this->pdo->prepare(
            "UPDATE drafts SET state = 'live', current_pick_no = ?, current_deadline = ?,"
            . ' completed_at = NULL WHERE id = ?'
        );
        $stmt->execute([$overallPick, $deadline, $draftId]);
    }

    /**
     * Return a Draft to Setup, clearing all live/lifecycle state (reset). The
     * order and queues are left intact so the Draft can be re-run.
     */
    public function resetToSetup(int $draftId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE drafts SET state = 'setup', current_pick_no = NULL, current_deadline = NULL,"
            . ' paused_remaining = NULL, started_at = NULL, completed_at = NULL WHERE id = ?'
        );
        $stmt->execute([$draftId]);
    }

    /**
     * Mark the Draft Complete: no pick on the clock, no deadline.
     */
    public function complete(int $draftId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE drafts SET state = 'complete', current_pick_no = NULL,"
            . ' current_deadline = NULL, completed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$draftId]);
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

    public function setAutoDraft(int $draftId, int $teamId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE draft_order SET auto_draft = ? WHERE draft_id = ? AND team_id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, $draftId, $teamId]);
    }

    public function isAutoDraft(int $draftId, int $teamId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT auto_draft FROM draft_order WHERE draft_id = ? AND team_id = ?'
        );
        $stmt->execute([$draftId, $teamId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (int) $value === 1;
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
