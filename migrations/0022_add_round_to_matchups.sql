-- Wave 5 (Playoffs): tag a Matchup with the playoff round it belongs to.
-- NULL = regular-season Matchup (the round-robin); 1 = first playoff round,
-- 2 = second, and so on. Playoff rounds reuse the ordinary Matchup + week
-- machinery (ADR-0008/0009 scoring, lineup lock, settlement) unchanged — this
-- marker is the only thing that distinguishes a postseason game from a
-- regular-season one. Playoff weeks are numbered schedule.regular_season_weeks + round.

ALTER TABLE matchups
    ADD COLUMN round TINYINT UNSIGNED NULL DEFAULT NULL AFTER week;
