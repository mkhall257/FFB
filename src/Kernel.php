<?php

declare(strict_types=1);

namespace FFB;

use FFB\Controllers\AdminController;
use FFB\Controllers\HomeController;
use FFB\Controllers\LoginController;
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

        $login = new LoginController($auth, $leagues, $view);
        $home = new HomeController($view);
        $admin = new AdminController($pdo, $teams, $users, $leagues, $view);

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

        return $router;
    }
}
