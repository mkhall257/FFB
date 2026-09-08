<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * Each NFL team's bye week for a season, derived from the real schedule via the
 * nflverse `nfldata` games feed. A team's bye is the one regular-season week in
 * which it has no game; the draft room shows it so a Manager doesn't unknowingly
 * stack players who are all off the same week (ADR-0004).
 *
 * Returns a map of NFL team code => bye week (1-based). nflverse's one code that
 * differs from Sleeper's — LA for the Rams — is normalized to Sleeper's LAR so
 * the map joins straight onto the players table.
 *
 * Parsing is a pure static method so it can be tested against a fixture without
 * the network.
 */
final class NflByeWeeks
{
    /** nflverse team code => Sleeper team code, where they differ. */
    private const TEAM_ALIASES = ['LA' => 'LAR'];

    /** The most regular-season weeks we'll consider when hunting for the gap. */
    private const MAX_REGULAR_WEEK = 18;

    public function __construct(
        private readonly int $season,
        private readonly string $url = 'https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv',
    ) {
    }

    /**
     * @return array<string,int> team code => bye week
     */
    public function fetch(): array
    {
        return self::parse(RemoteFile::get($this->url), $this->season);
    }

    /**
     * Derive each team's bye from the season's regular-season games: the single
     * week in 1..N in which the team does not appear. A team that is missing zero
     * or more than one week (an incomplete or malformed schedule) is left out
     * rather than guessed, so a half-published schedule never reports a wrong bye.
     *
     * @return array<string,int> team code => bye week
     */
    public static function parse(string $csv, int $season): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines));
        $index = array_flip($header);
        foreach (['season', 'game_type', 'week', 'home_team', 'away_team'] as $column) {
            if (!isset($index[$column])) {
                throw new \RuntimeException("nflverse games feed is missing the {$column} column.");
            }
        }

        // team code => set of weeks it plays in, and the largest week seen, so we
        // scan exactly the weeks this season actually has.
        $played = [];
        $maxWeek = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $row = str_getcsv($line);
            if ((int) ($row[$index['season']] ?? 0) !== $season) {
                continue;
            }
            if (($row[$index['game_type']] ?? '') !== 'REG') {
                continue;
            }
            $week = (int) ($row[$index['week']] ?? 0);
            if ($week < 1 || $week > self::MAX_REGULAR_WEEK) {
                continue;
            }
            $maxWeek = max($maxWeek, $week);

            foreach ([$row[$index['home_team']] ?? '', $row[$index['away_team']] ?? ''] as $team) {
                $team = self::normalize(trim((string) $team));
                if ($team !== '') {
                    $played[$team][$week] = true;
                }
            }
        }

        if ($maxWeek === 0) {
            return [];
        }

        $byes = [];
        foreach ($played as $team => $weeks) {
            $missing = [];
            for ($week = 1; $week <= $maxWeek; $week++) {
                if (!isset($weeks[$week])) {
                    $missing[] = $week;
                }
            }
            if (count($missing) === 1) {
                $byes[$team] = $missing[0];
            }
        }

        return $byes;
    }

    private static function normalize(string $team): string
    {
        return self::TEAM_ALIASES[$team] ?? $team;
    }
}
