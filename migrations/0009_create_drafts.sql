-- The Draft for a Season: the pre-Week-1 snake Draft held in the shared draft
-- room (see CONTEXT.md, ADR-0003, ADR-0007). Exactly one Draft per Season
-- (uq_drafts_season). Roster shape lives in league_settings (roster.*); this
-- row holds the Draft's own configuration and live state.
--
-- state       : Setup -> Ready -> Live -> Paused -> Complete lifecycle.
-- pick_seconds: per-pick timer length.
-- autopick_on_expiry: whether an expired pick timer auto-picks (1) or simply
--             leaves the Team on the clock (0) — a Commissioner toggle.
-- scheduled_at: optional display-only draft date/time; triggers nothing.
-- current_pick_no / current_deadline: the live pointer and the on-the-clock
--             deadline; both filled once the Draft is Live.

CREATE TABLE drafts (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id          INT UNSIGNED NOT NULL,
    season_id          INT UNSIGNED NOT NULL,
    state              ENUM('setup', 'ready', 'live', 'paused', 'complete') NOT NULL DEFAULT 'setup',
    pick_seconds       SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    autopick_on_expiry TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_at       DATETIME NULL,
    current_pick_no    INT UNSIGNED NULL,
    current_deadline   DATETIME NULL,
    started_at         DATETIME NULL,
    completed_at       DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drafts_season (league_id, season_id),
    CONSTRAINT fk_drafts_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_drafts_season FOREIGN KEY (season_id) REFERENCES seasons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
