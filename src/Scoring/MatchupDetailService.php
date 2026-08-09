<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;

/**
 * Read model for the Scoreboard/Matchup UI: every Matchup in a week expanded to
 * a per-starter comparison (name, slot, NFL team, status, live/official points)
 * plus each Team's total. Reuses the exact scoring inputs as MatchupScoringService
 * (resolved stat lines × started Players × scoring) so the detail always agrees
 * with the cached Matchup totals. Presentation only — never writes.
 */
final class MatchupDetailService
{
    /** Canonical display order of Lineup slots (unknown slots sort last). */
    private const SLOT_ORDER = ['QB', 'RB', 'WR', 'TE', 'FLEX', 'DEF', 'K'];

    public function __construct(
        private readonly MatchupRepository $matchups,
        private readonly LineupRepository $lineups,
        private readonly PlayerWeekStatsRepository $stats,
        private readonly PlayerRepository $players,
        private readonly ScoringEngine $engine,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    /**
     * @return list<array{
     *   id:int, status:string,
     *   home:array{team_id:int,total:float,starters:list<array<string,mixed>>},
     *   away:array{team_id:int,total:float,starters:list<array<string,mixed>>}
     * }>
     */
    public function forWeek(int $leagueId, int $seasonId, int $week): array
    {
        $settings = $this->settings->all($leagueId, $seasonId);
        $statLines = $this->stats->resolvedForWeek($seasonId, $week);
        $starters = $this->lineups->startersForWeek($seasonId, $week);

        // One bulk lookup for every started Player across the week.
        $ids = [];
        foreach ($starters as $slots) {
            foreach ($slots as $s) {
                $ids[] = $s['player_id'];
            }
        }
        $meta = $this->players->byIds($ids);

        $out = [];
        foreach ($this->matchups->forWeek($seasonId, $week) as $m) {
            $home = $this->teamDetail((int) $m['home_team_id'], $starters, $statLines, $meta, $settings);
            $away = $this->teamDetail((int) $m['away_team_id'], $starters, $statLines, $meta, $settings);
            // The authoritative headline score is the cached Matchup total (what the
            // Live/Final scoring wrote); the per-starter sum ('total') is the breakdown.
            $home['score'] = $m['home_score'] === null ? null : round((float) $m['home_score'], 2);
            $away['score'] = $m['away_score'] === null ? null : round((float) $m['away_score'], 2);
            $out[] = [
                'id' => (int) $m['id'],
                'status' => (string) $m['status'],
                'home' => $home,
                'away' => $away,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, list<array{roster_slot:string,player_id:string}>> $starters
     * @param array<string, array<string,float>> $statLines
     * @param array<string, array{name:string,position:string,nfl_team:string,status:string}> $meta
     * @param array<string,string> $settings
     * @return array{team_id:int,total:float,starters:list<array<string,mixed>>}
     */
    private function teamDetail(int $teamId, array $starters, array $statLines, array $meta, array $settings): array
    {
        $rows = [];
        $total = 0.0;
        foreach ($starters[$teamId] ?? [] as $s) {
            $pid = $s['player_id'];
            $pts = $this->engine->pointsFor($statLines[$pid] ?? [], $settings);
            $total += $pts;
            $info = $meta[$pid] ?? ['name' => $pid, 'position' => '', 'nfl_team' => '', 'status' => ''];
            $rows[] = [
                'slot' => $s['roster_slot'],
                'player_id' => $pid,
                'name' => $info['name'] !== '' ? $info['name'] : $pid,
                'position' => $info['position'],
                'nfl_team' => $info['nfl_team'],
                'status' => $info['status'],
                'points' => round($pts, 2),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $ia = array_search($a['slot'], self::SLOT_ORDER, true);
            $ib = array_search($b['slot'], self::SLOT_ORDER, true);
            $ia = $ia === false ? PHP_INT_MAX : $ia;
            $ib = $ib === false ? PHP_INT_MAX : $ib;

            return $ia <=> $ib;
        });

        return ['team_id' => $teamId, 'total' => round($total, 2), 'starters' => $rows];
    }
}
