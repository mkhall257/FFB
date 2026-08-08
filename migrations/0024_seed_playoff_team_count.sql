-- Wave 5 (Playoffs): default the playoff field size.
-- playoffs.team_count is how many Teams qualify for the bracket. Commissioner-
-- configured on /admin/season; any integer 2 <= n <= Team count. Read and frozen
-- into playoff_seeds at bracket creation. Data-driven like the other league
-- settings so it can change without a schema change. Default 4.

INSERT INTO league_settings (league_id, season_id, setting_key, setting_value)
SELECT l.id, s.id, 'playoffs.team_count', '4'
FROM leagues l
JOIN seasons s ON s.league_id = l.id AND s.is_current = 1;
