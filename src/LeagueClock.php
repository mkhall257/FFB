<?php

declare(strict_types=1);

namespace FFB;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts a datetime-local form value (e.g. "2026-12-18T20:20"), interpreted in
 * the league's local timezone, into an ISO 8601 string with offset — the format
 * the weekly lineup-lock (schedule.week_<n>_kickoff) is stored in. Mirrors the
 * conversion SeasonController uses for "Start a week".
 */
final class LeagueClock
{
    public const LEAGUE_TZ = 'America/New_York';

    /** ISO 8601 with offset, or null when the input is empty/invalid. */
    public static function toIso(string $local): ?string
    {
        if ($local === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($local, new DateTimeZone(self::LEAGUE_TZ));
        } catch (\Exception) {
            return null;
        }

        return $dt->format('c');
    }
}
