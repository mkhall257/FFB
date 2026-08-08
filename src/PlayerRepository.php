<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the canonical NFL Player universe (keyed on Sleeper id).
 */
final class PlayerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsert(
        string $sleeperId,
        ?string $nflverseId,
        string $fullName,
        ?string $position,
        ?string $team,
        ?string $status,
        ?int $searchRank,
    ): void {
        // Uses the VALUES() form of ON DUPLICATE KEY UPDATE for broad MySQL
        // compatibility (the newer "AS new" row-alias syntax requires MySQL
        // 8.0.19+ and is rejected by older/MariaDB servers).
        $stmt = $this->pdo->prepare(
            'INSERT INTO players (sleeper_id, nflverse_id, full_name, position, nfl_team, status, search_rank)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' nflverse_id = VALUES(nflverse_id),'
            . ' full_name = VALUES(full_name),'
            . ' position = VALUES(position),'
            . ' nfl_team = VALUES(nfl_team),'
            . ' status = VALUES(status),'
            . ' search_rank = VALUES(search_rank)'
        );
        $stmt->execute([$sleeperId, $nflverseId, $fullName, $position, $team, $status, $searchRank]);
    }

    /** Positions that can be drafted/rostered (see CONTEXT.md). */
    private const DRAFTABLE_POSITIONS = ['QB', 'RB', 'WR', 'TE', 'K', 'DEF'];

    /**
     * True when the Player exists and plays a draftable position.
     */
    public function isDraftable(string $sleeperId): bool
    {
        $stmt = $this->pdo->prepare('SELECT position FROM players WHERE sleeper_id = ?');
        $stmt->execute([$sleeperId]);
        $position = $stmt->fetchColumn();

        return $position !== false && in_array($position, self::DRAFTABLE_POSITIONS, true);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
    }

    /**
     * Draftable Players not yet taken in the given Draft, ordered by Sleeper
     * rank (unranked last), then name. The best available first.
     *
     * @return list<array<string,mixed>>
     */
    public function availableForDraft(int $draftId, int $limit = 300): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare(
            'SELECT p.sleeper_id, p.full_name, p.position, p.nfl_team, p.status, p.search_rank'
            . ' FROM players p'
            . " WHERE p.position IN ('QB', 'RB', 'WR', 'TE', 'K', 'DEF')"
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM draft_picks dp WHERE dp.draft_id = ? AND dp.player_id = p.sleeper_id'
            . ' )'
            . ' ORDER BY (p.search_rank IS NULL), p.search_rank, p.full_name'
            . ' LIMIT ' . $limit
        );
        $stmt->execute([$draftId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    public function linkedCount(): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM players WHERE nflverse_id IS NOT NULL')
            ->fetchColumn();
    }

    /**
     * Unmatched Players for the Commissioner review: rosterable skill players
     * on a team with no nflverse link. Mirrors the importer's Unmatched rule.
     *
     * @return list<array<string,mixed>>
     */
    public function listUnmatched(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query(
            "SELECT sleeper_id, full_name, position, nfl_team, status FROM players"
            . " WHERE nflverse_id IS NULL"
            . " AND position IN ('QB', 'RB', 'WR', 'TE', 'K')"
            . " AND nfl_team IS NOT NULL"
            . " ORDER BY position, full_name"
        )->fetchAll();

        return $rows;
    }
}
