<?php

declare(strict_types=1);

namespace FFB\Http;

/**
 * An in-memory {@see Session} backed by a plain array. Used in tests so the
 * HTTP seam can be exercised without PHP's native session machinery.
 */
final class ArraySession implements Session
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function regenerate(): void
    {
        // No identifier to rotate for an in-memory store.
    }
}
