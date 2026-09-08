-- A lightweight "who is in the draft room right now" heartbeat (see ADR-0003:
-- the room is poll-driven, so each room load records the viewer as present and
-- refreshes last_seen). One row per user per draft. Managers use it to see which
-- teams are actually connected before and while a team is on the clock.
--
-- Deliberately no foreign keys: the Commissioner may hold a user id that manages
-- no team (and team_id is then NULL), and presence is disposable heartbeat data,
-- not a record worth cascading. The (draft_id, last_seen) key serves the
-- "connected in the last N seconds" lookup.

CREATE TABLE draft_presence (
    draft_id  INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    team_id   INT UNSIGNED NULL,
    last_seen DATETIME NOT NULL,
    PRIMARY KEY (draft_id, user_id),
    KEY idx_presence_seen (draft_id, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
