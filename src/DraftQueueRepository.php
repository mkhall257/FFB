<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads each Manager's personal Draft Queue. Stored as a dense
 * 1..N ranking; every mutation rewrites the list to keep ranks contiguous and
 * avoid transient unique-key clashes.
 */
final class DraftQueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The Team's queued Player ids in rank order.
     *
     * @return list<string>
     */
    public function playerIds(int $draftId, int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT player_id FROM draft_queue WHERE draft_id = ? AND team_id = ? ORDER BY rank_position'
        );
        $stmt->execute([$draftId, $teamId]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The Team's queued Players with names, in rank order, for display.
     *
     * @return list<array<string,mixed>>
     */
    public function queued(int $draftId, int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.player_id, q.rank_position, p.full_name, p.position, p.nfl_team'
            . ' FROM draft_queue q JOIN players p ON p.sleeper_id = q.player_id'
            . ' WHERE q.draft_id = ? AND q.team_id = ? ORDER BY q.rank_position'
        );
        $stmt->execute([$draftId, $teamId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    /**
     * Replace the Team's queue with the given Player ids, ranked 1..N.
     *
     * @param list<string> $playerIds
     */
    public function setQueue(int $draftId, int $teamId, array $playerIds): void
    {
        $this->pdo->prepare('DELETE FROM draft_queue WHERE draft_id = ? AND team_id = ?')
            ->execute([$draftId, $teamId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO draft_queue (draft_id, team_id, player_id, rank_position) VALUES (?, ?, ?, ?)'
        );
        $rank = 1;
        foreach ($playerIds as $playerId) {
            $insert->execute([$draftId, $teamId, $playerId, $rank]);
            $rank++;
        }
    }
}
