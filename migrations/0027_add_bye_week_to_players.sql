-- Each Player's NFL bye week for the current season, so a Manager can see it
-- while drafting and avoid stacking players who are all off in the same week
-- (see ADR-0004). Like nfl_team and status, this is a current-state field on the
-- shared Player universe: it is refreshed every player sync from the real NFL
-- schedule (nflverse), and is the same for every League. Nullable — a Player
-- whose team's bye can't be determined (or who has no team) simply has none.

ALTER TABLE players
    ADD COLUMN bye_week TINYINT UNSIGNED NULL AFTER nfl_team;
