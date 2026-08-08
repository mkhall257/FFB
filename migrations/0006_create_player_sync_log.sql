-- One row per Player-sync run, so the Commissioner can confirm the import is
-- running on the server and see when it last succeeded and how many Players
-- were Unmatched.

CREATE TABLE player_sync_log (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at       DATETIME NOT NULL,
    finished_at      DATETIME NULL,
    players_upserted INT UNSIGNED NOT NULL DEFAULT 0,
    unmatched_count  INT UNSIGNED NOT NULL DEFAULT 0,
    outcome          ENUM('running', 'success', 'error') NOT NULL DEFAULT 'running',
    message          TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
