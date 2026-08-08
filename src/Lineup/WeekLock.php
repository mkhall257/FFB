<?php

declare(strict_types=1);

namespace FFB\Lineup;

use FFB\LeagueSettingsRepository;

/**
 * Resolves whether a week's Lineups are locked. Wave 3 locks the whole Lineup at
 * the week's first NFL kickoff, stored as schedule.week_<n>_kickoff (an ISO 8601
 * timestamp). When no kickoff is configured the week is treated as unlocked, so
 * pre-season editing (and tests) can edit freely.
 */
final class WeekLock
{
    public function __construct(private readonly LeagueSettingsRepository $settings)
    {
    }

    public function isLocked(int $leagueId, int $seasonId, int $week, int $now): bool
    {
        $all = $this->settings->all($leagueId, $seasonId);
        $kickoff = $all['schedule.week_' . $week . '_kickoff'] ?? null;
        if ($kickoff === null || $kickoff === '') {
            return false;
        }

        return $now >= strtotime($kickoff);
    }
}
