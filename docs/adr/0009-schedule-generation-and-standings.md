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
