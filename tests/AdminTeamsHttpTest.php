<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Exercises Commissioner Team & Manager management through the HTTP seam.
 */
final class AdminTeamsHttpTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    private function commissioner(): ArraySession
    {
        return new ArraySession([
            'user_id' => 1,
            'role' => 'commissioner',
            'league_id' => $this->leagueId(),
            'display_name' => 'Boss',
        ]);
    }

    /**
     * @param array<string,mixed> $post
     * @return array{0:Response,1:ArraySession}
     */
    private function dispatch(string $method, string $path, array $post = [], ?ArraySession $session = null): array
    {
        $session ??= $this->commissioner();
        $response = Kernel::router($this->pdo)
            ->dispatch(new Request($method, $path, $post), $session);

        return [$response, $session];
    }

    private function teamIdByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM teams WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    public function testCommissionerCanCreateTeam(): void
    {
        [$response] = $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);

        $this->assertSame(302, $response->status);
        $this->assertSame('/admin', $response->headers['Location']);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM teams WHERE name = 'Sharks'")->fetchColumn()
        );
    }

    public function testCreateTeamRequiresAName(): void
    {
        [$response] = $this->dispatch('POST', '/admin/teams', ['name' => '   ']);

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn());
    }

    public function testDuplicateTeamNameIsRejected(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        [$response] = $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);

        $this->assertSame(400, $response->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn());
    }

    public function testCommissionerCanProvisionManagerAndThatManagerCanLogIn(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $teamId = $this->teamIdByName('Sharks');

        [$response] = $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId,
            'display_name' => 'Ada',
            'username' => 'ada',
            'password' => 'swimfast',
        ]);

        $this->assertSame(302, $response->status);

        // Manager row exists, is a manager, and is attached to the team.
        $manager = $this->pdo->query("SELECT * FROM users WHERE username = 'ada'")->fetch();
        $this->assertSame('manager', $manager['role']);
        $this->assertSame(
            (int) $manager['id'],
            (int) $this->pdo->query("SELECT user_id FROM teams WHERE id = {$teamId}")->fetchColumn()
        );

        // And that manager can now log in through the normal login flow.
        $loginSession = new ArraySession();
        Kernel::router($this->pdo)->dispatch(
            new Request('POST', '/login', ['username' => 'ada', 'password' => 'swimfast']),
            $loginSession,
        );
        $this->assertSame('manager', $loginSession->get('role'));
    }

    public function testManagerPasswordMustMeetMinimumLength(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $teamId = $this->teamIdByName('Sharks');

        [$response] = $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId,
            'display_name' => 'Ada',
            'username' => 'ada',
            'password' => 'short',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ada'")->fetchColumn());
    }

    public function testDuplicateManagerUsernameIsRejected(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $this->dispatch('POST', '/admin/teams', ['name' => 'Bears']);
        $sharks = $this->teamIdByName('Sharks');
        $bears = $this->teamIdByName('Bears');

        $this->dispatch('POST', '/admin/managers', [
            'team_id' => $sharks, 'display_name' => 'Ada', 'username' => 'ada', 'password' => 'swimfast',
        ]);
        [$response] = $this->dispatch('POST', '/admin/managers', [
            'team_id' => $bears, 'display_name' => 'Ada Two', 'username' => 'ada', 'password' => 'swimfast',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE username = 'ada'")->fetchColumn());
        // The Bears team was left without a manager (transaction rolled back).
        $this->assertNull($this->pdo->query("SELECT user_id FROM teams WHERE id = {$bears}")->fetchColumn() ?: null);
    }

    public function testCannotAddSecondManagerToATeam(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $teamId = $this->teamIdByName('Sharks');
        $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId, 'display_name' => 'Ada', 'username' => 'ada', 'password' => 'swimfast',
        ]);

        [$response] = $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId, 'display_name' => 'Bob', 'username' => 'bob', 'password' => 'swimfast',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE username = 'bob'")->fetchColumn());
    }

    public function testResetPasswordChangesTheManagerLogin(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $teamId = $this->teamIdByName('Sharks');
        $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId, 'display_name' => 'Ada', 'username' => 'ada', 'password' => 'oldpass1',
        ]);
        $userId = (int) $this->pdo->query("SELECT id FROM users WHERE username = 'ada'")->fetchColumn();

        [$response] = $this->dispatch('POST', '/admin/managers/reset', [
            'user_id' => $userId, 'password' => 'newpass1',
        ]);
        $this->assertSame(302, $response->status);

        // Old password no longer works; new one does.
        $old = new ArraySession();
        Kernel::router($this->pdo)->dispatch(new Request('POST', '/login', ['username' => 'ada', 'password' => 'oldpass1']), $old);
        $this->assertNull($old->get('user_id'));

        $new = new ArraySession();
        Kernel::router($this->pdo)->dispatch(new Request('POST', '/login', ['username' => 'ada', 'password' => 'newpass1']), $new);
        $this->assertNotNull($new->get('user_id'));
    }

    public function testDeactivatingManagerBlocksLogin(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $teamId = $this->teamIdByName('Sharks');
        $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId, 'display_name' => 'Ada', 'username' => 'ada', 'password' => 'swimfast',
        ]);
        $userId = (int) $this->pdo->query("SELECT id FROM users WHERE username = 'ada'")->fetchColumn();

        [$response] = $this->dispatch('POST', '/admin/managers/status', [
            'user_id' => $userId, 'active' => '0',
        ]);
        $this->assertSame(302, $response->status);

        $login = new ArraySession();
        Kernel::router($this->pdo)->dispatch(new Request('POST', '/login', ['username' => 'ada', 'password' => 'swimfast']), $login);
        $this->assertNull($login->get('user_id'), 'a deactivated manager must not be able to log in');
    }

    public function testAdminPageListsTeamsAndManagers(): void
    {
        $this->dispatch('POST', '/admin/teams', ['name' => 'Sharks']);
        $teamId = $this->teamIdByName('Sharks');
        $this->dispatch('POST', '/admin/managers', [
            'team_id' => $teamId, 'display_name' => 'Ada Lovelace', 'username' => 'ada', 'password' => 'swimfast',
        ]);

        [$response] = $this->dispatch('GET', '/admin');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Sharks', $response->body);
        $this->assertStringContainsString('Ada Lovelace', $response->body);
        $this->assertStringContainsString('ada', $response->body);
    }

    public function testManagerCannotAccessTeamAdmin(): void
    {
        $managerSession = new ArraySession([
            'user_id' => 9, 'role' => 'manager', 'league_id' => $this->leagueId(), 'display_name' => 'Kid',
        ]);

        [$response] = $this->dispatch('POST', '/admin/teams', ['name' => 'Sneaky'], $managerSession);

        $this->assertSame(403, $response->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn());
    }
}
