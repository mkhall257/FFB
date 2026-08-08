<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Computes seed-ordered Standings from settled (final) Matchups: record (win%,
 * a tie = half a win) then total points scored, then team id as a deterministic
 * final tiebreaker. No head-to-head. Feeds the (future) Playoff seeding.
 */
final class StandingsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{team_id:int,wins:int,losses:int,ties:int,points_for:float,win_pct:float}>
     */
    public function compute(int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT home_team_id, away_team_id, home_score, away_score'
            . " FROM matchups WHERE season_id = ? AND status = 'final'"
        );
        $stmt->execute([$seasonId]);

        /** @var array<int,array{wins:int,losses:int,ties:int,points_for:float}> $acc */
        $acc = [];
        $ensure = static function (array &$acc, int $team): void {
            $acc[$team] ??= ['wins' => 0, 'losses' => 0, 'ties' => 0, 'points_for' => 0.0];
        };

        foreach ($stmt->fetchAll() as $m) {
            $home = (int) $m['home_team_id'];
            $away = (int) $m['away_team_id'];
            $hs = (float) $m['home_score'];
            $as = (float) $m['away_score'];
            $ensure($acc, $home);
            $ensure($acc, $away);
            $acc[$home]['points_for'] += $hs;
            $acc[$away]['points_for'] += $as;

            if ($hs > $as) {
                $acc[$home]['wins']++;
                $acc[$away]['losses']++;
            } elseif ($as > $hs) {
                $acc[$away]['wins']++;
                $acc[$home]['losses']++;
            } else {
                $acc[$home]['ties']++;
                $acc[$away]['ties']++;
            }
        }

        $rows = [];
        foreach ($acc as $teamId => $r) {
            $games = $r['wins'] + $r['losses'] + $r['ties'];
            $winPct = $games > 0 ? ($r['wins'] + 0.5 * $r['ties']) / $games : 0.0;
            $rows[] = [
                'team_id' => $teamId,
                'wins' => $r['wins'], 'losses' => $r['losses'], 'ties' => $r['ties'],
                'points_for' => round($r['points_for'], 2),
                'win_pct' => round($winPct, 4),
            ];
        }

        usort($rows, static fn ($a, $b) =>
            [$b['win_pct'], $b['points_for'], $a['team_id']]
            <=> [$a['win_pct'], $a['points_for'], $b['team_id']]);

        return $rows;
    }
}
