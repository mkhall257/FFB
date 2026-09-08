<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * The tallies from one player sync (see {@see PlayerSync}), for the CLI/cron to
 * report and for tests to assert against.
 */
final class PlayerSyncResult
{
    public function __construct(
        public readonly int $runId,
        public readonly int $season,
        public readonly int $playersUpserted,
        public readonly int $unmatched,
        public readonly int $defenseRanksAvailable,
        public readonly int $defensesRanked,
        public readonly int $byesAvailable,
        public readonly int $byesSet,
    ) {
    }
}
