<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * A deactivated Team is dropped from the reads that enroll Teams into play:
 * TeamRepository::activeIdsForSeason and, through it, the Draft order.
 */
final class InactiveTeamExclusionTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    public function testActiveIdsForSeasonExcludesInactiveTeams(): void
    {
        $teams = new TeamRepository($this->pdo);
        $a = $teams->create($this->leagueId(), $this->seasonId(), 'Sharks');
        $b = $teams->create($this->leagueId(), $this->seasonId(), 'Bears');
        $c = $teams->create($this->leagueId(), $this->seasonId(), 'Wolves');

        $teams->setActive($this->leagueId(), $this->seasonId(), $b, false);

        $active = $teams->activeIdsForSeason($this->leagueId(), $this->seasonId());
        $all = $teams->idsForSeason($this->leagueId(), $this->seasonId());

        $this->assertSame([$a, $c], $active, 'inactive team must be excluded');
        $this->assertSame([$a, $b, $c], $all, 'idsForSeason still returns every team');
    }

    public function testDeactivatedTeamIsLeftOutOfDraftOrder(): void
    {
        $teams = new TeamRepository($this->pdo);
        $a = $teams->create($this->leagueId(), $this->seasonId(), 'Sharks');
        $b = $teams->create($this->leagueId(), $this->seasonId(), 'Bears');
        $c = $teams->create($this->leagueId(), $this->seasonId(), 'Wolves');
        $teams->setActive($this->leagueId(), $this->seasonId(), $c, false);

        $commissioner = new ArraySession([
            'user_id' => 1, 'role' => 'commissioner', 'league_id' => $this->leagueId(), 'display_name' => 'Boss',
        ]);
        $response = Kernel::router($this->pdo)->dispatch(
            new Request('POST', '/admin/draft/order/randomize'),
            $commissioner,
        );

        $this->assertSame(302, $response->status);

        $order = array_map(
            intval(...),
            $this->pdo->query('SELECT team_id FROM draft_order ORDER BY team_id')->fetchAll(\PDO::FETCH_COLUMN),
        );
        $this->assertSame([$a, $b], $order, 'the deactivated team must not be in the draft order');
    }
}
