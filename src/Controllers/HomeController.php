<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\View;

/**
 * The authenticated landing page and a Commissioner-only placeholder that the
 * Team/Manager admin screens (next slice) will replace.
 */
final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request, Session $session): Response
    {
        return Response::html($this->view->page('home', 'Home', [
            'displayName' => (string) $session->get('display_name', ''),
            'role' => (string) $session->get('role', ''),
        ]));
    }

    public function admin(Request $request, Session $session): Response
    {
        return Response::html($this->view->page('admin', 'Commissioner tools'));
    }
}
