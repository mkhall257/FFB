# Lineups are a weekly slot snapshot that locks at kickoff

A Team's Lineup is stored per week as one row per physical starting slot
(`lineups`), separate from the season-long `rosters` membership. Bench = rostered
Players with no lineup row that week. The whole Lineup locks at the week's first
NFL kickoff (`schedule.week_<n>_kickoff`); before lock a Manager edits freely,
after it the week is frozen. A Team with no Lineup set defaults by carrying
forward the previous week's; Week 1 auto-fills the best legal rostered Players
using the ADR-0007 fallback ranking, so no Team ever fields an empty Lineup.

Consequence: a Manager who forgets cannot fix an injured starter after kickoff —
we chose whole-lineup lock over per-player game-time locking for simplicity, and
carry-forward over an empty-Team penalty so a distracted kid still fields a
roughly-right team.
