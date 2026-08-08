# Sleeper is the canonical Player spine; nflverse provides Official Scores

The `players` table is keyed on Sleeper's `player_id` and populated from Sleeper's players feed — Sleeper is the canonical Player universe used for drafting, rostering, and Live Scores. Each Player also carries an `nflverse_id`, mapped in via the cross-reference IDs Sleeper publishes, so official weekly nflverse stats can compute the Official Score for the same rostered Player.

Sleeper is chosen as the spine (rather than nflverse) because its feed is a true draftable-player catalog — current team, position, injury status, rookies before their first game — which nflverse's stats-centric data is not. Name-matching across sources is unreliable (duplicates, Jr./Sr., spelling), so linkage is by ID. A cron reconciliation step reports any Unmatched Player to the Commissioner so scoring gaps surface before they cause a wrong result rather than after.
