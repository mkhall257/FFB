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

    /**
     * The full board with Team and Player names, for display.
     *
     * @return list<array<string,mixed>>
     */
    public function board(int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dp.overall_pick, dp.round, dp.pick_in_round, dp.team_id, t.name AS team_name,'
            . ' dp.player_id, dp.source, p.full_name AS player_name, p.position'
            . ' FROM draft_picks dp'
            . ' JOIN teams t ON t.id = dp.team_id'
            . ' LEFT JOIN players p ON p.sleeper_id = dp.player_id'
            . ' WHERE dp.draft_id = ? ORDER BY dp.overall_pick'
        );
        $stmt->execute([$draftId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    /**
     * A Team's made picks grouped by Player position: position => count.
     *
     * @return array<string,int>
     */
    public function rosterPositionCounts(int $draftId, int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.position, COUNT(*) AS c FROM draft_picks dp'
            . ' JOIN players p ON p.sleeper_id = dp.player_id'
            . ' WHERE dp.draft_id = ? AND dp.team_id = ? AND dp.player_id IS NOT NULL'
            . ' GROUP BY p.position'
        );
        $stmt->execute([$draftId, $teamId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['position']] = (int) $row['c'];
        }

        return $out;
    }

    public function isPlayerTaken(int $draftId, string $playerId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM draft_picks WHERE draft_id = ? AND player_id = ? LIMIT 1'
        );
        $stmt->execute([$draftId, $playerId]);

        return $stmt->fetchColumn() !== false;
    }

    public function assignPlayer(int $pickId, string $playerId, string $source): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE draft_picks SET player_id = ?, source = ?, picked_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$playerId, $source, $pickId]);
    }

    /**
     * True when another pick in the Draft already holds this Player.
     */
    public function isPlayerTakenByOther(int $draftId, string $playerId, int $exceptPickId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM draft_picks WHERE draft_id = ? AND player_id = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$draftId, $playerId, $exceptPickId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The most recently made pick (highest overall with a Player), or null.
     *
     * @return array<string,mixed>|null
     */
    public function lastMadePick(int $draftId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM draft_picks WHERE draft_id = ? AND player_id IS NOT NULL'
            . ' ORDER BY overall_pick DESC LIMIT 1'
        );
        $stmt->execute([$draftId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Empty a pick slot (undo): no Player, no source, no timestamp.
     */
    public function clearPick(int $pickId): void
    {
        $this->pdo->prepare(
            'UPDATE draft_picks SET player_id = NULL, source = NULL, picked_at = NULL WHERE id = ?'
        )->execute([$pickId]);
    }

    /**
     * Delete the entire board for a Draft (reset).
     */
    public function clearBoard(int $draftId): void
    {
        $this->pdo->prepare('DELETE FROM draft_picks WHERE draft_id = ?')->execute([$draftId]);
    }
}
