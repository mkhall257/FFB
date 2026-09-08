<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * Consensus team-defense rankings from FantasyPros, via the DynastyProcess
 * `fp_latest_weekly.csv` mirror. Sleeper publishes no rank for team defenses, so
 * this is the authoritative order for the draft board's DEF slot (ADR-0004).
 *
 * Returns a map of NFL team code => 1-based rank (1 = the top-ranked defense).
 * FantasyPros' one code that differs from Sleeper's — JAC — is normalized to
 * Sleeper's JAX so the map joins straight onto the players table.
 *
 * Parsing is a pure static method so it can be tested against a fixture without
 * the network.
 */
final class FantasyProsDefenseRankings
{
    /** FantasyPros team code => Sleeper team code, where they differ. */
    private const TEAM_ALIASES = ['JAC' => 'JAX'];

    public function __construct(
        private readonly string $url = 'https://github.com/dynastyprocess/data/raw/master/files/fp_latest_weekly.csv',
    ) {
    }

    /**
     * @return array<string,int> team code => rank
     */
    public function fetch(): array
    {
        return self::parse(RemoteFile::get($this->url));
    }

    /**
     * @return array<string,int> team code => rank
     */
    public static function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines));
        $index = array_flip($header);
        if (!isset($index['pos'], $index['team'], $index['rank'])) {
            throw new \RuntimeException('FantasyPros feed is missing pos/team/rank columns.');
        }

        $posCol = $index['pos'];
        $teamCol = $index['team'];
        $rankCol = $index['rank'];

        $map = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $row = str_getcsv($line);
            if (strtoupper(trim((string) ($row[$posCol] ?? ''))) !== 'DST') {
                continue;
            }

            $team = strtoupper(trim((string) ($row[$teamCol] ?? '')));
            $rank = trim((string) ($row[$rankCol] ?? ''));
            if ($team === '' || !ctype_digit($rank)) {
                continue;
            }

            $team = self::TEAM_ALIASES[$team] ?? $team;
            $map[$team] = (int) $rank;
        }

        return $map;
    }
}
