-- The canonical NFL Player universe (see ADR-0004).
-- Keyed on Sleeper's player_id (Sleeper is the spine). nflverse_id is the
-- cross-reference used later for Official Scores; a Player with a NULL
-- nflverse_id is an Unmatched Player.
--
-- This is shared reference data, not League/Season-scoped: every League draws
-- from the same NFL Player universe.

CREATE TABLE players (
    sleeper_id  VARCHAR(32) NOT NULL PRIMARY KEY,
    nflverse_id VARCHAR(32) NULL,
    full_name   VARCHAR(120) NOT NULL,
    position    VARCHAR(8) NULL,
    nfl_team    VARCHAR(8) NULL,
    status      VARCHAR(32) NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_players_nflverse (nflverse_id),
    KEY idx_players_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
