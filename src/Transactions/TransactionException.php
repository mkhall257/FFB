<?php

declare(strict_types=1);

namespace FFB\Transactions;

use RuntimeException;

/**
 * Thrown when a Transaction (Add/Drop, Trade, reversal, or Commissioner edit) is
 * illegal — a full Roster with no drop, an unavailable Player, an invalid Trade
 * at accept time, a conflicted reversal, and so on. Carries an HTTP-style status,
 * mirroring LineupException / DraftPickException.
 */
final class TransactionException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
