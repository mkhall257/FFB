<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Reads and writes the key/value league_settings for a League/Season — the
 * roster shape (roster.*) and scoring (scoring.*) configuration. Kept as data
 * so the Commissioner can change it without a schema change.
 */
final class LeagueSettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * All settings for the League/Season as a key => value map.
     *
     * @return array<string,string>
     */
    public function all(int $leagueId, int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT setting_key, setting_value FROM league_settings WHERE league_id = ? AND season_id = ?'
        );
        $stmt->execute([$leagueId, $seasonId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $out;
    }

    /**
     * Upsert a batch of settings.
     *
     * @param array<string,string> $settings key => value
     */
    public function setMany(int $leagueId, int $seasonId, array $settings): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO league_settings (league_id, season_id, setting_key, setting_value)'
            . ' VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([$leagueId, $seasonId, $key, $value]);
        }
    }
}
