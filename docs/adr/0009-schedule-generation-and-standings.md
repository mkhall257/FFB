# The Schedule is a cycled round-robin; Standings rank by record then points

At Draft completion the Schedule is generated from the final Team set as a
round-robin (circle method), cycled to fill `schedule.regular_season_weeks`
(default 14); an odd Team count gives byes (absence of a Matchup row). Matchup
rows cache `home_score`/`away_score` and a `status` (`scheduled`→`live`→`final`),
recomputed by the Live cron and settled by the Official cron.

Standings rank Teams by win% (a tie counts half a win), then total points scored,
then team id as a deterministic final tiebreaker — no head-to-head, chosen for
explainability to kids. Only `final` Matchups count, so an Official stat
correction that flips a result (ADR-0005) flows through to the seeding.
Playoffs (the single-elimination bracket) are deferred to a later wave; Wave 3
ends at the seed-ordered Standings.

## Kicker and Defense do not settle to nflverse

Official settlement (ADR-0005) supersedes only **offensive** production. Verified
against the live nflverse 2024 release: the weekly `player_stats` file is offense
only; kicking is a separate file, and nflverse has no team points-allowed figure
at all (its defense file is individual defenders). So the Official (nflverse)
source cannot compute our team-DEF or kicker scores. The settlement client writes
nflverse rows only for players it finds in the offense file, which means K and
DEF have no Official row and `resolvedForWeek` keeps their **Live Sleeper** value
through settlement — Sleeper does provide team-DEF points-allowed and kicker
FG/XP, and that is authoritative enough for this league. We accept that K/DEF are
never "officially" corrected rather than rebuild team-defense scoring from
nflverse team-game aggregates. (Fumbles lost, which nflverse splits across the
sack/rushing/receiving columns, are summed so offensive settlement is correct.)
