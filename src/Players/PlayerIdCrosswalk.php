<?php

declare(strict_types=1);

namespace FFB\Players;

/**
 * The Sleeper→nflverse player-id crosswalk (see ADR-0006). Fetches and parses
 * the DynastyProcess db_playerids.csv into a map of sleeper_id => gsis_id.
 *
 * Parsing is a pure static method so it can be tested against a fixture without
 * the network.
 */
final class PlayerIdCrosswalk
{
    public function __construct(
        private readonly string $url = 'https://github.com/dynastyprocess/data/raw/master/files/db_playerids.csv',
    ) {
    }

    /**
     * @return array<string,string> sleeper_id => gsis_id
     */
    public function fetch(): array
    {
        return self::parse(RemoteFile::get($this->url));
    }

    /**
     * @return array<string,string> sleeper_id => gsis_id
     */
    public static function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines));
        $index = array_flip($header);
        if (!isset($index['sleeper_id'], $index['gsis_id'])) {
            throw new \RuntimeException('Crosswalk is missing sleeper_id/gsis_id columns.');
        }

        $sleeperCol = $index['sleeper_id'];
        $gsisCol = $index['gsis_id'];

        $map = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $row = str_getcsv($line);
            $sleeperId = trim((string) ($row[$sleeperCol] ?? ''));
            $gsisId = trim((string) ($row[$gsisCol] ?? ''));
            if ($sleeperId !== '' && $gsisId !== '') {
                $map[$sleeperId] = $gsisId;
            }
        }

        return $map;
    }
}
