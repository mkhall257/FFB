# Commissioner's Guide

Everything the Commissioner needs to run the Peak Dragon Fantasy Football League
— from first-time setup through the draft, the regular season, transactions, and
the playoffs. It's organised in the order you'll actually use it across a season,
with a quick-reference map at the end.

> **You can undo almost anything.** The Commissioner can reverse transactions,
> correct or undo draft picks, re-settle a scored week, and undo a playoff round.
> If something goes wrong, it's fixable — don't panic.

---

## 1. Orientation

### Who's who

- **Commissioner** — you. The single administrator who sets everything up and can
  reverse any action. (A backup Commissioner account can exist with the same
  powers.)
- **Manager** — a kid who controls one **Team**: drafts players, sets a weekly
  lineup, and makes trades and pickups.
- **Team** — a Manager's franchise. The league has **4–10 Teams**.
- **Player** — an NFL athlete or team defense that can be drafted and scored.

### Signing in and getting around

Go to `/login` and sign in with your Commissioner username and password. The site
works on phones and computers.

- The **top nav** (and the bottom tab bar on phones) has the everyday pages:
  **Home, Matchup, Standings, My Team, Playoffs**.
- The **menu drawer** (☰) has everything, including a **Commissioner** section
  only you can see:

| Commissioner menu item | What it's for |
|------------------------|---------------|
| **Commissioner Tools** (`/admin`) | Create Teams and Manager logins; reset passwords; activate/deactivate/delete Teams and Managers. |
| **Season Control** (`/admin/season`) | Start each week, set scoring and roster shape, set the trade deadline and playoff size. |
| **Draft Setup** (`/admin/draft`) | Configure, order, start, and run the Draft. |
| **Roster Edit** (`/admin/roster-edit`) | Manually move players between Teams / the free-agent pool. |
| **Unmatched Players** (`/admin/unmatched-players`) | Check the player catalog and the latest data sync. |

Every Commissioner action shows a confirmation message ("flash") at the top of
the page when it succeeds, or a clear error if something's off.

---

## 2. Preseason setup

Do these once, before the draft.

### 2.1 Set the scoring and roster rules — **Season Control**

Open **Season Control**. Two forms define how the league plays:

- **Scoring** — points per passing/rushing/receiving yard, touchdowns,
  receptions (the league ships with **Half-PPR**), etc. Adjust any value and save.
- **Roster shape** — how many of each position start each week and how big the
  bench is (defaults: **QB, RB, RB, WR, WR, TE, FLEX, K, DEF** + a 5-player bench).
  These numbers also set how many rounds the draft runs (starters + bench).

Set these **before** the draft — the roster shape determines draft length, and
you don't want to change scoring mid-season.

### 2.2 Create the Teams — **Commissioner Tools**

Under **Commissioner Tools**, add each Team by name (e.g. "Thunder Lizards"). Team
names must be unique.

### 2.3 Create Manager logins — **Commissioner Tools**

For each Team, create the Manager's login: pick the Team, enter a **username**, a
**display name**, and a **password** (at least **6 characters**). Each Team gets
**one** Manager. There's no public sign-up — you provision every account.

Give each kid their username and password. They can change their own display name
and password later on **My Profile**.

### 2.4 Load the players — **Unmatched Players**

The player catalog fills automatically from a daily sync (see §8). To fill it
immediately, whoever manages the hosting runs the player-sync once (§8). Then open
**Unmatched Players** to confirm:

- **Total players** is in the thousands (~3,200).
- The **unmatched** list is short. "Unmatched" means a player isn't linked to the
  official weekly-stats source yet — usually obscure or just-added players, safe
  to ignore unless a well-known player you expect to be drafted is on the list.

---

## 3. Running the Draft — **Draft Setup**

The Draft is a **snake draft** (pick order reverses each round) held in a shared,
live draft room. You drive it from **Draft Setup**; Managers pick from **Draft
Room** (`/draft`).

### 3.1 Configure

On **Draft Setup**, set:

- **Pick timer** — seconds each Manager has per pick (15–600).
- **Auto-pick on expiry** — if on, a Manager who runs out of time gets the system's
  best pick for them; if off, an expired clock just leaves them on the clock.
- **Roster shape** — same slots as Season Control (shown here too for convenience).
- Optional **draft date/time** — display only, so Managers know when to show up.

### 3.2 Set the order

- **Randomize order** shuffles all active Teams, or
- **drag to reorder** manually.

You can re-order as many times as you like **until you finalize**.

### 3.3 Finalize, then Start

- **Finalize** locks the order. Managers can now see the order and build their
  **Queue** (a private ranked wishlist that speeds up their picks and drives
  auto-pick).
