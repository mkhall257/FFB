<?php

declare(strict_types=1);

namespace FFB\Schedule;

use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\TeamRepository;

/**
 * Builds and persists the regular-season Schedule from the final Team set at
 * Draft completion. Replaces any existing schedule for the Season so a
 * regenerate (e.g. after the Draft is reopened and re-completed) is clean.
 */
final class ScheduleService
{
    public function __construct(
        private readonly ScheduleGenerator $generator,
        private readonly MatchupRepository $matchups,
        private readonly TeamRepository $teams,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    public function generateForSeason(int $leagueId, int $seasonId): void
    {
        $teamIds = $this->teams->idsForSeason($leagueId, $seasonId);
        $settings = $this->settings->all($leagueId, $seasonId);
        $weeks = (int) ($settings['schedule.regular_season_weeks'] ?? 14);

        $this->matchups->clearForSeason($seasonId);
        $rows = $this->generator->generate($teamIds, $weeks);
        $this->matchups->insertMany($leagueId, $seasonId, $rows);
    }
}
