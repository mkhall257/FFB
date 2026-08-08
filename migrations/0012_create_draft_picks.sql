-- The Draft's full pick board, generated when the Draft goes Live. One row per
-- slot in the snake (rounds x teams). player_id is NULL until the pick is made;
-- source records how it was made (a Manager pick, an Auto-pick, or a
-- Commissioner pick-on-behalf). A Player can be taken at most once per Draft
-- (uq_picks_player ignores the many NULLs).

CREATE TABLE draft_picks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    draft_id      INT UNSIGNED NOT NULL,
    overall_pick  SMALLINT UNSIGNED NOT NULL,
    round         SMALLINT UNSIGNED NOT NULL,
    pick_in_round SMALLINT UNSIGNED NOT NULL,
    team_id       INT UNSIGNED NOT NULL,
    player_id     VARCHAR(32) NULL,
    source        ENUM('manual', 'auto', 'commissioner') NULL,
    picked_at     DATETIME NULL,
    UNIQUE KEY uq_picks_overall (draft_id, overall_pick),
    UNIQUE KEY uq_picks_player (draft_id, player_id),
    KEY idx_picks_team (draft_id, team_id),
    CONSTRAINT fk_picks_draft FOREIGN KEY (draft_id) REFERENCES drafts (id),
    CONSTRAINT fk_picks_team FOREIGN KEY (team_id) REFERENCES teams (id),
    CONSTRAINT fk_picks_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
