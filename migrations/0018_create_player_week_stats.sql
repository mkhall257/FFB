-- Raw weekly stat lines per Player, in the two states of ADR-0005: the 'sleeper'
-- row is the provisional Live line, the 'nflverse' row is the Official line that
-- supersedes it. Both are retained so a settle is auditable. Points are never
-- stored here — ScoringEngine computes them from stats x league_settings so a
-- scoring-config change re-scores correctly. stats is a JSON object of stat_name
-- => numeric value.

CREATE TABLE player_week_stats (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season_id  INT UNSIGNED NOT NULL,
    week       TINYINT UNSIGNED NOT NULL,
    player_id  VARCHAR(32) NOT NULL,
    source     ENUM('sleeper','nflverse') NOT NULL,
    stats      JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pws (season_id, week, player_id, source),
    KEY idx_pws_week (season_id, week),
    CONSTRAINT fk_pws_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_pws_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
