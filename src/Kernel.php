<?php

declare(strict_types=1);

namespace FFB;

use FFB\Controllers\AdminController;
use FFB\Controllers\DraftController;
use FFB\Controllers\HomeController;
use FFB\Controllers\LoginController;
use FFB\Controllers\PlayerAdminController;
use FFB\Http\Router;
use PDO;

/**
 * Wires the application together: builds the repositories, services,
 * controllers, and the route table. Both the front controller and the tests
 * obtain their Router here, so they exercise identical wiring.
 */
final class Kernel
{
    public static function router(PDO $pdo): Router
    {
        $view = new View(dirname(__DIR__) . '/views');

        $users = new UserRepository($pdo);
        $teams = new TeamRepository($pdo);
        $auth = new Auth($users);
        $leagues = new LeagueRepository($pdo);

        $players = new PlayerRepository($pdo);
        $syncLog = new PlayerSyncLogRepository($pdo);
        $drafts = new DraftRepository($pdo);
        $settings = new LeagueSettingsRepository($pdo);

        $login = new LoginController($auth, $leagues, $view);
        $home = new HomeController($view);
        $admin = new AdminController($pdo, $teams, $users, $leagues, $view);
        $playerAdmin = new PlayerAdminController($players, $syncLog, $view);
        $draft = new DraftController($pdo, $drafts, $settings, $leagues, $view);

        $router = new Router();
        $router->get('/login', [$login, 'show']);
        $router->post('/login', [$login, 'submit']);
        $router->post('/logout', [$login, 'logout'], 'authenticated');
        $router->get('/', [$home, 'index'], 'authenticated');

        $router->get('/admin', [$admin, 'index'], 'commissioner');
        $router->post('/admin/teams', [$admin, 'createTeam'], 'commissioner');
        $router->post('/admin/managers', [$admin, 'createManager'], 'commissioner');
        $router->post('/admin/managers/reset', [$admin, 'resetPassword'], 'commissioner');
        $router->post('/admin/managers/status', [$admin, 'setManagerStatus'], 'commissioner');
        $router->get('/admin/unmatched-players', [$playerAdmin, 'unmatched'], 'commissioner');

        $router->get('/admin/draft', [$draft, 'setup'], 'commissioner');
        $router->post('/admin/draft/config', [$draft, 'configure'], 'commissioner');

        return $router;
    }
}
