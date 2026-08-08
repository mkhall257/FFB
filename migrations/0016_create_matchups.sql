-- The regular-season Schedule: one row per weekly head-to-head Matchup.
-- Generated at Draft completion from the final Team set (round-robin, cycled to
-- fill schedule.regular_season_weeks). A Team with no row in a week has a bye.
-- home_score/away_score are denormalized caches recomputed from lineups x stats;
-- status tracks the ADR-0005 lifecycle (scheduled -> live -> final on settle).

CREATE TABLE matchups (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id     INT UNSIGNED NOT NULL,
    season_id     INT UNSIGNED NOT NULL,
    week          TINYINT UNSIGNED NOT NULL,
    home_team_id  INT UNSIGNED NOT NULL,
    away_team_id  INT UNSIGNED NOT NULL,
    home_score    DECIMAL(6,2) NULL,
    away_score    DECIMAL(6,2) NULL,
    status        ENUM('scheduled','live','final') NOT NULL DEFAULT 'scheduled',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matchup_home (season_id, week, home_team_id),
    KEY idx_matchup_week (season_id, week),
    CONSTRAINT fk_matchups_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_matchups_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_matchups_home FOREIGN KEY (home_team_id) REFERENCES teams (id),
    CONSTRAINT fk_matchups_away FOREIGN KEY (away_team_id) REFERENCES teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
