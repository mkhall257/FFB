<?php

declare(strict_types=1);

namespace FFB\Players;

use FFB\PlayerRepository;

/**
 * Imports the Sleeper players feed into the canonical `players` table and links
 * each Player to its nflverse (gsis) id via the crosswalk (ADR-0004, ADR-0006).
 *
 * The catalog is limited to the rosterable universe: active skill players
 * (QB/RB/WR/TE/K) and team defenses (DEF). A skill Player who is on a team but
 * has no nflverse link is reported as Unmatched.
 *
 * Pure orchestration over injected data — the caller supplies the already-
 * fetched Sleeper payload and crosswalk map, so this is fully testable against
 * fixtures.
 */
final class PlayerImporter
{
    private const SKILL_POSITIONS = ['QB', 'RB', 'WR', 'TE', 'K'];

    public function __construct(private readonly PlayerRepository $players)
    {
    }

    /**
     * @param array<string,array<string,mixed>> $sleeperPlayers Sleeper id => attributes
     * @param array<string,string> $sleeperToGsis sleeper_id => gsis_id
     */
    public function import(array $sleeperPlayers, array $sleeperToGsis): ImportResult
    {
        $upserted = 0;
        $unmatched = [];

        foreach ($sleeperPlayers as $key => $attributes) {
            $position = $this->str($attributes['position'] ?? null);
            $isDefense = $position === 'DEF';
            $isSkill = $position !== null && in_array($position, self::SKILL_POSITIONS, true);

            if (!$isDefense && !$isSkill) {
                continue;
            }
            // Skill players must be currently active; retired players (whose
            // Sleeper status is unreliable) are excluded by the active flag.
            if ($isSkill && ($attributes['active'] ?? null) !== true) {
                continue;
            }

            $sleeperId = $this->str($attributes['player_id'] ?? $key);
            if ($sleeperId === null) {
                continue;
            }

            $team = $this->str($attributes['team'] ?? null);
            if ($isDefense && $team === null) {
                continue;
            }

            $status = $this->str($attributes['status'] ?? null);
            $searchRank = $this->intOrNull($attributes['search_rank'] ?? null);
            $fullName = $this->fullName($attributes, $team);
            $nflverseId = $this->resolveNflverseId($sleeperId, $attributes, $sleeperToGsis);

            $this->players->upsert($sleeperId, $nflverseId, $fullName, $position, $team, $status, $searchRank);
            $upserted++;

            if ($isSkill && $team !== null && $nflverseId === null) {
                $unmatched[] = [
                    'sleeper_id' => $sleeperId,
                    'full_name' => $fullName,
                    'position' => (string) $position,
                    'nfl_team' => $team,
                ];
            }
        }

        return new ImportResult($upserted, $unmatched);
    }

    /**
     * Prefer the crosswalk's gsis id; fall back to Sleeper's own gsis_id field
     * (which is trimmed — it can carry a leading space in the feed).
     *
     * @param array<string,mixed> $attributes
     * @param array<string,string> $sleeperToGsis
     */
    private function resolveNflverseId(string $sleeperId, array $attributes, array $sleeperToGsis): ?string
    {
        $fromCrosswalk = trim((string) ($sleeperToGsis[$sleeperId] ?? ''));
        if ($fromCrosswalk !== '') {
            return $fromCrosswalk;
        }

        $fromSleeper = trim((string) ($attributes['gsis_id'] ?? ''));

        return $fromSleeper !== '' ? $fromSleeper : null;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function fullName(array $attributes, ?string $team): string
    {
        $full = trim((string) ($attributes['full_name'] ?? ''));
        if ($full !== '') {
            return $full;
        }

        $composed = trim(
            trim((string) ($attributes['first_name'] ?? ''))
            . ' '
            . trim((string) ($attributes['last_name'] ?? ''))
        );
        if ($composed !== '') {
            return $composed;
        }

        // Team defenses arrive with no name; give them a readable one.
        return $team !== null ? "{$team} Defense" : 'Unknown Player';
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
