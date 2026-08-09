<?php

declare(strict_types=1);

namespace FFB\Http;

use FFB\View;

/**
 * Maps (method, path) to a handler and enforces the role gate before the
 * handler runs.
 *
 * A route's required role is one of:
 *   - null            → public
 *   - 'authenticated' → any logged-in user (Commissioner or Manager)
 *   - 'commissioner'  → Commissioner only
 *
 * An anonymous request to a protected route is redirected to /login; a
 * logged-in user with insufficient role gets 403.
 *
 * Handlers have the signature: fn(Request, Session): Response.
 */
final class Router
{
    /** @var list<array{method:string,path:string,handler:callable,role:?string}> */
    private array $routes = [];

    /** Optional shared View, populated with the current user for the page chrome. */
    public function __construct(private readonly ?View $view = null)
    {
    }

    public function get(string $path, callable $handler, ?string $role = null): void
    {
        $this->add('GET', $path, $handler, $role);
    }

    public function post(string $path, callable $handler, ?string $role = null): void
    {
        $this->add('POST', $path, $handler, $role);
    }

    private function add(string $method, string $path, callable $handler, ?string $role): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'role' => $role,
        ];
    }

    public function dispatch(Request $request, Session $session): Response
    {
        $role = $session->get('role');
        $name = $session->get('display_name');
        $this->view?->setCurrentUser(
            is_string($role) ? $role : null,
            is_string($name) ? $name : null,
        );

        $pathMatched = false;

        foreach ($this->routes as $route) {
            if ($route['path'] !== $request->path) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            $denied = $this->enforceRole($route['role'], $session);
            if ($denied !== null) {
                return $denied;
            }

            return ($route['handler'])($request, $session);
        }

        return $pathMatched
            ? Response::html('Method Not Allowed', 405)
            : Response::html('Not Found', 404);
    }

    /**
     * Returns a denial response if the session fails the role requirement,
     * or null if access is allowed.
     */
    private function enforceRole(?string $role, Session $session): ?Response
    {
        if ($role === null) {
            return null;
        }

        if ($session->get('user_id') === null) {
            return Response::redirect('/login');
        }

        if ($role === 'commissioner' && $session->get('role') !== 'commissioner') {
            return Response::html('Forbidden', 403);
        }

        return null;
    }
}
