<?php

declare(strict_types=1);

namespace FFB\Draft;

use FFB\DraftPickRepository;
use FFB\DraftQueueRepository;
use FFB\PlayerRepository;

/**
 * Chooses the Player an Auto-pick should take for a Team, implementing the
 * ADR-0007 strategy:
 *
 *   1. the highest available Player in the Team's Queue;
 *   2. otherwise the best available Player by Sleeper rank, position-aware;
 *   3. and, when roster space runs short, restricted to the positions that
 *      still need filling so the Team always ends with a legal starting Lineup.
 *
 * The "danger zone" is when the Team's remaining picks are no more than the
 * number of required slots still to fill; from then on selection is restricted
 * to the still-needed positions. Outside it, the Queue is honoured as-is and
 * the fallback takes the best player available.
 */
final class AutoPickStrategy
{
    /** Positions eligible for the FLEX slot. */
    private const FLEX_ELIGIBLE = ['RB', 'WR', 'TE'];

    /** Positions with their own required starter slot. */
    private const STARTER_POSITIONS = ['QB', 'RB', 'WR', 'TE', 'K', 'DEF'];

    public function __construct(
        private readonly DraftQueueRepository $queues,
        private readonly DraftPickRepository $picks,
        private readonly PlayerRepository $players,
    ) {
    }

    /**
     * @param array<string,string> $settings league_settings (roster.*)
     */
    public function choose(int $draftId, int $teamId, array $settings): ?string
    {
        $filter = $this->neededPositionsIfInDanger($draftId, $teamId, $settings);

        // 1. Queue: the highest queued Player still available (and, in the
        //    danger zone, of a position we still need).
        foreach ($this->queues->playerIds($draftId, $teamId) as $playerId) {
            if ($this->picks->isPlayerTaken($draftId, $playerId)) {
                continue;
            }
            if (!$this->players->isDraftable($playerId)) {
                continue;
            }
            if ($filter !== null) {
                $position = $this->players->positionOf($playerId);
                if ($position === null || !in_array($position, $filter, true)) {
                    continue;
                }
            }

            return $playerId;
        }

        // 2/3. Global fallback, position-aware when in the danger zone.
        $best = $this->players->bestAvailable($draftId, $filter);
        if ($best !== null) {
            return $best;
        }

        // Safety net: nothing in the needed positions is left — take any
        // available Player rather than stall the Draft.
        return $filter === null ? null : $this->players->bestAvailable($draftId, null);
    }

    /**
     * The positions the Team must still fill, or null when it is not yet in the
     * danger zone (plenty of picks left relative to required slots).
     *
     * @param array<string,string> $settings
     * @return list<string>|null
     */
    private function neededPositionsIfInDanger(int $draftId, int $teamId, array $settings): ?array
    {
        $slot = static fn (string $key): int => (int) ($settings['roster.' . $key] ?? 0);
        $have = $this->picks->rosterPositionCounts($draftId, $teamId);
        $get = static fn (string $position): int => $have[$position] ?? 0;

        $rounds = $slot('qb') + $slot('rb') + $slot('wr') + $slot('te')
            + $slot('flex') + $slot('k') + $slot('def') + $slot('bench');
        $remaining = $rounds - array_sum($have);

        $dedicatedNeed = [];
        foreach (self::STARTER_POSITIONS as $position) {
            $dedicatedNeed[$position] = max(0, $slot(strtolower($position)) - $get($position));
        }

        $flexSurplus = 0;
        foreach (self::FLEX_ELIGIBLE as $position) {
            $flexSurplus += max(0, $get($position) - $slot(strtolower($position)));
        }
        $flexNeed = max(0, $slot('flex') - $flexSurplus);

        $mandatory = array_sum($dedicatedNeed) + $flexNeed;

        if ($remaining > $mandatory) {
            return null;
        }

        $needed = [];
        foreach ($dedicatedNeed as $position => $count) {
            if ($count > 0) {
                $needed[$position] = true;
            }
        }
        if ($flexNeed > 0) {
            foreach (self::FLEX_ELIGIBLE as $position) {
                $needed[$position] = true;
            }
        }

        return array_keys($needed);
    }
}
