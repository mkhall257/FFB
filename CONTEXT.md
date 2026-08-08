# FFB

FFB is a self-contained fantasy football platform that runs a single private league for a group of kids. It aims to feel like a real fantasy service — draft, live scoring, transactions, playoffs — while staying deliberately simple to build and to use.

## Language

### People & roles

**Commissioner**:
The single administrator who runs the league — creates accounts, sets league configuration, and can reverse any action. A backup Commissioner (a second admin account with the same powers) may exist.
_Avoid_: Admin, owner, moderator

**Manager**:
A person who controls one Team — drafts players, sets a Lineup, and makes transactions. In this league, Managers are kids.
_Avoid_: Player (a Player is an NFL athlete), user, owner, coach

**Team**:
A Manager's franchise within the League. Holds a Roster and accumulates a win-loss record across Matchups. 4–10 Teams compete in the League.
_Avoid_: Franchise, club

### League structure

**League**:
The single competition all Teams belong to. Owns the configuration (scoring, roster shape, schedule length, playoff size) and the schedule.
_Avoid_: Group, pool

**Season**:
One NFL year's run of the League (e.g. 2026). Every Roster, Matchup, score, and Draft belongs to exactly one Season.
_Avoid_: Year (as a domain entity)

**Schedule**:
The auto-generated round-robin of Matchups for the regular season, giving byes when the Team count is odd.

**Matchup**:
A single week's weekly head-to-head pairing of two Teams. The Team with the higher weekly score wins; results roll up into Standings.
_Avoid_: Game (a Game is an NFL contest), fixture

**Standings**:
The ranked list of Teams by win-loss record, used to seed the Playoffs.

**Playoffs**:
The single-elimination bracket among the top-seeded Teams at the end of the regular season. The number of Teams that qualify is Commissioner-configured.
_Avoid_: Postseason (informal use is fine)

### Rosters & players

**Player**:
An NFL athlete (or a team defense) who can be drafted, rostered, and scored. The League's canonical Player list is the shared universe every Team draws from.
_Avoid_: Athlete; never "Manager"

**Roster**:
The full set of Players a Team controls — both the Lineup and the Bench.
_Avoid_: Squad

**Lineup**:
The Players a Manager starts in a given week, one per required position slot (QB, RB, WR, TE, FLEX, K, DEF). Only Lineup Players score for the Team that week.
_Avoid_: Starters (informal use is fine), active roster

**Bench**:
Rostered Players not in this week's Lineup. They do not score.
_Avoid_: Reserves, IR

### The draft

**Draft**:
The event, held before Week 1, where Managers take turns claiming Players until Rosters are filled. Run as a snake draft in a shared, near-real-time draft room.

**Snake Draft**:
A Draft where pick order reverses each round, so the Manager who picks first in one round picks last in the next.
_Avoid_: Serpentine

**Auto-pick**:
The safety-net selection the system makes for a Manager who lets the pick timer expire — the best-ranked available Player.

### Transactions

**Transaction**:
Any post-Draft change to a Roster — an Add/Drop or a Trade. The Commissioner can reverse any Transaction.
_Avoid_: Move, deal

**Add/Drop**:
A Manager claiming an unrostered Player (adding) while releasing a rostered one to make room (dropping). First-come-first-served — there is no waiver priority or bidding.
_Avoid_: Waiver, pickup, FAAB

**Trade**:
A Manager-to-Manager exchange of Players, completed by one side proposing and the other accepting.

### Scoring & data

**Live Score**:
The provisional, in-progress score shown during NFL games, derived from Sleeper. It updates as games unfold and is explicitly not final.
_Avoid_: Real-time score, projected score

**Official Score**:
The authoritative, final score for a completed week, derived from nflverse data. It supersedes the Live Score and may differ from it once official stats are corrected.
_Avoid_: Final score (as a distinct term), verified score

**Sleeper**:
The external API that provides the canonical Player universe and the Live Score feed during games.

**nflverse**:
The external source of official weekly NFL statistics, used to compute the Official Score.

**Unmatched Player**:
A Player in the Sleeper-sourced universe that the system could not automatically link to nflverse stats. Surfaced to the Commissioner for manual review so scoring gaps are caught before they affect results.
