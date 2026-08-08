<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\MatchupRepository;

/**
 * Settles a week to its Official result (ADR-0005): ingest nflverse lines,
 * rescore every Matchup from the now-Official stats, and mark the week final —
 * which locks it. Settlement may change a result; Standings recompute because
 * StandingsService reads only final Matchups.
 */
final class SettlementService
{
    public function __construct(
        private readonly StatsImporter $importer,
        private readonly MatchupScoringService $scoring,
        private readonly MatchupRepository $matchups,
    ) {
    }

    /**
     * @param array<string, array<string,float>> $nflverseLines gsis_id => stat line
     */
    public function settleWeek(int $leagueId, int $seasonId, int $week, array $nflverseLines): void
    {
        $this->importer->importNflverse($seasonId, $week, $nflverseLines);
        $this->scoring->scoreWeek($leagueId, $seasonId, $week, 'final');
        $this->matchups->settleWeek($seasonId, $week);
    }
}
