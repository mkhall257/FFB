<?php

declare(strict_types=1);

namespace FFB\Http;

/**
 * An immutable HTTP request: the method, the normalized path, and the POST/GET
 * parameters. Constructed from PHP superglobals in production
 * (via {@see fromGlobals}) and directly in tests.
 */
final class Request
{
    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $post = [],
        public readonly array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return new self(
            $method,
            self::normalizePath(is_string($rawPath) ? $rawPath : '/'),
            $_POST,
            $_GET,
        );
    }

    /**
     * A POST field as a string, or $default when absent.
     */
    public function input(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $this->post)
            ? (string) $this->post[$key]
            : $default;
    }

    /**
     * Strip a trailing slash so "/login" and "/login/" route the same;
     * the root path stays "/".
     */
    public static function normalizePath(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
