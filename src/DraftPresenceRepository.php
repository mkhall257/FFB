<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * The draft room's presence heartbeat (see ADR-0003). Every poll of the room
 * records the viewer as present via {@see touch()}; {@see connectedTeamIds()}
 * then reports which Teams have a manager who was seen recently, so the room can
 * show who is actually connected. Heartbeat data is disposable — it is never a
 * source of truth for anything but the "is X here right now" display.
 */
final class DraftPresenceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record (or refresh) the viewer's presence in a Draft. team_id is null for
     * a viewer who manages no Team (e.g. the Commissioner).
     */
    public function touch(int $draftId, int $userId, ?int $teamId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO draft_presence (draft_id, user_id, team_id, last_seen)'
            . ' VALUES (?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), last_seen = VALUES(last_seen)'
        );
        $stmt->execute([$draftId, $userId, $teamId]);
    }

    /**
     * Team ids whose manager was seen within the last $withinSeconds. The window
     * is an internal constant (never user input), so it is inlined rather than
     * bound — some MySQL builds reject a placeholder inside INTERVAL.
     *
     * @return list<int>
     */
    public function connectedTeamIds(int $draftId, int $withinSeconds): array
    {
        $seconds = max(1, $withinSeconds);
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT team_id FROM draft_presence'
            . ' WHERE draft_id = ? AND team_id IS NOT NULL'
            . " AND last_seen >= (NOW() - INTERVAL {$seconds} SECOND)"
        );
        $stmt->execute([$draftId]);

        return array_map(intval(...), array_column($stmt->fetchAll(), 'team_id'));
    }
}
