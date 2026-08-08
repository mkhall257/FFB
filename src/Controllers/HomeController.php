<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\View;

/**
 * The authenticated landing page shown after login.
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
}
