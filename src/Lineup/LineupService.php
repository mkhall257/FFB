<?php

declare(strict_types=1);

namespace FFB\Lineup;

use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\RosterRepository;

/**
 * Owns the weekly Lineup lifecycle: the slot plan from roster.* settings,
 * carry-forward defaulting, and Week-1 auto-fill of best-legal Players. Saving a
 * Manager's chosen Lineup with legality and lock checks is added in later tasks.
 */
final class LineupService
{
    private const FLEX_ELIGIBLE = ['RB', 'WR', 'TE'];

    public function __construct(
        private readonly LineupRepository $lineups,
        private readonly RosterRepository $rosters,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    /**
     * The ordered physical slots for the League's roster shape.
     *
     * @param array<string,string> $settings
     * @return list<array{roster_slot:string,slot_index:int}>
     */
    public function slotPlan(array $settings): array
    {
        $shape = [
            'QB' => (int) ($settings['roster.qb'] ?? 0),
            'RB' => (int) ($settings['roster.rb'] ?? 0),
            'WR' => (int) ($settings['roster.wr'] ?? 0),
            'TE' => (int) ($settings['roster.te'] ?? 0),
            'FLEX' => (int) ($settings['roster.flex'] ?? 0),
            'K' => (int) ($settings['roster.k'] ?? 0),
            'DEF' => (int) ($settings['roster.def'] ?? 0),
        ];
        $plan = [];
        foreach ($shape as $slot => $count) {
            for ($i = 0; $i < $count; $i++) {
                $plan[] = ['roster_slot' => $slot, 'slot_index' => $i];
            }
        }

        return $plan;
    }

    /**
     * Ensure the Team has a Lineup for the week: carry forward the previous
     * week's, or (Week 1 / no prior) auto-fill best-legal rostered Players. A
     * no-op when a Lineup already exists.
     */
    public function ensureLineup(int $leagueId, int $seasonId, int $week, int $teamId): void
    {
        if ($this->lineups->forTeamWeek($seasonId, $week, $teamId) !== []) {
            return;
        }

        $settings = $this->settings->all($leagueId, $seasonId);
        $plan = $this->slotPlan($settings);

        $prior = $week > 1 ? $this->lineups->forTeamWeek($seasonId, $week - 1, $teamId) : [];
        $slots = $prior !== []
            ? $this->carryForward($plan, $prior)
            : $this->autoFill($plan, $seasonId, $teamId);

        $this->lineups->replaceForTeamWeek($leagueId, $seasonId, $week, $teamId, $slots);
    }

    /**
     * @param list<array{roster_slot:string,slot_index:int}> $plan
     * @param list<array{roster_slot:string,slot_index:int,player_id:?string}> $prior
     * @return list<array{roster_slot:string,slot_index:int,player_id:?string}>
     */
    private function carryForward(array $plan, array $prior): array
    {
        $priorByKey = [];
        foreach ($prior as $p) {
            $priorByKey[$p['roster_slot'] . ':' . $p['slot_index']] = $p['player_id'];
        }
        $slots = [];
        foreach ($plan as $s) {
            $slots[] = $s + ['player_id' => $priorByKey[$s['roster_slot'] . ':' . $s['slot_index']] ?? null];
        }

        return $slots;
    }

    /**
     * Fill each slot with the best available rostered Player of an eligible
     * position (FLEX from leftover RB/WR/TE), never starting a Player twice.
     *
     * @param list<array{roster_slot:string,slot_index:int}> $plan
     * @return list<array{roster_slot:string,slot_index:int,player_id:?string}>
     */
    private function autoFill(array $plan, int $seasonId, int $teamId): array
    {
        $byPos = ['QB' => [], 'RB' => [], 'WR' => [], 'TE' => [], 'K' => [], 'DEF' => []];
        foreach ($this->rosters->byTeam($seasonId)[$teamId] ?? [] as $p) {
            $byPos[$p['position']][] = $p['player_id'];
        }

        $used = [];
        $slots = [];
        foreach ($plan as $s) {
            $pick = $this->takeBest($s['roster_slot'], $byPos, $used);
            if ($pick !== null) {
                $used[$pick] = true;
            }
            $slots[] = $s + ['player_id' => $pick];
        }

        return $slots;
    }

    /**
     * @param array<string,list<string>> $byPos
     * @param array<string,bool> $used
     */
    private function takeBest(string $slot, array $byPos, array $used): ?string
    {
        $pools = $slot === 'FLEX' ? self::FLEX_ELIGIBLE : [$slot];
        foreach ($pools as $pos) {
            foreach ($byPos[$pos] ?? [] as $pid) {
                if (!isset($used[$pid])) {
                    return $pid;
                }
            }
        }

        return null;
    }
}
