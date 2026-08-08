-- A Team's weekly Lineup: one row per physical starting slot. Bench = rostered
-- Players with no lineup row that week. Written by carry-forward at schedule
-- generation and edited by the Manager until the week's kickoff lock. slot_index
-- separates duplicated slots (RB slot_index 0 and 1). player_id is nullable so an
-- intentionally-empty slot is representable.

CREATE TABLE lineups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id   INT UNSIGNED NOT NULL,
    season_id   INT UNSIGNED NOT NULL,
    week        TINYINT UNSIGNED NOT NULL,
    team_id     INT UNSIGNED NOT NULL,
    roster_slot ENUM('QB','RB','WR','TE','FLEX','K','DEF') NOT NULL,
    slot_index  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    player_id   VARCHAR(32) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lineup_slot (season_id, week, team_id, roster_slot, slot_index),
    KEY idx_lineup_team_week (season_id, week, team_id),
    CONSTRAINT fk_lineups_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_lineups_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_lineups_team FOREIGN KEY (team_id) REFERENCES teams (id),
    CONSTRAINT fk_lineups_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
