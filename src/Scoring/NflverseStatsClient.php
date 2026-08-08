<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\Players\RemoteFile;

/**
 * Downloads nflverse's weekly official player stats (the offense CSV release) and
 * normalizes each row to the scoring stat names, keyed by the gsis (nflverse) id.
 *
 * Scope (verified against the live 2024 release): this file covers offensive
 * production only. Kicker and team-Defense scoring are NOT in it — kicking lives
 * in a separate release file and nflverse has no team points-allowed figure at
 * all (its defense file is individual defenders). K and DEF therefore keep their
 * live Sleeper values through settlement rather than being overwritten with
 * zeros; see ADR-0009. Fumbles lost are summed across the sack/rushing/receiving
 * columns.
 */
final class NflverseStatsClient
{
    /**
     * @return array<string, array<string,float>> gsis_id => normalized stat line
     */
    public function fetchWeek(int $season, int $week): array
    {
        $url = "https://github.com/nflverse/nflverse-data/releases/download/player_stats/player_stats_{$season}.csv";

        return $this->rowsForWeek(RemoteFile::get($url), $week);
    }

    /**
     * Parse a player-stats CSV and return the normalized lines for one week,
     * keyed by gsis id. Public so it can be tested against fixture CSV without
     * touching the network.
     *
     * @return array<string, array<string,float>> gsis_id => normalized stat line
     */
    public function rowsForWeek(string $csv, int $week): array
    {
        $out = [];
        foreach ($this->parseCsv($csv) as $row) {
            if ((int) ($row['week'] ?? 0) !== $week) {
                continue;
            }
            $gsis = (string) ($row['player_id'] ?? '');
            if ($gsis === '') {
                continue;
            }
            $out[$gsis] = $this->normalize($row);
        }

        return $out;
    }

    /**
     * @return list<array<string,string>> rows keyed by header column
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === []) {
            return [];
        }
        $header = str_getcsv((string) array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line);
            $rows[] = array_combine($header, array_pad($values, count($header), ''));
        }

        return $rows;
    }

    /**
     * Map nflverse offense column names to our scoring stat names.
     *
     * @param array<string,string> $row
     * @return array<string,float>
     */
    private function normalize(array $row): array
    {
        $num = static fn (string $col): float => isset($row[$col]) && $row[$col] !== '' ? (float) $row[$col] : 0.0;

        $map = [
            'receptions' => 'reception', 'passing_yards' => 'pass_yard',
            'passing_tds' => 'pass_td', 'interceptions' => 'pass_int',
            'rushing_yards' => 'rush_yard', 'rushing_tds' => 'rush_td',
            'receiving_yards' => 'rec_yard', 'receiving_tds' => 'rec_td',
        ];
        $line = [];
        foreach ($map as $from => $to) {
            if (isset($row[$from]) && $row[$from] !== '') {
                $line[$to] = (float) $row[$from];
            }
        }

        // Fumbles lost are split across three columns in nflverse; sum them.
        $fumbles = $num('sack_fumbles_lost') + $num('rushing_fumbles_lost') + $num('receiving_fumbles_lost');
        if ($fumbles > 0.0) {
            $line['fumble_lost'] = $fumbles;
        }

        return $line;
    }
}
