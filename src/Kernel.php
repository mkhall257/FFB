<?php

declare(strict_types=1);

namespace FFB;

use FFB\Controllers\AdminController;
use FFB\Controllers\DraftController;
use FFB\Controllers\DraftRoomController;
use FFB\Controllers\HomeController;
use FFB\Controllers\LineupController;
use FFB\Controllers\LoginController;
use FFB\Controllers\PlayerAdminController;
use FFB\Controllers\PlayersController;
use FFB\Controllers\ScoreboardController;
use FFB\Controllers\SeasonController;
use FFB\Controllers\StandingsController;
use FFB\Controllers\TransactionsController;
use FFB\Draft\AutoPickStrategy;
use FFB\Draft\DraftService;
use FFB\Http\Router;
use FFB\Lineup\LineupService;
use FFB\Lineup\WeekLock;
use FFB\Schedule\ScheduleGenerator;
use FFB\Schedule\ScheduleService;
use FFB\Transactions\TransactionService;
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
        $draftPicks = new DraftPickRepository($pdo);
        $draftQueues = new DraftQueueRepository($pdo);
        $rosters = new RosterRepository($pdo);
        $settings = new LeagueSettingsRepository($pdo);
        $matchups = new MatchupRepository($pdo);
        $lineupRepo = new LineupRepository($pdo);
        $transactionLedger = new TransactionRepository($pdo);
        $schedule = new ScheduleService(new ScheduleGenerator(), $matchups, $teams, $settings);
        $lineupService = new LineupService($lineupRepo, $rosters, $settings, new WeekLock($settings));
        $transactionService = new TransactionService($pdo, $rosters, $players, $settings, $transactionLedger, $lineupService, $lineupRepo);

        $login = new LoginController($auth, $leagues, $view);
        $home = new HomeController($view);
        $admin = new AdminController($pdo, $teams, $users, $leagues, $view);
        $playerAdmin = new PlayerAdminController($players, $syncLog, $view);
        $autoPick = new AutoPickStrategy($draftQueues, $draftPicks, $players);
        $draftService = new DraftService($pdo, $drafts, $draftPicks, $players, $autoPick, $rosters, $settings, $leagues, $schedule);

        $draft = new DraftController($pdo, $drafts, $draftPicks, $draftService, $settings, $teams, $players, $rosters, $leagues, $matchups, $view);
        $draftRoom = new DraftRoomController($draftService, $drafts, $draftPicks, $draftQueues, $teams, $players, $leagues, $view);
        $standings = new StandingsController(new StandingsService($pdo), $teams, $leagues, $view);
        $scoreboard = new ScoreboardController($matchups, $teams, $settings, $leagues, $view);
        $lineup = new LineupController($lineupService, $lineupRepo, $rosters, $teams, $settings, $leagues, $view);
        $season = new SeasonController($settings, $leagues, $view);
        $playersPage = new PlayersController($transactionService, $players, $rosters, $teams, $leagues, $view);
        $transactionsPage = new TransactionsController($transactionLedger, $leagues, $view);

        $router = new Router();
        $router->get('/login', [$login, 'show']);
        $router->post('/login', [$login, 'submit']);
        $router->post('/logout', [$login, 'logout'], 'authenticated');
        $router->get('/', [$home, 'index'], 'authenticated');
        $router->get('/standings', [$standings, 'index'], 'authenticated');
        $router->get('/scoreboard', [$scoreboard, 'index'], 'authenticated');
        $router->get('/lineup', [$lineup, 'index'], 'authenticated');
        $router->post('/lineup', [$lineup, 'save'], 'authenticated');
        $router->get('/players', [$playersPage, 'index'], 'authenticated');
        $router->post('/players/add', [$playersPage, 'add'], 'authenticated');
        $router->get('/transactions', [$transactionsPage, 'index'], 'authenticated');

        $router->get('/admin', [$admin, 'index'], 'commissioner');
        $router->post('/admin/teams', [$admin, 'createTeam'], 'commissioner');
        $router->post('/admin/managers', [$admin, 'createManager'], 'commissioner');
        $router->post('/admin/managers/reset', [$admin, 'resetPassword'], 'commissioner');
        $router->post('/admin/managers/status', [$admin, 'setManagerStatus'], 'commissioner');
        $router->get('/admin/unmatched-players', [$playerAdmin, 'unmatched'], 'commissioner');

        $router->get('/admin/season', [$season, 'index'], 'commissioner');
        $router->post('/admin/season/week', [$season, 'startWeek'], 'commissioner');
        $router->post('/admin/season/scoring', [$season, 'saveScoring'], 'commissioner');
        $router->post('/admin/season/roster', [$season, 'saveRoster'], 'commissioner');

        $router->get('/admin/draft', [$draft, 'setup'], 'commissioner');
        $router->post('/admin/draft/config', [$draft, 'configure'], 'commissioner');
        $router->post('/admin/draft/order/randomize', [$draft, 'randomizeOrder'], 'commissioner');
        $router->post('/admin/draft/order', [$draft, 'reorder'], 'commissioner');
        $router->post('/admin/draft/finalize', [$draft, 'finalize'], 'commissioner');
        $router->post('/admin/draft/start', [$draft, 'start'], 'commissioner');
        $router->post('/admin/draft/pause', [$draft, 'pause'], 'commissioner');
        $router->post('/admin/draft/resume', [$draft, 'resume'], 'commissioner');
        $router->post('/admin/draft/add-time', [$draft, 'addTime'], 'commissioner');
        $router->post('/admin/draft/pick-on-behalf', [$draft, 'pickOnBehalf'], 'commissioner');
        $router->post('/admin/draft/auto-draft', [$draft, 'toggleAutoDraft'], 'commissioner');
        $router->post('/admin/draft/correct-pick', [$draft, 'correctPick'], 'commissioner');
        $router->post('/admin/draft/undo-last', [$draft, 'undoLast'], 'commissioner');
        $router->post('/admin/draft/reset', [$draft, 'reset'], 'commissioner');

        $router->get('/draft', [$draftRoom, 'index'], 'authenticated');
        $router->post('/draft/pick', [$draftRoom, 'pick'], 'authenticated');
        $router->post('/draft/queue/add', [$draftRoom, 'addToQueue'], 'authenticated');
        $router->post('/draft/queue/remove', [$draftRoom, 'removeFromQueue'], 'authenticated');
        $router->post('/draft/queue/reorder', [$draftRoom, 'reorderQueue'], 'authenticated');

        return $router;
    }
}
