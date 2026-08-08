# The Sleeper→nflverse link is sourced from the DynastyProcess player-id crosswalk

The `nflverse_id` (gsis_id) on each Player is resolved by looking up the Player's `sleeper_id` in the DynastyProcess `db_playerids.csv` crosswalk (an nflverse-ecosystem file that maps `sleeper_id ↔ gsis_id` and many other id systems). Sleeper's own `gsis_id` field is used only as a fallback.

**Why:** measured against live data (2026 preseason), Sleeper's `gsis_id` field links only ~17% of active skill players, while the crosswalk links ~83%; the ~17% that still don't link are genuine UDFA/practice-squad players with no NFL snaps (and thus no gsis id yet) — which is precisely the short, actionable set the Commissioner's Unmatched Players review should surface. Matching by name was rejected up front (ADR-0004) as unreliable.

**Consequences:** FFB depends on a third external source beyond Sleeper and nflverse. It is fetched at player-sync time and cached in the `players` table, so a crosswalk outage degrades gracefully — the Sleeper catalog still imports and players simply remain Unmatched until the next successful sync. The crosswalk is refreshed on the same cron cadence as the player sync.
