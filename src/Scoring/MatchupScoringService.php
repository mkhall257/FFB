<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerWeekStatsRepository;

/**
 * Recomputes the cached score of every Matchup in a week from each Team's
 * started Players and their resolved stat lines. Called by the Live cron
 * (status 'live') and by settlement (status 'final').
 */
final class MatchupScoringService
{
    public function __construct(
        private readonly MatchupRepository $matchups,
        private readonly LineupRepository $lineups,
        private readonly PlayerWeekStatsRepository $stats,
        private readonly ScoringEngine $engine,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    public function scoreWeek(int $leagueId, int $seasonId, int $week, string $status): void
    {
        $settings = $this->settings->all($leagueId, $seasonId);
        $statLines = $this->stats->resolvedForWeek($seasonId, $week);
        $starters = $this->lineups->startersForWeek($seasonId, $week);

        foreach ($this->matchups->forWeek($seasonId, $week) as $m) {
            $home = $this->teamPoints((int) $m['home_team_id'], $starters, $statLines, $settings);
            $away = $this->teamPoints((int) $m['away_team_id'], $starters, $statLines, $settings);
            $this->matchups->updateScores((int) $m['id'], $home, $away, $status);
        }
    }

    /**
     * @param array<int, list<array{roster_slot:string,player_id:string}>> $starters
     * @param array<string, array<string,float>> $statLines
     * @param array<string,string> $settings
     */
    private function teamPoints(int $teamId, array $starters, array $statLines, array $settings): float
    {
        $total = 0.0;
        foreach ($starters[$teamId] ?? [] as $s) {
            $line = $statLines[$s['player_id']] ?? [];
            $total += $this->engine->pointsFor($line, $settings);
        }

        return round($total, 2);
    }
}
