-- League configuration (scoring, roster shape) stored as data, not hardcoded.
-- Key/value rows keep the model open so a settings UI can be added later
-- without a schema change. Seeded with defaults in a later migration.

CREATE TABLE league_settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id     INT UNSIGNED NOT NULL,
    season_id     INT UNSIGNED NOT NULL,
    setting_key   VARCHAR(64) NOT NULL,
    setting_value TEXT NOT NULL,
    UNIQUE KEY uq_settings (league_id, season_id, setting_key),
    CONSTRAINT fk_settings_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_settings_season FOREIGN KEY (season_id) REFERENCES seasons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
