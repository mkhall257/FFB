<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\Players\RemoteFile;

/**
 * Fetches Sleeper's weekly player stats (the Live feed) and normalizes each
 * player's raw fields to the scoring stat names. Sleeper keys by sleeper_id.
 *
 * NOTE: the raw Sleeper stat field names in {@see normalize} must be verified
 * against a live payload — Sleeper documents them only informally. The $map is
 * the single place to correct them; the importer and scorer are agnostic.
 */
final class SleeperStatsClient
{
    /**
     * @return array<string, array<string,float>> sleeper_id => normalized stat line
     */
    public function fetchWeek(int $season, int $week): array
    {
        $url = "https://api.sleeper.app/v1/stats/nfl/regular/{$season}/{$week}";
        $raw = json_decode(RemoteFile::get($url), true) ?: [];

        $out = [];
        foreach ($raw as $sleeperId => $fields) {
            $out[(string) $sleeperId] = $this->normalize((array) $fields);
        }

        return $out;
    }

    /**
     * Map Sleeper's stat field names to our scoring stat names. Only the fields
     * the scoring config consumes are kept.
     *
     * @param array<string,mixed> $raw
     * @return array<string,float>
     */
    private function normalize(array $raw): array
    {
        $map = [
            'rec' => 'reception', 'pass_yd' => 'pass_yard', 'pass_td' => 'pass_td',
            'pass_int' => 'pass_int', 'rush_yd' => 'rush_yard', 'rush_td' => 'rush_td',
            'rec_yd' => 'rec_yard', 'rec_td' => 'rec_td', 'fum_lost' => 'fumble_lost',
            'fgm' => 'fg_made', 'xpm' => 'xp_made',
            'sack' => 'def_sack', 'int' => 'def_int', 'fum_rec' => 'def_fumble_rec',
            'def_td' => 'def_td', 'safe' => 'def_safety', 'pts_allow' => 'def_points_allowed',
        ];
        $line = [];
        foreach ($map as $from => $to) {
            if (isset($raw[$from])) {
                $line[$to] = (float) $raw[$from];
            }
        }

        return $line;
    }
}
