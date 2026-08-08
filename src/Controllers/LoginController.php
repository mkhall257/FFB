<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Auth;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\View;

/**
 * Handles logging in and out.
 */
final class LoginController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function show(Request $request, Session $session): Response
    {
        return Response::html($this->view->page('login', 'Log in', ['error' => null]));
    }

    public function submit(Request $request, Session $session): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if (
            $username !== '' && $password !== ''
            && $this->auth->attempt($session, $this->leagues->currentLeagueId(), $username, $password)
        ) {
            return Response::redirect('/');
        }

        return Response::html(
            $this->view->page('login', 'Log in', ['error' => 'Incorrect username or password.']),
            401,
        );
    }

    public function logout(Request $request, Session $session): Response
    {
        $this->auth->logout($session);

        return Response::redirect('/login');
    }
}