- **Start** makes the Draft **live** and puts the first Team on the clock.

### 3.4 Running a live Draft

While the Draft is live you have full control from **Draft Setup**:

| Control | What it does |
|---------|--------------|
| **Pause / Resume** | Freeze the clock for a break; resume banks the time left. |
| **Add time** | Give the Team on the clock extra seconds. |
| **Pick on behalf** | Make a pick for a Manager who can't (e.g. no internet). |
| **Auto-draft (per Team)** | Toggle a Team to pick automatically and instantly on its turn — use when a Manager has left or is absent. |
| **Correct a pick** | Swap a wrong pick for the right player. |
| **Undo last** | Roll back the most recent pick. |
| **Reset** | Wipe the board back to setup (start over). |

**Tip:** if a kid can't make it, turn on **Auto-draft** for their Team and the
system drafts a sensible roster for them without holding up everyone else.

### 3.5 What happens when the Draft finishes

When the last pick is made, the site automatically:

1. Turns each Team's drafted players into its **season Roster**, and
2. **Generates the regular-season schedule** (a round-robin; odd Team counts get
   byes).

You're now ready to play weeks. **Transactions (add/drop and trades) open
automatically** once the Draft is complete.

---

## 4. The regular season — the weekly rhythm

Each week follows the same loop. You do one thing (start the week); the data feeds
and Managers do the rest.

### 4.1 Start the week — **Season Control**

On **Season Control → Start a week**, set:

- **Week number** and **season year**.
- **Lineup-lock time** (kickoff) — prefilled to the coming Thursday 8:20pm league
  time, editable. **Lineups lock at this time** and can't be changed after.

Managers set their **lineup** on **My Team** (`/lineup`) before the lock — one
player per required slot. A Team's lineup carries forward week to week if they
don't change it. Benched players don't score.

### 4.2 Scoring happens automatically

Two automated jobs (set up on the hosting — see §8) handle scoring:

- **Live scoring** runs frequently during games and shows **provisional** scores
  on the **Matchup** page as games unfold. These are not final.
- **Official settlement** runs once a day after the week's games, replaces the
  provisional numbers with **official** stats, marks the week **final**, and
  **locks** it. This can occasionally change a result — standings then reflect the
  settled outcome.

> **Kickers and Defenses:** the official source doesn't publish team-defense
> points-allowed or kicking, so K and DEF keep their live values through
> settlement. That's expected, not a bug.

If you ever need to re-settle a specific past week, there's a `settle_week`
setting (defaults to "the week before the current one"); normally you never touch
it.

### 4.3 Following along

- **Matchup** (`/scoreboard`) — this week's head-to-head scores.
- **Standings** (`/standings`) — win-loss records, ranked. **Standings count only
  regular-season games** and freeze once the regular season ends — playoff results
  never change them.

---

## 5. Transactions (pickups & trades)

Transactions are **Manager-driven** and open automatically after the Draft.

- **Add/Drop** — on **Free Agents** (`/players`), a Manager adds an unrostered
  player and drops one to make room. **First-come, first-served** — no waivers or
  bidding.
- **Trade** — on **Trades** (`/trades`), one Manager proposes and the other
  accepts. Both rosters update on acceptance.

### What the Commissioner controls

- **Trade deadline** — on **Season Control**, set the week after which trading
  closes. Leave it **blank** for no deadline.
- **Reverse any transaction** — on **Activity** (`/transactions`), you can reverse
  an applied add/drop or trade if it was a mistake or against league rules.
- **Roster Edit** (`/admin/roster-edit`) — the manual override: move any player to
  any Team, drop one to free agency, or add a free agent, bypassing the normal
  rules. Every edit is itself a **reversible** record.

---

## 6. The Playoffs

A single-elimination bracket among the top Teams. Runs on the same weekly scoring
machinery as the regular season.

### 6.1 Before the season ends

On **Season Control**, set **how many Teams make the playoffs** (2 or more, up to
the number of Teams).

### 6.2 Create the bracket

Once the **final regular-season week is settled** (every matchup that week is
final), use **Create the playoff bracket** on the Playoffs controls. This:

