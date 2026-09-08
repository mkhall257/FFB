<?php

declare(strict_types=1);

namespace FFB\Players;

use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;

/**
 * The one player-sync pipeline, shared by the CLI ({@see bin/sync-players.php})
 * and the daily cron ({@see cron/sync_players.php}) so they can never drift
 * apart again. A sync is three steps, in order:
 *
 *   1. import the rosterable Player universe from Sleeper (+ the nflverse id
 *      crosswalk) — this sets each Player's Sleeper search_rank, which is NULL
 *      for team defenses;
 *   2. rank team defenses from the FantasyPros DST consensus (Sleeper ships no
 *      rank for them) so they don't fall to the bottom, alphabetical;
 *   3. set the current season's bye weeks.
 *
 * Steps 2 and 3 MUST run every sync: the import (step 1) rewrites defenses with
 * Sleeper's null rank and does not touch byes, so a sync that stops after step 1
 * — as the cron used to — leaves defenses unranked and byes stale. That was the
 * "defenses show blank and alphabetical" regression.
 *
 * {@see apply()} is the pure DB pipeline over already-fetched data, so it can be
 * tested against fixtures without the network; {@see run()} adds the fetching
 * and the player_sync_log bookkeeping around it.
 */
final class PlayerSync
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly PlayerSyncLogRepository $log,
        private readonly SleeperClient $sleeper = new SleeperClient(),
        private readonly PlayerIdCrosswalk $crosswalk = new PlayerIdCrosswalk(),
        private readonly FantasyProsDefenseRankings $defenseRankings = new FantasyProsDefenseRankings(),
    ) {
    }

    /**
     * The NFL season today belongs to: Sept–Feb belongs to the year the season
     * kicked off in, so before March we're still in last year's season.
     */
    public static function currentNflSeason(): int
    {
        return (int) date('n') >= 3 ? (int) date('Y') : (int) date('Y') - 1;
    }

    /**
     * Fetch everything and apply it, recording the run in player_sync_log. On
     * failure the log entry is marked errored and the throwable re-thrown for the
     * caller to report.
     */
    public function run(?int $season = null): PlayerSyncResult
    {
        $season ??= self::currentNflSeason();
        $runId = $this->log->start();

        try {
            $sleeperPlayers = $this->sleeper->fetchPlayers();
            $crosswalk = $this->crosswalk->fetch();
            $defenseRanks = $this->defenseRankings->fetch();
            $byes = (new NflByeWeeks($season))->fetch();

            $result = $this->apply($sleeperPlayers, $crosswalk, $defenseRanks, $byes, $runId, $season);
            $this->log->finishSuccess($runId, $result->playersUpserted, $result->unmatched);

            return $result;
        } catch (\Throwable $e) {
            $this->log->finishError($runId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * The pure DB pipeline over already-fetched inputs (see the class docblock
     * for why all three steps must run). No network, no logging — testable with
     * fixtures.
     *
     * @param array<string,array<string,mixed>> $sleeperPlayers Sleeper feed
     * @param array<string,string> $crosswalk                   sleeper_id => gsis_id
     * @param array<string,int> $defenseRanks                   NFL team => DST rank
     * @param array<string,int> $byes                           NFL team => bye week
     */
    public function apply(
        array $sleeperPlayers,
        array $crosswalk,
        array $defenseRanks,
        array $byes,
        int $runId = 0,
        int $season = 0,
    ): PlayerSyncResult {
        $import = (new PlayerImporter($this->players))->import($sleeperPlayers, $crosswalk);
        $defensesRanked = $this->players->assignDefenseRanks($defenseRanks);
        $byesSet = $this->players->assignByeWeeks($byes);

        return new PlayerSyncResult(
            runId: $runId,
            season: $season,
            playersUpserted: $import->upserted,
            unmatched: $import->unmatchedCount(),
            defenseRanksAvailable: count($defenseRanks),
            defensesRanked: $defensesRanked,
            byesAvailable: count($byes),
            byesSet: $byesSet,
        );
    }
}
