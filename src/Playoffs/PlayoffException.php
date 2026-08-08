<?php

declare(strict_types=1);

namespace FFB\Playoffs;

use RuntimeException;

/**
 * Thrown when a Playoff bracket action is illegal — creating a bracket before
 * the regular season is settled, a misconfigured field size, advancing a round
 * that isn't final, correcting or resetting once results have locked in, and so
 * on. Carries an HTTP-style status, mirroring TransactionException /
 * LineupException.
 */
final class PlayoffException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
