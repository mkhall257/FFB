<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * The outcome of a player import: how many Players were upserted and which
 * ones are Unmatched (rosterable and on a team, but with no nflverse link).
 */
final class ImportResult
{
    /**
     * @param list<array{sleeper_id:string,full_name:string,position:string,nfl_team:string}> $unmatched
     */
    public function __construct(
        public readonly int $upserted,
        public readonly array $unmatched,
    ) {
    }

    public function unmatchedCount(): int
    {
        return count($this->unmatched);
    }
}
