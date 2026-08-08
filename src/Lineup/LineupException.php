<?php

declare(strict_types=1);

namespace FFB\Lineup;

use RuntimeException;

/**
 * Thrown when a Lineup save is illegal (unrostered/ineligible/duplicate player)
 * or the week is locked. Carries an HTTP-style status, mirroring DraftPickException.
 */
final class LineupException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
