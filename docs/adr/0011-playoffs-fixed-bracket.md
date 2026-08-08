# Playoffs are a fixed single-elimination bracket over frozen seeds

At the end of the regular season the Commissioner creates the Playoff bracket.
The top `playoffs.team_count` Teams (2 ≤ n ≤ Team count, default 4) are taken
from the current Standings (ADR-0009 order: win% → points-for → team id) and
**frozen** into a `playoff_seeds` snapshot. The tree is drawn once with
**standard seed placement** (seed 1 maximally protected, can only meet seed 2 in
the final) and **never re-seeds** between rounds — a Manager can see their whole
path from day one. When the field isn't a power of two, the top seeds get
**first-round byes** (`byes = nextPow2(n) − n`). The bracket tree itself is not
stored: it is a pure function of (field size, frozen seeds), derived by
`Playoffs\Bracket`.

Chosen over re-seeding for legibility and fairness to kids — a stable tree that
never rearranges is easier to follow, and drawing it once avoids handing the top
seed a fresh easiest-opponent advantage every round. Freezing the seeds is a
consequence: a fixed tree can't be drawn without pinning the seeds, so a later
Official stat correction (ADR-0005) still fixes the Standings *display* but never
re-draws a bracket that is already underway.

## Playoff weeks reuse the regular-season machinery

A playoff round is an ordinary NFL week. Its pairings are written as ordinary
`matchups` rows tagged with a new nullable `round` column (NULL = regular season,
1 = first playoff round, …), at week `schedule.regular_season_weeks + round`. So
`MatchupScoringService`, the Live and Official crons, the weekly lineup lock, and
carry-forward (ADR-0008) all score and gate playoff games **unchanged** — nothing
about scoring knows or cares that two Teams are paired for the postseason. The
Commissioner opens each round explicitly (mirroring "Start a week"): creating the
bracket opens Round 1; **Advance** confirms the current round is fully `final`,
works out who advanced, and pairs the survivors into the next fixed-tree slots,
interleaving Round-1 byes into the semifinals. A Team on a bye has no `matchups`
row and sets no Lineup that week. No consolation bracket ships; non-qualifiers'
final Standings position is their finish, and the regular season is never
truncated (there is no clinching mechanic).

## A tie is broken on the field, then by seed

Single elimination must produce exactly one winner, so a tied playoff score is
broken by the **highest-scoring single starter**, then the next-highest, and so
on down each Team's sorted starter list; only if those vectors are identical does
the **higher seed** advance as a deterministic backstop. This decides the game on
what happened that week rather than on regular-season standing (which the fixed
bracket deliberately declines to reward again), while guaranteeing the bracket can
never stall. It reuses the exact scoring inputs — `MatchupScoringService` exposes
the per-starter breakdown it already computes.

## Commissioner controls, tightly gated

**Create** requires the final regular-season week settled and validates the field
size. **Advance** requires the current round fully final. **Correct last round**
undoes only the most recent advancement (delete the newest round, make the prior
round current) so corrected scores can settle and be re-advanced — non-cascading,
refused before any advancement. **Reset** tears the bracket down and clears the
frozen seeds, but only before any playoff game is `final`, so postseason history
is never rewritten. There is no manual seed editing — the ADR-0009 tiebreakers are
the source of truth. The Champion is **derived** from the settled final's winner;
nothing extra is stored.
