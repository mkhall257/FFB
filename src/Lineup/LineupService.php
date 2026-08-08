<?php

declare(strict_types=1);

namespace FFB\Lineup;

use Closure;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\RosterRepository;

/**
 * Owns the weekly Lineup lifecycle: the slot plan from roster.* settings,
 * carry-forward defaulting, Week-1 auto-fill of best-legal Players, and saving a
 * Manager's chosen Lineup with legality and kickoff-lock checks.
 */
final class LineupService
{
    private const FLEX_ELIGIBLE = ['RB', 'WR', 'TE'];

    /** @var Closure(): int */
    private Closure $now;

    /**
     * @param (callable(): int)|null $now current unix time provider (for tests)
     */
    public function __construct(
        private readonly LineupRepository $lineups,
        private readonly RosterRepository $rosters,
        private readonly LeagueSettingsRepository $settings,
        private readonly WeekLock $lock,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
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
     * Validate a Manager's chosen Lineup and persist it. Every non-null Player
     * must be on the Team's Roster, appear at most once, and be eligible for its
     * slot (exact position, or RB/WR/TE for FLEX).
     *
     * @param list<array{roster_slot:string,slot_index:int,player_id:?string}> $assignments
     * @throws LineupException on any illegal assignment
     */
    public function saveLineup(int $leagueId, int $seasonId, int $week, int $teamId, array $assignments): void
    {
        if ($this->lock->isLocked($leagueId, $seasonId, $week, ($this->now)())) {
            throw new LineupException(423, 'Lineups are locked for this week.');
        }

        $rosterPos = [];
        foreach ($this->rosters->byTeam($seasonId)[$teamId] ?? [] as $p) {
            $rosterPos[$p['player_id']] = $p['position'];
        }

        $seen = [];
        foreach ($assignments as $a) {
            $pid = $a['player_id'];
            if ($pid === null) {
                continue;
            }
            if (!isset($rosterPos[$pid])) {
                throw new LineupException(422, 'That player is not on your roster.');
            }
            if (isset($seen[$pid])) {
                throw new LineupException(422, 'A player can only start in one slot.');
            }
            $seen[$pid] = true;
            if (!$this->eligible($a['roster_slot'], $rosterPos[$pid])) {
                throw new LineupException(422, "That player can't start at {$a['roster_slot']}.");
            }
        }

        $this->lineups->replaceForTeamWeek($leagueId, $seasonId, $week, $teamId, $assignments);
    }

    private function eligible(string $slot, string $position): bool
    {
        return $slot === 'FLEX'
            ? in_array($position, self::FLEX_ELIGIBLE, true)
            : $slot === $position;
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
