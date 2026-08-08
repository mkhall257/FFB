# Post-Draft changes are an append-only Transaction ledger over live rosters

Every post-Draft Roster change — an Add/Drop, a Trade, or a Commissioner manual
edit — is recorded as a first-class Transaction in two tables: a `transactions`
header (one row per Transaction) and `transaction_items` lines (one row per
Player that moved, with `from_team_id`, `to_team_id`, and the `prior_acquired`
value the Player held before the move). The `rosters` table stays the live
membership these Transactions drive; the ledger is the durable audit record.
`rosters.acquired` stays a three-value tag (draft/add/trade) describing the
*nature* of the move — a `commish_edit` maps to add or trade and records its
who/why only in the ledger `type`.

A header carries two orthogonal fields: `status` (pending → applied → reversed)
and, for Trades only, `proposal_outcome` (proposed → accepted / rejected /
cancelled / expired). An Add/Drop and a commish_edit are born `applied`; a Trade
is born `pending` and becomes `applied` only when the target accepts. A Trade's
`accepted_by_team` is the Team the offer is made *to* (the only Team that may
accept or reject); `proposed_by_team` is the offering Team.

Consequences:

- **Reversal is implementable and clean.** The Commissioner can reverse any
  applied Transaction by replaying its items in reverse (from/to swapped,
  `prior_acquired` restored). It is conflict-checked and non-cascading: if any
  Player it touched has since moved, the reversal is refused rather than yanking
  a Player off an innocent Team. Manager self-undo does not exist.
- **Add/Drop** enforces the roster cap (starters + bench); a drop is mandatory
  only when the Roster is full. It is first-come-first-served via
  `uq_roster_player` — the losing Transaction rolls back whole. It is allowed
  even mid-locked-week: the locked Lineup snapshot keeps scoring (ADR-0008)
  while membership changes; an un-locked vacated slot is cleared.
- **Trades** move nothing until accepted, so a Player may sit in several pending
  proposals; accept re-validates ownership and both-side cap atomically, and an
  overflow is blocked (no drop-inside-trade). Proposals expire after 48h,
  computed lazily (no cron).
- **Timing.** Transactions open once the Draft state is `complete` (not gated on
  Week 1). A Commissioner trade deadline (`schedule.trade_deadline_week`, blank
  = none) closes trading past that week; Add/Drop is never affected. No
  playoff-driven freeze this wave (Playoffs deferred).
- The whole feature is tested at the single HTTP seam
  (`Kernel::router($pdo)->dispatch(...)`) against a throwaway MySQL database,
  as with every prior wave.
