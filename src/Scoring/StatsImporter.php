<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;

/**
 * Writes normalized weekly stat lines into player_week_stats. Sleeper lines are
 * keyed by sleeper_id directly; nflverse lines are keyed by gsis id and mapped
 * back to sleeper_id via the Player crosswalk. Lines for Players not in the
 * canonical universe are skipped.
 */
final class StatsImporter
{
    public function __construct(
        private readonly PlayerWeekStatsRepository $stats,
        private readonly PlayerRepository $players,
    ) {
    }

    /**
     * @param array<string, array<string,float>> $lines sleeper_id => stat line
     * @return int number of lines written
     */
    public function importSleeper(int $seasonId, int $week, array $lines): int
    {
        $written = 0;
        foreach ($lines as $sleeperId => $line) {
            if (!$this->players->exists((string) $sleeperId)) {
                continue;
            }
            $this->stats->upsert($seasonId, $week, (string) $sleeperId, 'sleeper', $line);
            $written++;
        }

        return $written;
    }

    /**
     * @param array<string, array<string,float>> $lines gsis_id => stat line
     * @return int number of lines written
     */
    public function importNflverse(int $seasonId, int $week, array $lines): int
    {
        $written = 0;
        foreach ($lines as $gsisId => $line) {
            $sleeperId = $this->players->sleeperIdForNflverseId((string) $gsisId);
            if ($sleeperId === null) {
                continue; // Unmatched Player — surfaced to the Commissioner elsewhere (ADR-0004)
            }
            $this->stats->upsert($seasonId, $week, $sleeperId, 'nflverse', $line);
            $written++;
        }

        return $written;
    }
}
