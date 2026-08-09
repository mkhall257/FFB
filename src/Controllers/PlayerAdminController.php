<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;
use FFB\View;

/**
 * Commissioner-only read views over the Player catalog: the Unmatched Players
 * review and the latest sync status.
 */
final class PlayerAdminController
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly PlayerSyncLogRepository $syncLog,
        private readonly View $view,
    ) {
    }

    public function unmatched(Request $request, Session $session): Response
    {
        return Response::html($this->view->page('unmatched_players', 'Unmatched players', [
            'players' => $this->players->listUnmatched(),
            'playerCount' => $this->players->count(),
            'linkedCount' => $this->players->linkedCount(),
            'lastSync' => $this->syncLog->latest(),
        ], '', '', 'layout_app'));
    }
}
