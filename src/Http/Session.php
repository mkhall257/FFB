<?php

declare(strict_types=1);

namespace FFB\Http;

/**
 * A key/value session store. Abstracted behind an interface so the app can run
 * on PHP's native session in production ({@see PhpSession}) and on a plain
 * array in tests ({@see ArraySession}).
 */
interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function clear(): void;

    /**
     * Rotate the session identifier — called on privilege changes such as
     * login, to prevent session fixation.
     */
    public function regenerate(): void;
}
