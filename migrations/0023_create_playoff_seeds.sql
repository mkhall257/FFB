-- Wave 5 (Playoffs): the frozen seed snapshot.
-- When the Commissioner creates the bracket, the top `playoffs.team_count` Teams
-- from the current Standings (ADR-0009 order) are frozen here, one row per
-- qualifying Team. The presence of rows for a Season *is* the "bracket exists"
-- flag. The bracket tree itself is NOT stored — it is derived from these seeds by
-- standard slotting. A later Official stat correction may re-rank the live
-- Standings display, but it never rewrites this snapshot.

CREATE TABLE playoff_seeds (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id  INT UNSIGNED NOT NULL,
    season_id  INT UNSIGNED NOT NULL,
    seed       TINYINT UNSIGNED NOT NULL,
    team_id    INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_playoff_seed (season_id, seed),
    UNIQUE KEY uq_playoff_team (season_id, team_id),
    CONSTRAINT fk_playoff_seeds_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_playoff_seeds_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_playoff_seeds_team FOREIGN KEY (team_id) REFERENCES teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
