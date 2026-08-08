-- Seed the single League, the current (2026) Season, and default league
-- settings: Half-PPR scoring and a standard roster. Stored as data so the
-- engine reads configuration rather than hardcoded constants.

INSERT INTO leagues (name) VALUES ('FFB League');

INSERT INTO seasons (league_id, year, is_current)
VALUES ((SELECT id FROM leagues ORDER BY id LIMIT 1), 2026, 1);

INSERT INTO league_settings (league_id, season_id, setting_key, setting_value)
SELECT l.id, s.id, k.setting_key, k.setting_value
FROM leagues l
JOIN seasons s ON s.league_id = l.id AND s.year = 2026
JOIN (
    -- Roster slots
    SELECT 'roster.qb'         AS setting_key, '1'   AS setting_value
    UNION ALL SELECT 'roster.rb', '2'
    UNION ALL SELECT 'roster.wr', '2'
    UNION ALL SELECT 'roster.te', '1'
    UNION ALL SELECT 'roster.flex', '1'
    UNION ALL SELECT 'roster.k', '1'
    UNION ALL SELECT 'roster.def', '1'
    UNION ALL SELECT 'roster.bench', '5'
    -- Scoring (Half-PPR): points per unit
    UNION ALL SELECT 'scoring.reception', '0.5'
    UNION ALL SELECT 'scoring.pass_yard', '0.04'
    UNION ALL SELECT 'scoring.pass_td', '4'
    UNION ALL SELECT 'scoring.pass_int', '-2'
    UNION ALL SELECT 'scoring.rush_yard', '0.1'
    UNION ALL SELECT 'scoring.rush_td', '6'
    UNION ALL SELECT 'scoring.rec_yard', '0.1'
    UNION ALL SELECT 'scoring.rec_td', '6'
    UNION ALL SELECT 'scoring.fumble_lost', '-2'
) k;
