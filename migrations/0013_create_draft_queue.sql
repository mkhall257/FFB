-- Each Manager's personal ranked Queue for a Draft (see CONTEXT.md). Private to
-- the Team: drives that Team's Auto-pick and lets the Manager pick quickly.
-- rank_position is a dense 1..N ordering, rewritten on every change.

CREATE TABLE draft_queue (
    draft_id      INT UNSIGNED NOT NULL,
    team_id       INT UNSIGNED NOT NULL,
    player_id     VARCHAR(32) NOT NULL,
    rank_position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (draft_id, team_id, player_id),
    UNIQUE KEY uq_queue_rank (draft_id, team_id, rank_position),
    CONSTRAINT fk_queue_draft FOREIGN KEY (draft_id) REFERENCES drafts (id),
    CONSTRAINT fk_queue_team FOREIGN KEY (team_id) REFERENCES teams (id),
    CONSTRAINT fk_queue_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
