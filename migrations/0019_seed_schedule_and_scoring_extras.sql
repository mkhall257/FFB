-- Extend league_settings for Wave 3: regular-season length (the round-robin
-- cycles to fill it) and simplified Kicker/Defense scoring (flat FG/XP, flat DEF
-- events, coarse points-allowed tiers). Data-driven so the Commissioner can edit
-- later without a schema change.

INSERT INTO league_settings (league_id, season_id, setting_key, setting_value)
SELECT l.id, s.id, k.setting_key, k.setting_value
FROM leagues l
JOIN seasons s ON s.league_id = l.id AND s.is_current = 1
JOIN (
    SELECT 'schedule.regular_season_weeks' AS setting_key, '14' AS setting_value
    UNION ALL SELECT 'scoring.fg_made', '3'
    UNION ALL SELECT 'scoring.xp_made', '1'
    UNION ALL SELECT 'scoring.def_sack', '1'
    UNION ALL SELECT 'scoring.def_int', '2'
    UNION ALL SELECT 'scoring.def_fumble_rec', '2'
    UNION ALL SELECT 'scoring.def_td', '6'
    UNION ALL SELECT 'scoring.def_safety', '2'
    UNION ALL SELECT 'scoring.def_pa_0', '10'
    UNION ALL SELECT 'scoring.def_pa_1_6', '7'
    UNION ALL SELECT 'scoring.def_pa_7_13', '4'
    UNION ALL SELECT 'scoring.def_pa_14_20', '1'
    UNION ALL SELECT 'scoring.def_pa_21_27', '0'
    UNION ALL SELECT 'scoring.def_pa_28_34', '-1'
    UNION ALL SELECT 'scoring.def_pa_35', '-4'
) k;
