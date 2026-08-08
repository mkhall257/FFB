-- Sleeper's search_rank for each Player: a global ordering (lower = more
-- prominent) carried in the Sleeper /players/nfl payload. It is the global
-- fallback ranking for Draft Auto-pick when a Manager's Queue is empty or
-- exhausted (see ADR-0007). Nullable: not every Player carries a rank.

ALTER TABLE players
    ADD COLUMN search_rank INT NULL AFTER status,
    ADD KEY idx_players_search_rank (search_rank);
