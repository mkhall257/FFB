-- Each Team's Season Roster — the set of Players it controls. Materialized from
-- the Draft board when the Draft completes (see CONTEXT.md); the input to Wave 3
-- scoring/lineups and to Wave 4 transactions. A Player belongs to at most one
-- Team per Season (uq_roster_player). acquired records how the Player was
-- obtained; the Draft writes 'draft'.

CREATE TABLE rosters (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id  INT UNSIGNED NOT NULL,
    season_id  INT UNSIGNED NOT NULL,
    team_id    INT UNSIGNED NOT NULL,
    player_id  VARCHAR(32) NOT NULL,
    acquired   ENUM('draft', 'add', 'trade') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roster_player (season_id, player_id),
    KEY idx_roster_team (season_id, team_id),
    CONSTRAINT fk_rosters_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_rosters_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_rosters_team FOREIGN KEY (team_id) REFERENCES teams (id),
    CONSTRAINT fk_rosters_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
