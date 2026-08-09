# Running a Mock Season

A **mock season** is a full end-to-end dry run of the site — auto-draft, several
scored game weeks, and a playoff bracket — driven by the `bin/mock-season.php`
helper. It exercises the whole system, including the real **Sleeper** (live) and
**nflverse** (official) data feeds, so you can confirm everything works *before*
a live draft where mistakes are costly.

There are two ways to run it:

- **Locally** (recommended for clicking through the UI) — on your Windows dev
  machine with Laragon.
- **On the server** (for verifying the ICDSoft environment itself) — the PHP 8.3
  CLI, the hosting's MySQL, and the exact cron commands.

---

## Why it runs against a *past* season (2024)

It is the NFL offseason, so neither feed has any stats for the upcoming season
yet — every player would score zero and you'd learn nothing. Both feeds are
keyed by `(season, week)` and hold real, complete history, so the mock runs
against a **completed season (default 2024)**. Scoring then produces real,
differentiated points. When you're done you simply throw the mock data away.

---

## ⚠️ Safety: never run the mock against live data

`bin/mock-season.php` **writes** to whatever database `config/config.php` points
at — it creates teams, runs a draft, and scores weeks. If you run it against the
**live** league database it will fill your real league with fake teams and a fake
draft.

- **Locally** this is fine — your Laragon `ffb` database is disposable. Still,
  start from a clean database each run (drop + re-migrate).
- **On the server** you must run it from an **isolated second checkout pointed at
  a throwaway database** (covered below). Never point the live checkout's config
  at anything but the live database.

---

## What the helper does

| Command | Effect |
|---------|--------|
| `setup --teams=N --season=YYYY --regular-weeks=W` | Creates N teams + manager logins (`manager1..N` / password `draft`), sets the season year and a short regular season. |
| `draft` | Auto-drafts every team to completion; materialises rosters and generates the schedule. |
| `week <N> [--live-only]` | Fills every team's lineup, live-scores from Sleeper, then settles official from nflverse (skip settlement with `--live-only`). |
| `playoffs-create` | Seeds the bracket from the standings; round 1 lands in week `regular-weeks + 1`. |
| `playoffs-advance` | Opens the next playoff round after the current one is final. |
| `full ...` | Runs the entire sequence above in one shot. |
| `status` | Prints season/week/draft/bracket state and current standings. |

The short regular season matters: the playoff bracket refuses to seed until every
matchup of `schedule.regular_season_weeks` is final, so a 3-week regular season
gets you to the playoffs quickly. This is set at `setup` time because the
schedule is generated when the draft completes.

---

## A) Local mock (Windows / Laragon)

Run from the project root. Paths assume the Laragon install recorded in the
project notes; adjust if yours differ.

```bash
# Full path to the Laragon PHP 8.3 CLI (not on PATH)
PHP="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"

# 1. Start from a clean database (drop + recreate schema `ffb`), then:
"$PHP" bin/migrate.php
"$PHP" bin/create-commissioner.php commish <password> "Commish"
"$PHP" cron/sync_players.php          # ~3200 players; needs internet

# 2. Run the whole season in one command
"$PHP" bin/mock-season.php full --teams=4 --season=2024 --regular-weeks=3
```

To click through the site while the mock data is in place, serve `public/` and
log in as `commish` (or any `managerN` / `draft`). Step-by-step instead of
`full`:

```bash
"$PHP" bin/mock-season.php setup --teams=4 --season=2024 --regular-weeks=3
"$PHP" bin/mock-season.php draft
"$PHP" bin/mock-season.php week 1
"$PHP" bin/mock-season.php week 2
"$PHP" bin/mock-season.php week 3
"$PHP" bin/mock-season.php playoffs-create
"$PHP" bin/mock-season.php week 4          # championship (regular-weeks + 1)
"$PHP" bin/mock-season.php playoffs-advance # only if > 2 playoff teams
"$PHP" bin/mock-season.php status
```

**When finished**, drop the `ffb` database and re-migrate to return to a clean
slate for the real season. (The live year is 2026; the mock leaves the season
year at 2024, so a fresh migrate is the clean reset.)

---

## B) Server mock (ICDSoft) — isolated

This verifies the *server* environment: the PHP 8.3 CLI, the hosting's MySQL, and
that the cron commands work. It runs in a **separate checkout** against a
**throwaway database**, so the live site at `ffb.michaelkhall.com` is never
touched.

### 1. Create a throwaway database (Control Panel)

Control Panel → **MySQL Databases**:

1. Create a database, e.g. `michaelkhall_ffbmock`.
2. Create/assign a database user and password, granted all privileges on it.
3. Note the **name**, **user**, **password**, host is `localhost`.

### 2. Make an isolated checkout

```bash
ssh michaelkhall@michaelkhall.com

cd ~/private
cp -r FFB FFBmock            # reuses vendor/, so no composer step needed
cd FFBmock
```

### 3. Point its config at the throwaway database

Edit `~/private/FFBmock/config/config.php` so `database`, `username`, and
`password` are the throwaway values from step 1 (leave the live checkout's config
alone). Then protect it:

```bash
chmod 600 config/config.php
```

### 4. Stand up the schema and data

All commands use the ICDSoft PHP 8.3 CLI and run **from `~/private/FFBmock`**:

```bash
/usr/local/bin/php83.cli bin/migrate.php
/usr/local/bin/php83.cli bin/create-commissioner.php commish <password> "Commish"
/usr/local/bin/php83.cli cron/sync_players.php
```

### 5. Run the mock

```bash
/usr/local/bin/php83.cli bin/mock-season.php full --teams=4 --season=2024 --regular-weeks=3
/usr/local/bin/php83.cli bin/mock-season.php status
```

### 6. (Optional) Test the exact cron commands

The scoring crons read the same settings the mock writes, so you can prove the
real cron invocations against the mock data — run them from `~/private/FFBmock`:

```bash
/usr/local/bin/php83.cli /home/michaelkhall/private/FFBmock/cron/live_scores.php
/usr/local/bin/php83.cli /home/michaelkhall/private/FFBmock/cron/settle_official.php
```

(The live cron jobs stay pointed at `~/private/FFB` — don't change them.)

### 7. Tear it down

```bash
rm -rf ~/private/FFBmock
```

Then delete `michaelkhall_ffbmock` in Control Panel → MySQL Databases.

> **Seeing the mock in a browser on the server** would require pointing a docroot
> at `FFBmock/public` (a second subdomain) — usually not worth it. Use the
> **local** mock for UI walk-throughs; use the **server** mock to confirm the
> environment, feeds, and crons from the command line.

---

## Known caveat surfaced by the mock

**Standings include playoff games.** The `/standings` page counts every final
matchup regardless of round, so once the playoff bracket exists, playoff wins and
losses inflate each team's regular-season record (in testing a 3-0 team showed as
5-0 after two playoff wins). This is invisible during the regular season and is
tracked as a separate fix — just be aware of it when reading standings after the
bracket is created.

---

## Quick reference

- **Managers created by the mock:** `manager1`, `manager2`, … password `draft`.
- **Default shape:** 4 teams, season 2024, 3-week regular season, then a 2-round
  bracket (with `--teams=4`).
- **Feeds need internet:** player sync and weekly scoring both fetch live URLs.
- **Isolation rule:** the mock writes to whatever `config/config.php` targets —
  keep it pointed at a disposable database, never the live one.
