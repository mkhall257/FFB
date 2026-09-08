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

    /**
     * Give every team defense a draft rank from a real consensus source, since
     * Sleeper ships none and defenses would otherwise sort dead-last and
     * alphabetically. Ordered by the supplied FantasyPros ranking (team code =>
     * 1-based rank; see {@see \FFB\Players\FantasyProsDefenseRankings}) and
     * assigned a contiguous block of search_ranks from {@see DEFENSE_RANK_BASE},
     * so they order correctly among themselves and land at a realistic spot on
     * the overall board. Any defense whose team is absent from the ranking sorts
     * last, by team code. Run at the end of a player sync.
     *
     * @param array<string,int> $teamRank NFL team code => 1-based defense rank
     * @return int the number of defenses ranked
     */
    public function assignDefenseRanks(array $teamRank): int
    {
        /** @var list<array<string,mixed>> $defenses */
        $defenses = $this->pdo->query(
            "SELECT sleeper_id, nfl_team FROM players WHERE position = 'DEF'"
        )->fetchAll();

        usort($defenses, static function (array $a, array $b) use ($teamRank): int {
            $ra = $teamRank[(string) $a['nfl_team']] ?? PHP_INT_MAX;
            $rb = $teamRank[(string) $b['nfl_team']] ?? PHP_INT_MAX;

            return $ra <=> $rb ?: strcmp((string) $a['nfl_team'], (string) $b['nfl_team']);
        });

        $update = $this->pdo->prepare('UPDATE players SET search_rank = ? WHERE sleeper_id = ?');
        $rank = self::DEFENSE_RANK_BASE;
        foreach ($defenses as $defense) {
            $update->execute([$rank, $defense['sleeper_id']]);
            $rank++;
        }

        return count($defenses);
    }

    /**
     * Set each Player's bye_week from a team => bye-week map (see
     * {@see \FFB\Players\NflByeWeeks}). Stale byes are cleared first so a team
     * dropped from the map never keeps a wrong week. Run at the end of a player
     * sync, so the draft room shows the current season's byes.
     *
     * @param array<string,int> $teamBye NFL team code => bye week
     * @return int the number of Players given a bye week
     */
    public function assignByeWeeks(array $teamBye): int
    {
        $this->pdo->exec('UPDATE players SET bye_week = NULL WHERE bye_week IS NOT NULL');

        $update = $this->pdo->prepare('UPDATE players SET bye_week = ? WHERE nfl_team = ?');
        $updated = 0;
        foreach ($teamBye as $team => $week) {
            $update->execute([$week, $team]);
            $updated += $update->rowCount();
        }

        return $updated;
    }

    /** Positions that can be drafted/rostered (see CONTEXT.md). */
    private const DRAFTABLE_POSITIONS = ['QB', 'RB', 'WR', 'TE', 'K', 'DEF'];

    /**
     * Where the team-defense block starts in the overall draft order. Sleeper
     * publishes no rank for defenses, so {@see assignDefenseRanks} slots them in
     * here — roughly where the first DST goes in a typical league — instead of
     * leaving them unranked (and therefore dead-last and alphabetical).
     */
    private const DEFENSE_RANK_BASE = 140;

    /**
     * True when the Player exists and plays a draftable position.
     */
    public function isDraftable(string $sleeperId): bool
    {
        $position = $this->positionOf($sleeperId);

        return $position !== null && in_array($position, self::DRAFTABLE_POSITIONS, true);
    }

    public function exists(string $sleeperId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM players WHERE sleeper_id = ? LIMIT 1');
        $stmt->execute([$sleeperId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * The Sleeper id for a Player linked to the given nflverse (gsis) id, or null
     * when no Player carries that link (an Unmatched Player; see ADR-0004/0006).
     */
    public function sleeperIdForNflverseId(string $nflverseId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT sleeper_id FROM players WHERE nflverse_id = ? LIMIT 1');
        $stmt->execute([$nflverseId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    public function positionOf(string $sleeperId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT position FROM players WHERE sleeper_id = ?');
        $stmt->execute([$sleeperId]);
        $position = $stmt->fetchColumn();

        return $position === false || $position === null ? null : (string) $position;
    }

    /**
     * The best available (undrafted) draftable Player for a Draft by Sleeper
     * rank, optionally restricted to a set of positions. Returns the sleeper_id
     * or null when nothing matches.
     *
     * @param list<string>|null $positions restrict to these positions, or null for any draftable
     */
    public function bestAvailable(int $draftId, ?array $positions = null): ?string
    {
        $allowed = self::DRAFTABLE_POSITIONS;
        if ($positions !== null) {
            $allowed = array_values(array_intersect($allowed, $positions));
            if ($allowed === []) {
                return null;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT p.sleeper_id FROM players p'
            . " WHERE p.position IN ({$placeholders})"
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM draft_picks dp WHERE dp.draft_id = ? AND dp.player_id = p.sleeper_id'
            . ' )'
            . ' ORDER BY (p.search_rank IS NULL), p.search_rank, p.full_name'
            . ' LIMIT 1'
        );
        $stmt->execute([...$allowed, $draftId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
    }

    /**
     * Bulk metadata lookup for a set of sleeper ids. Missing ids are simply
     * absent from the result. Used by read models (e.g. the Matchup detail) that
     * have a list of player ids and need names/positions/teams/status in one hop.
     *
     * @param list<string> $ids
     * @return array<string, array{name:string,position:string,nfl_team:string,status:string}>
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($x): bool => is_string($x) && $x !== '')));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT sleeper_id, full_name, position, nfl_team, status FROM players WHERE sleeper_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['sleeper_id']] = [
                'name' => (string) ($r['full_name'] ?? ''),
                'position' => (string) ($r['position'] ?? ''),
                'nfl_team' => (string) ($r['nfl_team'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Draftable Players not yet taken in the given Draft, ordered by Sleeper
     * rank (unranked last), then name — best available first. Optionally filtered
     * to a single position and/or a name search, so the draft room can show, for
     * example, just the remaining defenses or every player matching "smith".
     *
     * A position filter is essential for the lower-volume slots (K, DEF): they
     * rank below the deep skill-player pool, so without it they fall past any
     * result limit and never appear. The search matches a Player's name or NFL
     * team, so a Manager can find a defense by its team (e.g. "SF" or
     * "San Francisco") as well as a skill player by name.
     *
     * @param int|null $limit cap the rows returned, or null for the full pool
     * @return list<array<string,mixed>>
     */
    public function availableForDraft(
        int $draftId,
        ?string $search = null,
        ?string $position = null,
        ?int $limit = null,
    ): array {
        $params = [$draftId];
        $where = '';

        if ($position !== null && in_array($position, self::DRAFTABLE_POSITIONS, true)) {
            $where .= ' AND p.position = ?';
            $params[] = $position;
        }
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (p.full_name LIKE ? OR p.nfl_team LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.sleeper_id, p.full_name, p.position, p.nfl_team, p.bye_week, p.status, p.search_rank'
            . ' FROM players p'
            . " WHERE p.position IN ('QB', 'RB', 'WR', 'TE', 'K', 'DEF')"
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM draft_picks dp WHERE dp.draft_id = ? AND dp.player_id = p.sleeper_id'
            . ' )'
            . $where
            . ' ORDER BY (p.search_rank IS NULL), p.search_rank, p.full_name'
            . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
        );
        // Bind order matches the SQL: NOT EXISTS draft_id first, then the
        // optional position and search filters.
        $stmt->execute($params);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    /**
     * The free-agent pool for a Season: draftable Players with no rosters row
     * this Season (the Add/Drop availability rule, ADR-0010), ordered by Sleeper
     * rank (unranked last), then name — best available first. Optionally filtered
     * by a single position and/or a search over the Player's name or NFL team (so
     * a defense is found by its team as well as its name).
     *
     * @param int|null $limit cap the rows returned, or null for the full pool
     * @return list<array<string,mixed>>
     */
    public function availableForSeason(
        int $seasonId,
        ?string $search = null,
        ?string $position = null,
        ?int $limit = null,
    ): array {
        $params = [$seasonId];
        $where = '';

        if ($position !== null && in_array($position, self::DRAFTABLE_POSITIONS, true)) {
            $where .= ' AND p.position = ?';
            $params[] = $position;
        }
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (p.full_name LIKE ? OR p.nfl_team LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.sleeper_id, p.full_name, p.position, p.nfl_team, p.bye_week, p.status, p.search_rank'
            . ' FROM players p'
            . " WHERE p.position IN ('QB', 'RB', 'WR', 'TE', 'K', 'DEF')"
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM rosters r WHERE r.season_id = ? AND r.player_id = p.sleeper_id'
            . ' )'
            . $where
            . ' ORDER BY (p.search_rank IS NULL), p.search_rank, p.full_name'
            . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
        );
        // Bind order matches the SQL: NOT EXISTS season_id first, then the
        // optional position and search filters.
        $stmt->execute($params);

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
