<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LineupRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;
use FFB\UserRepository;

final class LineupHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
    }

    /**
     * A team owned by a manager user, with one rostered QB and WR.
     *
     * @return array{0:int,1:int} [teamId, userId]
     */
    private function managedRosteredTeam(): array
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Mine');
        $userId = (new UserRepository($this->pdo))->create($this->leagueId, 'mgr', 'password1', 'manager', 'Manager');
        (new TeamRepository($this->pdo))->assignManager($teamId, $userId);

        $players = new PlayerRepository($this->pdo);
        foreach ([['QB1', 'QB'], ['WR1', 'WR']] as [$id, $pos]) {
            $players->upsert($id, null, $id, $pos, 'KC', 'Active', 1);
            $this->pdo->prepare('INSERT INTO rosters (league_id, season_id, team_id, player_id) VALUES (?,?,?,?)')
                ->execute([$this->leagueId, $this->seasonId, $teamId, $id]);
        }

        return [$teamId, $userId];
    }

    private function session(int $userId): ArraySession
    {
        return new ArraySession([
            'user_id' => $userId, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'Manager',
        ]);
    }

    private function dispatch(int $userId, string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $this->session($userId));
    }

    public function testManagerCanViewTheirEnsuredLineup(): void
    {
        [, $userId] = $this->managedRosteredTeam();
        $response = $this->dispatch($userId, 'GET', '/lineup');

        $this->assertSame(200, $response->status);
        // The auto-filled QB should be preselected in the rendered form.
        $this->assertStringContainsString('QB1', $response->body);
    }

    public function testSavingALegalLineupPersistsAndRedirects(): void
    {
        [$teamId, $userId] = $this->managedRosteredTeam();

        $response = $this->dispatch($userId, 'POST', '/lineup', [
            'players' => ['QB:0' => 'QB1', 'WR:0' => 'WR1'],
        ]);

        $this->assertSame(302, $response->status);
        $rows = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId, 1, $teamId);
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['roster_slot'] . ':' . $r['slot_index']] = $r['player_id'];
        }
        $this->assertSame('QB1', $byKey['QB:0']);
        $this->assertSame('WR1', $byKey['WR:0']);
    }

    public function testSavingAnIneligiblePlayerReportsError(): void
    {
        [, $userId] = $this->managedRosteredTeam();

        $response = $this->dispatch($userId, 'POST', '/lineup', [
            'players' => ['QB:0' => 'WR1'], // WR in the QB slot
        ]);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('start at QB', $response->body);
    }
}
