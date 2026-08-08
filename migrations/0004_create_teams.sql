-- Teams: a Manager's franchise within the League, for a given Season.
-- user_id is the managing Manager; nullable so a Team can exist before its
-- login is assigned.

CREATE TABLE teams (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id  INT UNSIGNED NOT NULL,
    season_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    name       VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teams_league_season_name (league_id, season_id, name),
    CONSTRAINT fk_teams_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_teams_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_teams_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
