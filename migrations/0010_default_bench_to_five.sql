-- Correct the default Bench size from 6 to 5, giving the agreed 14-round Draft
-- (9 starters + 5 bench). The Wave 1 seed shipped 6; this fixes already-deployed
-- databases. Only touches the row if it is still the old default, so a
-- Commissioner who has already chosen a Bench size is left untouched.

UPDATE league_settings
SET setting_value = '5'
WHERE setting_key = 'roster.bench' AND setting_value = '6';
