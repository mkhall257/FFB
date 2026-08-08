<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\Players\RemoteFile;

/**
 * Downloads nflverse's weekly official player stats (a CSV release) and
 * normalizes each row to the scoring stat names, keyed by the gsis (nflverse) id.
 *
 * NOTE: the release URL and CSV column names in {@see normalize} must be verified
 * against the live nflverse release during implementation. The $map and the id
 * column are the single places to correct; the importer and scorer are agnostic.
 */
final class NflverseStatsClient
{
    /**
     * @return array<string, array<string,float>> gsis_id => normalized stat line
     */
    public function fetchWeek(int $season, int $week): array
    {
        $url = "https://github.com/nflverse/nflverse-data/releases/download/player_stats/player_stats_{$season}.csv";
        $rows = $this->parseCsv(RemoteFile::get($url));

        $out = [];
        foreach ($rows as $row) {
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
        $header = str_getcsv(array_shift($lines));
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
     * Map nflverse column names to our scoring stat names.
     *
     * @param array<string,string> $row
     * @return array<string,float>
     */
    private function normalize(array $row): array
    {
        $map = [
            'receptions' => 'reception', 'passing_yards' => 'pass_yard',
            'passing_tds' => 'pass_td', 'interceptions' => 'pass_int',
            'rushing_yards' => 'rush_yard', 'rushing_tds' => 'rush_td',
            'receiving_yards' => 'rec_yard', 'receiving_tds' => 'rec_td',
            'rushing_fumbles_lost' => 'fumble_lost',
        ];
        $line = [];
        foreach ($map as $from => $to) {
            if (isset($row[$from]) && $row[$from] !== '') {
                $line[$to] = (float) $row[$from];
            }
        }

        return $line;
    }
}
