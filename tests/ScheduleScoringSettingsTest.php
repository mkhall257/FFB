<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class ScheduleScoringSettingsTest extends DatabaseTestCase
{
    public function testDefaultsIncludeScheduleLengthAndKickerDefenseScoring(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $settings = (new LeagueSettingsRepository($this->pdo))->all(
            $leagues->currentLeagueId(),
            $leagues->currentSeasonId(),
        );

        $this->assertSame('14', $settings['schedule.regular_season_weeks']);
        $this->assertSame('3', $settings['scoring.fg_made']);
        $this->assertSame('7', $settings['scoring.def_pa_1_6']);
    }
}