- **Freezes the standings as seeds** (best record = #1 seed), and
- Opens **Round 1** in the week after the regular season.

If it refuses, the most common reason is that the last regular week isn't fully
final yet — let settlement finish first.

### 6.3 Run each round

For each playoff round, treat it like a normal week: it lives in its own week, so
**Start that week** (lineups, lock time), let the crons score and settle it, then
**Advance to the next round**. Repeat until the champion is decided.

### 6.4 Fixing mistakes

- **Undo the last round** — if a score gets corrected after you advanced, roll the
  bracket back one round and re-advance.
- **Reset** — clear the whole bracket (before any playoff game has been played).

Everyone watches the bracket and the champion on **Playoffs** (`/playoffs`).

---

## 7. Ongoing Team & account admin — **Commissioner Tools**

Things that come up mid-season:

| Task | How |
|------|-----|
| **Reset a forgotten password** | Commissioner Tools → reset password for that Manager (min 6 chars). |
| **A kid leaves** | **Deactivate** their Manager and/or **Team**. Deactivated Teams are excluded from new drafts, schedules, and playoffs, but their history stays intact. |
| **Bring someone back** | **Reactivate** the Manager/Team. |
| **Remove a Team entirely** | **Delete** — but only allowed if the Team has **no** history (no draft, roster, matchups, or trades). Anything with history must be **deactivated** instead. |

Managers maintain their own display name and password on **My Profile**
(`/profile`).

---

## 8. Data feeds & automation (for whoever runs the hosting)

The site pulls real NFL data from two sources, run by three scheduled jobs
("cron jobs") on the server. These are set up once in the hosting Control Panel.

| Job | How often | What it does |
|-----|-----------|--------------|
| **Player sync** | Daily | Refreshes the player catalog from Sleeper. |
| **Live scoring** | Every ~2 min during game windows (Thu/Sun/Mon) | Provisional scores from Sleeper for the current week. |
| **Official settlement** | Daily | Final scores from nflverse; marks the week final and locks it. |

On this hosting (ICDSoft) the jobs use the PHP 8.3 CLI and the app under
`~/private/FFB`, e.g.:

```
/usr/local/bin/php83.cli /home/michaelkhall/private/FFB/cron/live_scores.php
/usr/local/bin/php83.cli /home/michaelkhall/private/FFB/cron/settle_official.php
/usr/local/bin/php83.cli /home/michaelkhall/private/FFB/cron/sync_players.php
```

All three read the settings you manage on **Season Control** (current week, season
year, kickoff/lock times) — so you never edit the database directly.

**Deploying code updates:** `cd ~/private/FFB && git pull` (add
`composer install --no-dev --optimize-autoloader` and `php83.cli bin/migrate.php`
only when an update adds dependencies or database changes).

---

## 9. Test before it counts — the mock season

Before your **live** draft, you can rehearse the entire season — draft, scored
weeks, and playoffs — against real historical data, so you see everything working
with zero risk to the real league. See **[Running a Mock Season](running-a-mock-season.md)**.

---

## 10. Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| **Everyone scored 0 for a week** | The week wasn't started, or you're pointed at a season with no data yet. Confirm **Season Control** shows the right week and season year, and that the scoring jobs ran. |
| **A Manager can't edit their lineup** | The week is **locked** (past kickoff). That's by design; use **Roster Edit** only for genuine corrections. |
| **"Can't create the playoff bracket"** | The final regular-season week isn't fully **final** yet — wait for settlement, then try again. |
| **"Can't delete this Team"** | It has league history — **deactivate** it instead of deleting. |
| **A drafted star shows on Unmatched Players** | Their official-stats link is missing; live (Sleeper) scoring still works. Flag it to whoever runs the hosting to reconcile. |
| **Site loads over `http://` but not `https://`** | On a brand-new subdomain the security certificate can lag a day; `http://` works meanwhile. |

---

## 11. Quick reference — Commissioner menu

| Menu item | Path | Use it to |
|-----------|------|-----------|
| Commissioner Tools | `/admin` | Teams, Manager logins, passwords, activate/deactivate/delete |
| Season Control | `/admin/season` | Start a week; scoring; roster shape; trade deadline; playoff size |
| Draft Setup | `/admin/draft` | Configure, order, start, and run the Draft |
| Roster Edit | `/admin/roster-edit` | Manually move players between Teams / free agency |
| Unmatched Players | `/admin/unmatched-players` | Player catalog + last sync check |
| Activity | `/transactions` | Review and **reverse** transactions |
| Playoffs | `/playoffs` | View the bracket (create/advance from the controls) |

### The season at a glance

1. **Preseason:** set scoring & roster → create Teams → create Manager logins → load players.
2. **Draft:** configure → order → finalize → start → run it → (rosters + schedule auto-generate).
3. **Each week:** start the week → Managers set lineups → scoring runs live, then settles final.
4. **Anytime:** manage add/drops, trades (with a deadline), reversals, roster fixes.
5. **Playoffs:** set size → create bracket after the last week settles → start & advance each round → champion.
