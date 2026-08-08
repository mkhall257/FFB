-- The Draft's first-round order: each Team's slot (position 1..N). The snake
-- reversal each round is derived from this, not stored. Set by the Commissioner
-- (randomize + manual reorder) and locked when the Draft is finalized (Ready).
--
-- auto_draft: per-Team Auto-draft mode toggled during a live Draft (a Team whose
-- Manager has left keeps picking instantly). Lives here since this is the
-- Draft's per-Team row.

CREATE TABLE draft_order (
    draft_id   INT UNSIGNED NOT NULL,
    position   SMALLINT UNSIGNED NOT NULL,
    team_id    INT UNSIGNED NOT NULL,
    auto_draft TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (draft_id, position),
    UNIQUE KEY uq_draft_order_team (draft_id, team_id),
    CONSTRAINT fk_draft_order_draft FOREIGN KEY (draft_id) REFERENCES drafts (id),
    CONSTRAINT fk_draft_order_team FOREIGN KEY (team_id) REFERENCES teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
