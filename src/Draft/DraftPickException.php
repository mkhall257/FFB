<?php

declare(strict_types=1);

namespace FFB\Draft;

/**
 * A rejected Draft pick. Carries the HTTP status the caller should surface
 * (403 not your turn, 409 wrong Draft state / Player gone, 400 bad input).
 */
final class DraftPickException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
