<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the Draft pick board — the snake grid generated when the
 * Draft goes Live, and each Player selection as it is made.
 */
final class DraftPickRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Generate the full snake board: for each round, the draft order runs
     * forward on odd rounds and reverses on even rounds (see CONTEXT.md).
     *
     * @param list<int> $orderedTeamIds Team ids in first-round order
     */
    public function generateBoard(int $draftId, array $orderedTeamIds, int $rounds): void
    {
        $count = count($orderedTeamIds);
        $insert = $this->pdo->prepare(
            'INSERT INTO draft_picks (draft_id, overall_pick, round, pick_in_round, team_id)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );

        $overall = 1;
        for ($round = 1; $round <= $rounds; $round++) {
            $indexes = $round % 2 === 1 ? range(0, $count - 1) : range($count - 1, 0);
            $pickInRound = 1;
            foreach ($indexes as $index) {
                $insert->execute([$draftId, $overall, $round, $pickInRound, $orderedTeamIds[$index]]);
                $overall++;
                $pickInRound++;
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByOverall(int $draftId, int $overallPick): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM draft_picks WHERE draft_id = ? AND overall_pick = ?'
        );
        $stmt->execute([$draftId, $overallPick]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function totalPicks(int $draftId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM draft_picks WHERE draft_id = ?');
        $stmt->execute([$draftId]);

        return (int) $stmt->fetchColumn();
    }
}
