# Wave 3 — Live Scoring + Matchups + Standings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the league a regular season — generate a round-robin schedule at Draft completion, let Managers set weekly Lineups that lock at kickoff, ingest player stats (Sleeper live → nflverse official), score each Matchup, and rank Teams in Standings.

**Architecture:** Follows the existing plain-PHP-on-shared-hosting stack (ADR-0002): PDO repositories + focused services, wired in `Kernel.php`, HTTP integration tests against a real MySQL test DB. Stats are stored as raw lines in two states per player-week (`sleeper` live, `nflverse` official — ADR-0004/0005); a pure `ScoringEngine` turns a stat line + the key/value `league_settings` scoring config into points. Matchup scores are denormalized caches recomputed by cron; Standings derive from settled Matchups. Playoffs are out of scope (deferred).

**Tech Stack:** PHP 8.3, MySQL 8.4, PHPUnit 11, plain SQL migrations run by `bin/migrate.php`, ICDSoft cron for background jobs, Sleeper API + nflverse CSV for stats.

## Global Constraints

- PHP files: `declare(strict_types=1);`, `namespace FFB` (or `FFB\<Sub>`), `final class`, constructor property promotion — match existing `src/` style.
- All DB access through `PDO` prepared statements inside repository classes; services hold repositories, never raw SQL where a repository fits.
- Migrations: sequential `NNNN_snake_case.sql` in `migrations/`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, FKs named `fk_<table>_<ref>`. Next free number is **0016**.
- Configuration stays data-driven in `league_settings` (key/value) — no hardcoded scoring/roster/schedule constants. Read via `LeagueSettingsRepository::all()`, seed via new migrations, not code.
- Tests are HTTP-level where a route exists (extend `FFB\Tests\Support\DatabaseTestCase`, dispatch through `Kernel::router($this->pdo)`); pure services (ScoringEngine, ScheduleGenerator) get plain unit tests.
- Current League/Season come from `LeagueRepository::currentLeagueId()` / `currentSeasonId()`.
- Player identity: `players.sleeper_id` (VARCHAR(32)) is the spine; `players.nflverse_id` links official stats (ADR-0006). Roster membership is `rosters(season_id, team_id, player_id)`.
- Positions in use: `QB, RB, WR, TE, K, DEF`; Lineup slots: `QB, RB, WR, TE, FLEX, K, DEF` (FLEX = RB/WR/TE).
- Commit after every green step; `feat:`/`test:`/`docs:` prefixes; end commit bodies with the Co-Authored-By trailer this repo uses.

## Decisions locked in grilling (2026-08-08)

- **Lineups** are a separate table keyed `(season, week, team, slot, player)`; Bench = rostered players absent from that week's rows.
- **Lineup lock**: whole lineup freezes at the week's first NFL kickoff (one lock time per week).
- **Lineup default**: carry forward previous week's Lineup; Week 1 auto-fills best-legal players (reuse ADR-0007 fallback ranking).
- **Stats**: store raw stat lines, **two rows per player-week** by `source` (`sleeper`/`nflverse`); points computed, never stored raw. Resolution: use nflverse row if present, else sleeper.
- **Schedule**: generated at Draft completion; round-robin **cycles** to fill a configurable `schedule.regular_season_weeks` (default 14). Byes = absence of a Matchup row.
- **Matchups**: cache `home_score`/`away_score` + `status` (`scheduled`/`live`/`final`).
- **Standings**: record (win%) → total points scored; no head-to-head; **ties allowed** (W-L-T; a tie = half a win in win%).
- **K/DEF scoring**: simplified, added as new `scoring.*` keys (flat FG/XP; flat DEF events + coarse points-allowed tier).
- **Crons**: frequent Live cron during game windows (Sleeper) writes stat rows + cached Matchup scores; separate daily Official cron (nflverse) settles + locks the week; settlement may change results and Standings recompute.
- **Playoffs deferred** — Wave 3 ends at seed-ordered Standings.

## File Structure

New/changed files, grouped by responsibility:

**Migrations (Slice 1)**
- `migrations/0016_create_matchups.sql` — schedule + cached Matchup scores/status.
- `migrations/0017_create_lineups.sql` — per-week Lineup slot assignments.
- `migrations/0018_create_player_week_stats.sql` — raw stat lines, two sources.
- `migrations/0019_seed_schedule_and_scoring_extras.sql` — `schedule.*` + K/DEF `scoring.*` defaults.

**Domain services (pure where possible)**
- `src/Schedule/ScheduleGenerator.php` — round-robin (circle method) + cycling to N weeks. Pure.
- `src/Scoring/ScoringEngine.php` — stat line + settings → points. Pure.
- `src/Scoring/StatLine.php` — small value object wrapping a decoded stat array (optional helper).

**Repositories**
- `src/MatchupRepository.php`
- `src/LineupRepository.php`
- `src/PlayerWeekStatsRepository.php`
- `src/StandingsRepository.php` (read-only aggregate query) — or fold into `MatchupRepository`; kept separate here.

**Orchestration services**
- `src/Schedule/ScheduleService.php` — generate + persist schedule from the final Team set; clear on draft reopen.
- `src/Lineup/LineupService.php` — read/save Lineup, carry-forward, Week-1 auto-fill, lock check.
- `src/Scoring/MatchupScoringService.php` — recompute a week's Matchup scores from lineups × stats.
- `src/Scoring/SettlementService.php` — apply official stats, set `status='final'`, lock the week.
- `src/StandingsService.php` — compute ordered Standings from settled Matchups.

**Stats ingestion**
- `src/Scoring/SleeperStatsClient.php` — fetch live weekly stats from Sleeper.
- `src/Scoring/NflverseStatsClient.php` — fetch official weekly stats CSV from nflverse.
- `src/Scoring/StatsImporter.php` — map a source's raw rows into `player_week_stats` (uses the crosswalk for nflverse).

**Controllers / views / routes**
- `src/Controllers/LineupController.php` + `views/lineup.php`
- `src/Controllers/ScoreboardController.php` + `views/scoreboard.php`, `views/matchup.php`
- `src/Controllers/StandingsController.php` + `views/standings.php`
- `src/Kernel.php` — wire new repos/services/controllers/routes.

**Cron**
- `cron/live_scores.php` — Sleeper live poll → stats + Matchup score cache.
- `cron/settle_official.php` — nflverse ingest → settle + lock the completed week.

**ADRs**
- `docs/adr/0008-lineups-and-weekly-lock.md`
- `docs/adr/0009-schedule-generation-and-standings.md`

---

## Slice 1 — Schema & configuration foundation

Delivers the tables and settings everything else reads/writes. Testable via a migration round-trip test.

### Task 1: Matchups table

**Files:**
- Create: `migrations/0016_create_matchups.sql`
- Test: `tests/MatchupSchemaTest.php`

**Interfaces:**
- Produces: table `matchups(id, league_id, season_id, week, home_team_id, away_team_id, home_score DECIMAL(6,2) NULL, away_score DECIMAL(6,2) NULL, status ENUM('scheduled','live','final') DEFAULT 'scheduled', created_at)`; unique `(season_id, week, home_team_id)`; index `(season_id, week)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Tests\Support\DatabaseTestCase;

final class MatchupSchemaTest extends DatabaseTestCase
{
    public function testMatchupsTableAcceptsAScheduledRow(): void
    {
        $this->pdo->exec(
            "INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id)"
            . " VALUES (1, 1, 1, 10, 11)"
        );
        $row = $this->pdo->query('SELECT status, home_score FROM matchups')->fetch();

        $this->assertSame('scheduled', $row['status']);
        $this->assertNull($row['home_score']);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/MatchupSchemaTest.php`
Expected: FAIL — table `matchups` doesn't exist. (The test DB is migrated by `DatabaseTestCase`; the migration doesn't exist yet.)

- [ ] **Step 3: Write the migration**

```sql
-- The regular-season Schedule: one row per weekly head-to-head Matchup.
-- Generated at Draft completion from the final Team set (round-robin, cycled to
-- fill schedule.regular_season_weeks). A Team with no row in a week has a bye.
-- home_score/away_score are denormalized caches recomputed from lineups x stats;
-- status tracks the ADR-0005 lifecycle (scheduled -> live -> final on settle).

CREATE TABLE matchups (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id     INT UNSIGNED NOT NULL,
    season_id     INT UNSIGNED NOT NULL,
    week          TINYINT UNSIGNED NOT NULL,
    home_team_id  INT UNSIGNED NOT NULL,
    away_team_id  INT UNSIGNED NOT NULL,
    home_score    DECIMAL(6,2) NULL,
    away_score    DECIMAL(6,2) NULL,
    status        ENUM('scheduled','live','final') NOT NULL DEFAULT 'scheduled',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matchup_home (season_id, week, home_team_id),
    KEY idx_matchup_week (season_id, week),
    CONSTRAINT fk_matchups_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_matchups_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_matchups_home FOREIGN KEY (home_team_id) REFERENCES teams (id),
    CONSTRAINT fk_matchups_away FOREIGN KEY (away_team_id) REFERENCES teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/MatchupSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add migrations/0016_create_matchups.sql tests/MatchupSchemaTest.php
git commit -m "feat: Wave 3 slice 1 — matchups table"
```

### Task 2: Lineups table

**Files:**
- Create: `migrations/0017_create_lineups.sql`
- Test: extend `tests/MatchupSchemaTest.php` (rename mentally to schema coverage) — add `LineupSchemaTest.php`

**Interfaces:**
- Produces: table `lineups(id, league_id, season_id, week, team_id, roster_slot ENUM('QB','RB','WR','TE','FLEX','K','DEF'), slot_index TINYINT, player_id VARCHAR(32) NULL, created_at)`; unique `(season_id, week, team_id, roster_slot, slot_index)` so a Team has exactly one player per physical slot; index `(season_id, week, team_id)`. `slot_index` disambiguates duplicated slots (RB1/RB2 etc.). `player_id` nullable so an intentionally-empty slot can be stored.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Tests\Support\DatabaseTestCase;

final class LineupSchemaTest extends DatabaseTestCase
{
    public function testASlotIsUniquePerTeamWeek(): void
    {
        $sql = "INSERT INTO lineups (league_id, season_id, week, team_id, roster_slot, slot_index, player_id)"
             . " VALUES (1, 1, 1, 10, 'RB', ?, ?)";
        $this->pdo->prepare($sql)->execute([0, 'P1']);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare($sql)->execute([0, 'P2']); // same slot -> unique violation
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/LineupSchemaTest.php`
Expected: FAIL — table `lineups` doesn't exist.

- [ ] **Step 3: Write the migration**

```sql
-- A Team's weekly Lineup: one row per physical starting slot. Bench = rostered
-- Players with no lineup row that week. Written by carry-forward at schedule
-- generation and edited by the Manager until the week's kickoff lock. slot_index
-- separates duplicated slots (RB slot_index 0 and 1). player_id is nullable so an
-- intentionally-empty slot is representable.

CREATE TABLE lineups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id   INT UNSIGNED NOT NULL,
    season_id   INT UNSIGNED NOT NULL,
    week        TINYINT UNSIGNED NOT NULL,
    team_id     INT UNSIGNED NOT NULL,
    roster_slot ENUM('QB','RB','WR','TE','FLEX','K','DEF') NOT NULL,
    slot_index  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    player_id   VARCHAR(32) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lineup_slot (season_id, week, team_id, roster_slot, slot_index),
    KEY idx_lineup_team_week (season_id, week, team_id),
    CONSTRAINT fk_lineups_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_lineups_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_lineups_team FOREIGN KEY (team_id) REFERENCES teams (id),
    CONSTRAINT fk_lineups_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/LineupSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add migrations/0017_create_lineups.sql tests/LineupSchemaTest.php
git commit -m "feat: Wave 3 slice 1 — lineups table"
```

### Task 3: Player week stats table

**Files:**
- Create: `migrations/0018_create_player_week_stats.sql`
- Test: `tests/PlayerWeekStatsSchemaTest.php`

**Interfaces:**
- Produces: table `player_week_stats(id, season_id, week, player_id VARCHAR(32), source ENUM('sleeper','nflverse'), stats JSON, updated_at)`; unique `(season_id, week, player_id, source)`. `stats` is a JSON object keyed by stat name (e.g. `{"pass_yard":250,"pass_td":2}`); the scoring engine reads it decoded.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Tests\Support\DatabaseTestCase;

final class PlayerWeekStatsSchemaTest extends DatabaseTestCase
{
    public function testTwoSourcesCoexistForSamePlayerWeek(): void
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO player_week_stats (season_id, week, player_id, source, stats)"
            . " VALUES (1, 1, 'P1', ?, ?)"
        );
        $ins->execute(['sleeper', '{"pass_yard":250}']);
        $ins->execute(['nflverse', '{"pass_yard":248}']);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM player_week_stats WHERE player_id = 'P1'"
        )->fetchColumn();

        $this->assertSame(2, $count);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/PlayerWeekStatsSchemaTest.php`
Expected: FAIL — table doesn't exist.

- [ ] **Step 3: Write the migration**

```sql
-- Raw weekly stat lines per Player, in the two states of ADR-0005: the 'sleeper'
-- row is the provisional Live line, the 'nflverse' row is the Official line that
-- supersedes it. Both are retained so a settle is auditable. Points are never
-- stored here — ScoringEngine computes them from stats x league_settings so a
-- scoring-config change re-scores correctly. stats is a JSON object of stat_name
-- => numeric value.

CREATE TABLE player_week_stats (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season_id  INT UNSIGNED NOT NULL,
    week       TINYINT UNSIGNED NOT NULL,
    player_id  VARCHAR(32) NOT NULL,
    source     ENUM('sleeper','nflverse') NOT NULL,
    stats      JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pws (season_id, week, player_id, source),
    KEY idx_pws_week (season_id, week),
    CONSTRAINT fk_pws_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_pws_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/PlayerWeekStatsSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add migrations/0018_create_player_week_stats.sql tests/PlayerWeekStatsSchemaTest.php
git commit -m "feat: Wave 3 slice 1 — player_week_stats table"
```

### Task 4: Seed schedule + K/DEF scoring settings

**Files:**
- Create: `migrations/0019_seed_schedule_and_scoring_extras.sql`
- Test: `tests/ScheduleScoringSettingsTest.php`

**Interfaces:**
- Produces settings for the current League/Season: `schedule.regular_season_weeks=14`; kicker `scoring.fg_made=3`, `scoring.xp_made=1`; defense `scoring.def_sack=1`, `scoring.def_int=2`, `scoring.def_fumble_rec=2`, `scoring.def_td=6`, `scoring.def_safety=2`; DEF points-allowed tiers `scoring.def_pa_0=10`, `scoring.def_pa_1_6=7`, `scoring.def_pa_7_13=4`, `scoring.def_pa_14_20=1`, `scoring.def_pa_21_27=0`, `scoring.def_pa_28_34=-1`, `scoring.def_pa_35=-4`.
- Note for later tasks: the DEF points-allowed stat is carried in the stat line as `def_points_allowed`; ScoringEngine maps it to one tier value (Task 8).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class ScheduleScoringSettingsTest extends DatabaseTestCase
{
    public function testDefaultsIncludeScheduleLengthAndKickerDefenseScoring(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $settings = (new LeagueSettingsRepository($this->pdo))->all(
            $leagues->currentLeagueId(),
            $leagues->currentSeasonId(),
        );

        $this->assertSame('14', $settings['schedule.regular_season_weeks']);
        $this->assertSame('3', $settings['scoring.fg_made']);
        $this->assertSame('7', $settings['scoring.def_pa_1_6']);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/ScheduleScoringSettingsTest.php`
Expected: FAIL — keys absent.

- [ ] **Step 3: Write the migration**

```sql
-- Extend league_settings for Wave 3: regular-season length (the round-robin
-- cycles to fill it) and simplified Kicker/Defense scoring (flat FG/XP, flat DEF
-- events, coarse points-allowed tiers). Data-driven so the Commissioner can edit
-- later without a schema change.

INSERT INTO league_settings (league_id, season_id, setting_key, setting_value)
SELECT l.id, s.id, k.setting_key, k.setting_value
FROM leagues l
JOIN seasons s ON s.league_id = l.id AND s.is_current = 1
JOIN (
    SELECT 'schedule.regular_season_weeks' AS setting_key, '14' AS setting_value
    UNION ALL SELECT 'scoring.fg_made', '3'
    UNION ALL SELECT 'scoring.xp_made', '1'
    UNION ALL SELECT 'scoring.def_sack', '1'
    UNION ALL SELECT 'scoring.def_int', '2'
    UNION ALL SELECT 'scoring.def_fumble_rec', '2'
    UNION ALL SELECT 'scoring.def_td', '6'
    UNION ALL SELECT 'scoring.def_safety', '2'
    UNION ALL SELECT 'scoring.def_pa_0', '10'
    UNION ALL SELECT 'scoring.def_pa_1_6', '7'
    UNION ALL SELECT 'scoring.def_pa_7_13', '4'
    UNION ALL SELECT 'scoring.def_pa_14_20', '1'
    UNION ALL SELECT 'scoring.def_pa_21_27', '0'
    UNION ALL SELECT 'scoring.def_pa_28_34', '-1'
    UNION ALL SELECT 'scoring.def_pa_35', '-4'
) k;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/ScheduleScoringSettingsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add migrations/0019_seed_schedule_and_scoring_extras.sql tests/ScheduleScoringSettingsTest.php
git commit -m "feat: Wave 3 slice 1 — schedule length + K/DEF scoring defaults"
```

---

## Slice 2 — Schedule generation

Delivers a round-robin schedule persisted at Draft completion, cycling to fill the configured weeks; byes for odd Team counts; cleared when the Draft reopens.

### Task 5: ScheduleGenerator (pure round-robin, cycled)

**Files:**
- Create: `src/Schedule/ScheduleGenerator.php`
- Test: `tests/ScheduleGeneratorTest.php`

**Interfaces:**
- Produces: `FFB\Schedule\ScheduleGenerator::generate(array $teamIds, int $weeks): array` returning `list<array{week:int,home_team_id:int,away_team_id:int}>`. Uses the circle method; for an odd count a rotating BYE slot drops one Team each week (no row emitted). Cycles the round-robin until `$weeks` weeks are produced, alternating home/away on each cycle so repeat pairings swap venue.
- Consumed by: `ScheduleService` (Task 6).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Schedule\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

final class ScheduleGeneratorTest extends TestCase
{
    public function testEvenTeamsProduceFullWeeksWithNoByes(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4], 3);

        // 4 teams -> 2 matchups per week, no byes.
        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[$r['week']][] = $r;
        }
        $this->assertCount(2, $byWeek[1]);
        $this->assertCount(2, $byWeek[2]);
        $this->assertCount(2, $byWeek[3]);
    }

    public function testEveryTeamPlaysAtMostOncePerWeek(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4, 5], 14); // odd -> byes

        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[$r['week']][] = $r;
        }
        foreach ($byWeek as $week => $matchups) {
            $seen = [];
            foreach ($matchups as $m) {
                $this->assertArrayNotHasKey($m['home_team_id'], $seen, "team double-booked in week $week");
                $this->assertArrayNotHasKey($m['away_team_id'], $seen, "team double-booked in week $week");
                $seen[$m['home_team_id']] = true;
                $seen[$m['away_team_id']] = true;
            }
        }
    }

    public function testFillsExactlyTheRequestedWeeks(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4], 14);
        $weeks = array_unique(array_column($rows, 'week'));
        sort($weeks);
        $this->assertSame(range(1, 14), $weeks);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/ScheduleGeneratorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace FFB\Schedule;

/**
 * Generates a regular-season Schedule for the League's Teams using the circle
 * method, then cycles the round-robin to fill the configured number of weeks.
 * An odd Team count introduces a rotating BYE placeholder; the Team paired with
 * BYE that week simply has no Matchup (a bye). Pure — no I/O.
 */
final class ScheduleGenerator
{
    private const BYE = 0;

    /**
     * @param list<int> $teamIds
     * @return list<array{week:int,home_team_id:int,away_team_id:int}>
     */
    public function generate(array $teamIds, int $weeks): array
    {
        $teams = array_values($teamIds);
        if (count($teams) < 2 || $weeks < 1) {
            return [];
        }
        if (count($teams) % 2 === 1) {
            $teams[] = self::BYE;
        }

        $n = count($teams);
        $rounds = $n - 1;          // distinct weeks in one round-robin
        $half = intdiv($n, 2);
        $rows = [];

        for ($week = 1; $week <= $weeks; $week++) {
            $round = ($week - 1) % $rounds;
            $cycle = intdiv($week - 1, $rounds); // which pass through the round-robin
            $arrangement = $this->rotate($teams, $round);

            for ($i = 0; $i < $half; $i++) {
                $a = $arrangement[$i];
                $b = $arrangement[$n - 1 - $i];
                if ($a === self::BYE || $b === self::BYE) {
                    continue; // bye
                }
                // Swap home/away each cycle so repeat pairings alternate venue.
                [$home, $away] = $cycle % 2 === 0 ? [$a, $b] : [$b, $a];
                $rows[] = ['week' => $week, 'home_team_id' => $home, 'away_team_id' => $away];
            }
        }

        return $rows;
    }

    /**
     * Circle-method rotation: team 0 is fixed, the rest rotate clockwise.
     *
     * @param list<int> $teams
     * @return list<int>
     */
    private function rotate(array $teams, int $round): array
    {
        $fixed = $teams[0];
        $rest = array_slice($teams, 1);
        $count = count($rest);
        $rotated = [];
        for ($i = 0; $i < $count; $i++) {
            $rotated[$i] = $rest[($i - $round % $count + $count) % $count];
        }

        return array_merge([$fixed], $rotated);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ScheduleGeneratorTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add src/Schedule/ScheduleGenerator.php tests/ScheduleGeneratorTest.php
git commit -m "feat: Wave 3 slice 2 — round-robin schedule generator"
```

### Task 6: MatchupRepository + ScheduleService, generated at Draft completion

**Files:**
- Create: `src/MatchupRepository.php`, `src/Schedule/ScheduleService.php`
- Modify: `src/Draft/DraftService.php:172-190` (the `advance()` completion branch — call ScheduleService after roster materialization), `src/Kernel.php:39-49` (wire MatchupRepository + ScheduleService into DraftService), and the draft-reopen paths that call `rosters->clearForSeason` (undo/reset) to also clear matchups.
- Test: `tests/ScheduleGenerationHttpTest.php`

**Interfaces:**
- Consumes: `ScheduleGenerator::generate()`; `TeamRepository` (list Team ids for the Season); `LeagueSettingsRepository::all()` for `schedule.regular_season_weeks`.
- Produces:
  - `FFB\MatchupRepository::insertMany(int $leagueId, int $seasonId, array $rows): void` where `$rows` is `list<array{week:int,home_team_id:int,away_team_id:int}>`.
  - `FFB\MatchupRepository::clearForSeason(int $seasonId): void`
  - `FFB\MatchupRepository::forWeek(int $seasonId, int $week): array` → `list<array<string,mixed>>` (matchup rows).
  - `FFB\MatchupRepository::countForSeason(int $seasonId): int`
  - `FFB\Schedule\ScheduleService::generateForSeason(int $leagueId, int $seasonId): void` — reads team ids + weeks, calls generator, replaces matchups.
  - `FFB\Schedule\ScheduleService::clearForSeason(int $seasonId): void`
- Note: `TeamRepository` — confirm the method that lists Team ids for a Season; if none exists add `TeamRepository::idsForSeason(int $seasonId): array`. Check `src/TeamRepository.php` before implementing.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Completing the Draft generates the regular-season Schedule; reopening clears it.
 */
final class ScheduleGenerationHttpTest extends DatabaseTestCase
{
    private function commissioner(): ArraySession
    {
        $leagues = new LeagueRepository($this->pdo);
        return new ArraySession([
            'user_id' => 9999, 'role' => 'commissioner',
            'league_id' => $leagues->currentLeagueId(), 'display_name' => 'Boss',
        ]);
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $this->commissioner());
    }

    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    private function matchupCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM matchups')->fetchColumn();
    }

    public function testCompletingDraftGeneratesSchedule(): void
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 1; $i <= 2; $i++) {
            $ids[] = $teams->create(
                (new LeagueRepository($this->pdo))->currentLeagueId(),
                $this->seasonId(),
                "Team {$i}"
            );
        }
        (new PlayerRepository($this->pdo))->upsert('Q1', null, 'Q One', 'QB', 'KC', 'Active', 1);
        (new PlayerRepository($this->pdo))->upsert('Q2', null, 'Q Two', 'QB', 'KC', 'Active', 2);

        $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '120', 'autopick_on_expiry' => '0',
            'roster_qb' => '1', 'roster_rb' => '0', 'roster_wr' => '0', 'roster_te' => '0',
            'roster_flex' => '0', 'roster_k' => '0', 'roster_def' => '0', 'roster_bench' => '0',
        ]);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $ids]);
        $this->dispatch('POST', '/admin/draft/finalize');
        $this->dispatch('POST', '/admin/draft/start');
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q2']);

        // 2 teams x 14 weeks = 14 matchups (one per week).
        $this->assertSame(14, $this->matchupCount());
    }

    public function testUndoAfterCompletionClearsSchedule(): void
    {
        $this->testCompletingDraftGeneratesSchedule();
        $this->dispatch('POST', '/admin/draft/undo-last');
        $this->assertSame(0, $this->matchupCount());
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/ScheduleGenerationHttpTest.php`
Expected: FAIL — no matchups generated (service not wired).

- [ ] **Step 3: Implement MatchupRepository**

```php
<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the Schedule of Matchups. Rows are written once at Draft
 * completion and cleared if the Draft reopens; scores/status are updated later
 * by the scoring and settlement services.
 */
final class MatchupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{week:int,home_team_id:int,away_team_id:int}> $rows
     */
    public function insertMany(int $leagueId, int $seasonId, array $rows): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([$leagueId, $seasonId, $r['week'], $r['home_team_id'], $r['away_team_id']]);
        }
    }

    public function clearForSeason(int $seasonId): void
    {
        $this->pdo->prepare('DELETE FROM matchups WHERE season_id = ?')->execute([$seasonId]);
    }

    public function countForSeason(int $seasonId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM matchups WHERE season_id = ?');
        $stmt->execute([$seasonId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forWeek(int $seasonId, int $week): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM matchups WHERE season_id = ? AND week = ? ORDER BY id'
        );
        $stmt->execute([$seasonId, $week]);

        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 4: Implement ScheduleService**

```php
<?php

declare(strict_types=1);

namespace FFB\Schedule;

use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\TeamRepository;

/**
 * Builds and persists the regular-season Schedule from the final Team set at
 * Draft completion, and clears it if the Draft reopens.
 */
final class ScheduleService
{
    public function __construct(
        private readonly ScheduleGenerator $generator,
        private readonly MatchupRepository $matchups,
        private readonly TeamRepository $teams,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    public function generateForSeason(int $leagueId, int $seasonId): void
    {
        $teamIds = $this->teams->idsForSeason($seasonId);
        $settings = $this->settings->all($leagueId, $seasonId);
        $weeks = (int) ($settings['schedule.regular_season_weeks'] ?? 14);

        $this->matchups->clearForSeason($seasonId);
        $rows = $this->generator->generate($teamIds, $weeks);
        $this->matchups->insertMany($leagueId, $seasonId, $rows);
    }

    public function clearForSeason(int $seasonId): void
    {
        $this->matchups->clearForSeason($seasonId);
    }
}
```

- [ ] **Step 5: Add `TeamRepository::idsForSeason` if missing**

Read `src/TeamRepository.php` first. If there's no method returning Team ids for a Season, add:

```php
/**
 * @return list<int>
 */
public function idsForSeason(int $seasonId): array
{
    $stmt = $this->pdo->prepare('SELECT id FROM teams WHERE season_id = ? ORDER BY id');
    $stmt->execute([$seasonId]);

    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}
```

(Confirm the `teams` table has `season_id`; migration `0004_create_teams.sql` establishes it. Adjust the column if the schema differs.)

- [ ] **Step 6: Wire into DraftService completion + Kernel + reopen paths**

In `src/Draft/DraftService.php`, add a `ScheduleService $schedule` constructor dependency and, in `advance()` right after `materializeFromDraft(...)`, call:

```php
$this->schedule->generateForSeason(
    $this->leagues->currentLeagueId(),
    $this->leagues->currentSeasonId(),
);
```

In the Draft **undo-last** and **reset** handlers (find them in `DraftController`/`DraftRepository` where `rosters->clearForSeason` is invoked on reopen), add the matching `scheduleService->clearForSeason($seasonId)` call so reopening clears the schedule too. In `src/Kernel.php`, construct `ScheduleGenerator`, `MatchupRepository`, `ScheduleService`, and pass `ScheduleService` into `DraftService` (and wherever the reopen clears rosters).

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ScheduleGenerationHttpTest.php`
Expected: PASS (both).

- [ ] **Step 8: Run the full suite (no regressions in draft completion/undo/reset)**

Run: `vendor/bin/phpunit`
Expected: PASS across the suite (especially `DraftCompletionHttpTest`).

- [ ] **Step 9: Commit**

```bash
git add src/MatchupRepository.php src/Schedule/ScheduleService.php src/TeamRepository.php src/Draft/DraftService.php src/Kernel.php tests/ScheduleGenerationHttpTest.php
git commit -m "feat: Wave 3 slice 2 — generate schedule at draft completion"
```

---

## Slice 3 — Scoring engine (pure)

Delivers a pure function from a stat line + settings to fantasy points, including simplified K/DEF. No I/O; the load-bearing correctness of the whole wave.

### Task 7: ScoringEngine — offensive scoring

**Files:**
- Create: `src/Scoring/ScoringEngine.php`
- Test: `tests/ScoringEngineTest.php`

**Interfaces:**
- Produces: `FFB\Scoring\ScoringEngine::pointsFor(array $stats, array $settings): float`.
  - `$stats`: `array<string,float>` decoded stat line (e.g. `['pass_yard'=>250.0,'pass_td'=>2.0,'reception'=>4.0]`).
  - `$settings`: the `array<string,string>` from `LeagueSettingsRepository::all()`.
  - Returns points rounded to 2 decimals.
- Linear rules mapped stat_name → `scoring.<stat_name>`: `reception, pass_yard, pass_td, pass_int, rush_yard, rush_td, rec_yard, rec_td, fumble_lost, fg_made, xp_made, def_sack, def_int, def_fumble_rec, def_td, def_safety`.
- Consumed by: `MatchupScoringService` (Task 10), views.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Scoring\ScoringEngine;
use PHPUnit\Framework\TestCase;

final class ScoringEngineTest extends TestCase
{
    /** @return array<string,string> */
    private function halfPpr(): array
    {
        return [
            'scoring.reception' => '0.5', 'scoring.pass_yard' => '0.04', 'scoring.pass_td' => '4',
            'scoring.pass_int' => '-2', 'scoring.rush_yard' => '0.1', 'scoring.rush_td' => '6',
            'scoring.rec_yard' => '0.1', 'scoring.rec_td' => '6', 'scoring.fumble_lost' => '-2',
        ];
    }

    public function testScoresAHalfPprReceivingLine(): void
    {
        // 5 rec, 80 rec yds, 1 rec TD = 2.5 + 8.0 + 6.0 = 16.5
        $points = (new ScoringEngine())->pointsFor(
            ['reception' => 5, 'rec_yard' => 80, 'rec_td' => 1],
            $this->halfPpr(),
        );
        $this->assertSame(16.5, $points);
    }

    public function testScoresAPassingLineWithInterception(): void
    {
        // 300 pass yds (12.0) + 2 pass TD (8.0) - 1 INT (2.0) = 18.0
        $points = (new ScoringEngine())->pointsFor(
            ['pass_yard' => 300, 'pass_td' => 2, 'pass_int' => 1],
            $this->halfPpr(),
        );
        $this->assertSame(18.0, $points);
    }

    public function testUnknownStatsAreIgnored(): void
    {
        $points = (new ScoringEngine())->pointsFor(
            ['reception' => 2, 'made_up_stat' => 999],
            $this->halfPpr(),
        );
        $this->assertSame(1.0, $points);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/ScoringEngineTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation (offensive rules only)**

```php
<?php

declare(strict_types=1);

namespace FFB\Scoring;

/**
 * Turns a raw stat line into fantasy points using the League's key/value scoring
 * config (scoring.*). Pure and stateless. Linear stats multiply their value by
 * the matching scoring.<stat> weight; Defense points-allowed uses a tier lookup
 * (added in Task 8).
 */
final class ScoringEngine
{
    /** Stat names that score linearly as value * scoring.<name>. */
    private const LINEAR = [
        'reception', 'pass_yard', 'pass_td', 'pass_int',
        'rush_yard', 'rush_td', 'rec_yard', 'rec_td', 'fumble_lost',
        'fg_made', 'xp_made',
        'def_sack', 'def_int', 'def_fumble_rec', 'def_td', 'def_safety',
    ];

    /**
     * @param array<string,float|int> $stats
     * @param array<string,string> $settings
     */
    public function pointsFor(array $stats, array $settings): float
    {
        $points = 0.0;

        foreach (self::LINEAR as $stat) {
            if (!isset($stats[$stat])) {
                continue;
            }
            $weight = (float) ($settings['scoring.' . $stat] ?? 0);
            $points += (float) $stats[$stat] * $weight;
        }

        return round($points, 2);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ScoringEngineTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Scoring/ScoringEngine.php tests/ScoringEngineTest.php
git commit -m "feat: Wave 3 slice 3 — offensive scoring engine"
```

### Task 8: ScoringEngine — Defense points-allowed tier

**Files:**
- Modify: `src/Scoring/ScoringEngine.php`
- Test: `tests/ScoringEngineTest.php` (add cases)

**Interfaces:**
- No signature change. Adds handling of `def_points_allowed` stat: maps the value to one tier weight from `scoring.def_pa_*`, added to the linear DEF-event total.
- Tier mapping: 0 → `def_pa_0`; 1–6 → `def_pa_1_6`; 7–13 → `def_pa_7_13`; 14–20 → `def_pa_14_20`; 21–27 → `def_pa_21_27`; 28–34 → `def_pa_28_34`; ≥35 → `def_pa_35`.

- [ ] **Step 1: Add the failing tests**

```php
    public function testDefenseShutoutScoresEventsPlusTopTier(): void
    {
        // 3 sacks (3.0) + 1 INT (2.0) + 0 pts allowed tier (10.0) = 15.0
        $points = (new ScoringEngine())->pointsFor(
            ['def_sack' => 3, 'def_int' => 1, 'def_points_allowed' => 0],
            $this->defenseSettings(),
        );
        $this->assertSame(15.0, $points);
    }

    public function testDefenseMidTierPointsAllowed(): void
    {
        // 24 pts allowed -> def_pa_21_27 = 0.0; 2 sacks (2.0) = 2.0
        $points = (new ScoringEngine())->pointsFor(
            ['def_sack' => 2, 'def_points_allowed' => 24],
            $this->defenseSettings(),
        );
        $this->assertSame(2.0, $points);
    }

    /** @return array<string,string> */
    private function defenseSettings(): array
    {
        return [
            'scoring.def_sack' => '1', 'scoring.def_int' => '2', 'scoring.def_fumble_rec' => '2',
            'scoring.def_td' => '6', 'scoring.def_safety' => '2',
            'scoring.def_pa_0' => '10', 'scoring.def_pa_1_6' => '7', 'scoring.def_pa_7_13' => '4',
            'scoring.def_pa_14_20' => '1', 'scoring.def_pa_21_27' => '0',
            'scoring.def_pa_28_34' => '-1', 'scoring.def_pa_35' => '-4',
        ];
    }
```

- [ ] **Step 2: Run and verify the new tests fail**

Run: `vendor/bin/phpunit tests/ScoringEngineTest.php`
Expected: FAIL — `def_points_allowed` not yet handled (shutout returns 5.0 not 15.0).

- [ ] **Step 3: Add the tier handling**

In `pointsFor()`, after the linear loop and before `return`:

```php
        if (isset($stats['def_points_allowed'])) {
            $points += $this->pointsAllowedTier((int) $stats['def_points_allowed'], $settings);
        }
```

Add the private method:

```php
    /**
     * @param array<string,string> $settings
     */
    private function pointsAllowedTier(int $pointsAllowed, array $settings): float
    {
        $key = match (true) {
            $pointsAllowed <= 0  => 'scoring.def_pa_0',
            $pointsAllowed <= 6  => 'scoring.def_pa_1_6',
            $pointsAllowed <= 13 => 'scoring.def_pa_7_13',
            $pointsAllowed <= 20 => 'scoring.def_pa_14_20',
            $pointsAllowed <= 27 => 'scoring.def_pa_21_27',
            $pointsAllowed <= 34 => 'scoring.def_pa_28_34',
            default              => 'scoring.def_pa_35',
        };

        return (float) ($settings[$key] ?? 0);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ScoringEngineTest.php`
Expected: PASS (all, offensive + defense).

- [ ] **Step 5: Commit**

```bash
git add src/Scoring/ScoringEngine.php tests/ScoringEngineTest.php
git commit -m "feat: Wave 3 slice 3 — defense points-allowed tiers"
```

---

## Slice 4 — Lineups (set / lock / carry-forward)

Delivers the weekly Lineup: a Manager sets it, it locks at kickoff, defaults carry forward, Week 1 auto-fills.

### Task 9: PlayerWeekStatsRepository + LineupRepository + LineupService (carry-forward & auto-fill)

**Files:**
- Create: `src/PlayerWeekStatsRepository.php`, `src/LineupRepository.php`, `src/Lineup/LineupService.php`
- Test: `tests/LineupServiceTest.php`

**Interfaces:**
- `FFB\PlayerWeekStatsRepository`:
  - `upsert(int $seasonId, int $week, string $playerId, string $source, array $stats): void` — `$source` in `sleeper|nflverse`; `$stats` array JSON-encoded.
  - `resolvedForWeek(int $seasonId, int $week): array` → `array<string, array<string,float>>` map `player_id => stat line`, preferring the `nflverse` row when present else `sleeper`.
- `FFB\LineupRepository`:
  - `forTeamWeek(int $seasonId, int $week, int $teamId): array` → `list<array{roster_slot:string,slot_index:int,player_id:?string}>` ordered by slot then index.
  - `replaceForTeamWeek(int $leagueId, int $seasonId, int $week, int $teamId, array $slots): void` — replaces all rows for that team-week; `$slots` is `list<array{roster_slot:string,slot_index:int,player_id:?string}>`.
  - `startersForWeek(int $seasonId, int $week): array` → `array<int, list<array{roster_slot:string,player_id:?string}>>` map team_id → its started (non-null) players. Used by scoring.
- `FFB\Lineup\LineupService`:
  - `slotPlan(array $settings): array` → `list<array{roster_slot:string,slot_index:int}>` — the ordered physical slots from `roster.*` (QB×qb, RB×rb, WR×wr, TE×te, FLEX×flex, K×k, DEF×def).
  - `ensureLineup(int $leagueId, int $seasonId, int $week, int $teamId): void` — if the team-week has no rows, create them by carry-forward from `week-1`; if `week === 1` (or no prior lineup), auto-fill best-legal from the roster.
  - `saveLineup(int $leagueId, int $seasonId, int $week, int $teamId, array $assignments): void` — validate legality (slot eligibility, players are on the roster, no player in two slots) then persist. Throws `FFB\Lineup\LineupException` on illegal input or when the week is locked.
- Consumes: `RosterRepository::byTeam()` (roster players + positions), `LeagueSettingsRepository::all()`, `PlayerRepository` for position eligibility, and (Task 12) a lock-time source.
- Note: FLEX eligibility = position in `{RB,WR,TE}`. Auto-fill best-legal reuses the fallback ranking already used by Auto-pick — read `src/Draft/AutoPickStrategy.php` and reuse its ranking source (`players.search_rank`) rather than duplicating logic; fill required slots first, matching ADR-0007's spirit.

- [ ] **Step 1: Write the failing test (carry-forward + week-1 auto-fill)**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LineupRepository;
use FFB\Lineup\LineupService;
use FFB\PlayerRepository;
use FFB\RosterRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class LineupServiceTest extends DatabaseTestCase
{
    private function leagueId(): int { return (new LeagueRepository($this->pdo))->currentLeagueId(); }
    private function seasonId(): int { return (new LeagueRepository($this->pdo))->currentSeasonId(); }

    private function service(): LineupService
    {
        // Construct with the same dependencies Kernel will use.
        return new LineupService(
            new LineupRepository($this->pdo),
            new RosterRepository($this->pdo),
            new PlayerRepository($this->pdo),
            new \FFB\LeagueSettingsRepository($this->pdo),
        );
    }

    private function seedRosteredTeam(): int
    {
        $team = (new TeamRepository($this->pdo))->create($this->leagueId(), $this->seasonId(), 'T1');
        $players = new PlayerRepository($this->pdo);
        $roster = new RosterRepository($this->pdo);
        // One legal player per required slot for a QB1/RB1 minimal roster config.
        foreach ([['QB1','QB'],['RB1','RB'],['WR1','WR']] as [$id, $pos]) {
            $players->upsert($id, null, $id, $pos, 'KC', 'Active', 1);
            $this->pdo->prepare(
                'INSERT INTO rosters (league_id, season_id, team_id, player_id) VALUES (?,?,?,?)'
            )->execute([$this->leagueId(), $this->seasonId(), $team, $id]);
        }
        return $team;
    }

    public function testWeekOneAutoFillsRequiredSlots(): void
    {
        $team = $this->seedRosteredTeam();
        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);

        $rows = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);
        $filled = array_filter($rows, fn ($r) => $r['player_id'] !== null);
        $this->assertNotEmpty($filled, 'week 1 should auto-fill at least the required slots');
    }

    public function testWeekTwoCarriesForwardWeekOne(): void
    {
        $team = $this->seedRosteredTeam();
        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);
        $week1 = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);

        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 2, $team);
        $week2 = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 2, $team);

        $this->assertSame(
            array_column($week1, 'player_id'),
            array_column($week2, 'player_id'),
            'week 2 lineup should carry forward week 1',
        );
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit tests/LineupServiceTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement PlayerWeekStatsRepository**

```php
<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Raw weekly stat lines in two states (sleeper Live, nflverse Official). Reads
 * resolve to the Official line when present, else the Live line (ADR-0005).
 */
final class PlayerWeekStatsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,float|int> $stats
     */
    public function upsert(int $seasonId, int $week, string $playerId, string $source, array $stats): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO player_week_stats (season_id, week, player_id, source, stats)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE stats = VALUES(stats)'
        );
        $stmt->execute([$seasonId, $week, $playerId, $source, json_encode($stats, JSON_THROW_ON_ERROR)]);
    }

    /**
     * player_id => stat line, preferring nflverse over sleeper.
     *
     * @return array<string, array<string,float>>
     */
    public function resolvedForWeek(int $seasonId, int $week): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT player_id, source, stats FROM player_week_stats WHERE season_id = ? AND week = ?'
        );
        $stmt->execute([$seasonId, $week]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $pid = (string) $row['player_id'];
            $decoded = json_decode((string) $row['stats'], true) ?: [];
            $line = array_map('floatval', $decoded);
            // nflverse wins; only let sleeper set a value the official row hasn't.
            if ($row['source'] === 'nflverse' || !isset($out[$pid])) {
                $out[$pid] = $line;
            }
        }

        return $out;
    }
}
```

Note: because nflverse may arrive in any row order, prefer a two-pass read (load sleeper, then overwrite with nflverse) if the single-pass conditional proves fragile. Keep the "official wins" invariant covered by a test if you change it.

- [ ] **Step 4: Implement LineupRepository**

```php
<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Reads and writes weekly Lineup slot assignments. Bench = rostered Players with
 * no row here for the week.
 */
final class LineupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{roster_slot:string,slot_index:int,player_id:?string}>
     */
    public function forTeamWeek(int $seasonId, int $week, int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT roster_slot, slot_index, player_id FROM lineups"
            . " WHERE season_id = ? AND week = ? AND team_id = ?"
            . " ORDER BY FIELD(roster_slot,'QB','RB','WR','TE','FLEX','K','DEF'), slot_index"
        );
        $stmt->execute([$seasonId, $week, $teamId]);

        return array_map(static fn ($r) => [
            'roster_slot' => (string) $r['roster_slot'],
            'slot_index' => (int) $r['slot_index'],
            'player_id' => $r['player_id'] !== null ? (string) $r['player_id'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * @param list<array{roster_slot:string,slot_index:int,player_id:?string}> $slots
     */
    public function replaceForTeamWeek(int $leagueId, int $seasonId, int $week, int $teamId, array $slots): void
    {
        $this->pdo->prepare(
            'DELETE FROM lineups WHERE season_id = ? AND week = ? AND team_id = ?'
        )->execute([$seasonId, $week, $teamId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO lineups (league_id, season_id, week, team_id, roster_slot, slot_index, player_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($slots as $s) {
            $stmt->execute([$leagueId, $seasonId, $week, $teamId, $s['roster_slot'], $s['slot_index'], $s['player_id']]);
        }
    }

    /**
     * team_id => started (non-null) players for the week.
     *
     * @return array<int, list<array{roster_slot:string,player_id:string}>>
     */
    public function startersForWeek(int $seasonId, int $week): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT team_id, roster_slot, player_id FROM lineups'
            . ' WHERE season_id = ? AND week = ? AND player_id IS NOT NULL'
        );
        $stmt->execute([$seasonId, $week]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['team_id']][] = [
                'roster_slot' => (string) $r['roster_slot'],
                'player_id' => (string) $r['player_id'],
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 5: Implement LineupService (slotPlan, ensureLineup carry-forward + auto-fill)**

```php
<?php

declare(strict_types=1);

namespace FFB\Lineup;

use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\PlayerRepository;
use FFB\RosterRepository;

/**
 * Owns the weekly Lineup lifecycle: the slot plan from roster.* settings,
 * carry-forward defaulting, Week-1 auto-fill of best-legal Players, and saving a
 * Manager's chosen Lineup with legality checks. Lock enforcement is added in
 * Task 12.
 */
final class LineupService
{
    private const FLEX_ELIGIBLE = ['RB', 'WR', 'TE'];

    public function __construct(
        private readonly LineupRepository $lineups,
        private readonly RosterRepository $rosters,
        private readonly PlayerRepository $players,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    /**
     * The ordered physical slots for the League's roster shape.
     *
     * @param array<string,string> $settings
     * @return list<array{roster_slot:string,slot_index:int}>
     */
    public function slotPlan(array $settings): array
    {
        $shape = [
            'QB' => (int) ($settings['roster.qb'] ?? 0),
            'RB' => (int) ($settings['roster.rb'] ?? 0),
            'WR' => (int) ($settings['roster.wr'] ?? 0),
            'TE' => (int) ($settings['roster.te'] ?? 0),
            'FLEX' => (int) ($settings['roster.flex'] ?? 0),
            'K' => (int) ($settings['roster.k'] ?? 0),
            'DEF' => (int) ($settings['roster.def'] ?? 0),
        ];
        $plan = [];
        foreach ($shape as $slot => $count) {
            for ($i = 0; $i < $count; $i++) {
                $plan[] = ['roster_slot' => $slot, 'slot_index' => $i];
            }
        }

        return $plan;
    }

    public function ensureLineup(int $leagueId, int $seasonId, int $week, int $teamId): void
    {
        if ($this->lineups->forTeamWeek($seasonId, $week, $teamId) !== []) {
            return; // already has a lineup
        }

        $settings = $this->settings->all($leagueId, $seasonId);
        $plan = $this->slotPlan($settings);

        $prior = $week > 1 ? $this->lineups->forTeamWeek($seasonId, $week - 1, $teamId) : [];
        $slots = $prior !== []
            ? $this->carryForward($plan, $prior)
            : $this->autoFill($plan, $seasonId, $teamId);

        $this->lineups->replaceForTeamWeek($leagueId, $seasonId, $week, $teamId, $slots);
    }

    /**
     * @param list<array{roster_slot:string,slot_index:int}> $plan
     * @param list<array{roster_slot:string,slot_index:int,player_id:?string}> $prior
     * @return list<array{roster_slot:string,slot_index:int,player_id:?string}>
     */
    private function carryForward(array $plan, array $prior): array
    {
        $priorByKey = [];
        foreach ($prior as $p) {
            $priorByKey[$p['roster_slot'] . ':' . $p['slot_index']] = $p['player_id'];
        }
        $slots = [];
        foreach ($plan as $s) {
            $slots[] = $s + ['player_id' => $priorByKey[$s['roster_slot'] . ':' . $s['slot_index']] ?? null];
        }

        return $slots;
    }

    /**
     * Fill required slots with best-available rostered Players by search rank,
     * FLEX from leftover RB/WR/TE. Reuses the ADR-0007 ranking source.
     *
     * @param list<array{roster_slot:string,slot_index:int}> $plan
     * @return list<array{roster_slot:string,slot_index:int,player_id:?string}>
     */
    private function autoFill(array $plan, int $seasonId, int $teamId): array
    {
        // Roster players for this team, with position, best-rank first.
        $byPos = ['QB' => [], 'RB' => [], 'WR' => [], 'TE' => [], 'K' => [], 'DEF' => []];
        foreach ($this->rosters->byTeam($seasonId)[$teamId] ?? [] as $p) {
            $byPos[$p['position']][] = $p['player_id']; // byTeam() already orders sensibly
        }
        $used = [];
        $slots = [];
        foreach ($plan as $s) {
            $pick = $this->takeBest($s['roster_slot'], $byPos, $used);
            if ($pick !== null) {
                $used[$pick] = true;
            }
            $slots[] = $s + ['player_id' => $pick];
        }

        return $slots;
    }

    /**
     * @param array<string,list<string>> $byPos
     * @param array<string,bool> $used
     */
    private function takeBest(string $slot, array $byPos, array $used): ?string
    {
        $pools = $slot === 'FLEX' ? self::FLEX_ELIGIBLE : [$slot];
        foreach ($pools as $pos) {
            foreach ($byPos[$pos] ?? [] as $pid) {
                if (!isset($used[$pid])) {
                    return $pid;
                }
            }
        }

        return null;
    }
}
```

Note: verify `RosterRepository::byTeam()` returns `position` and `player_id` and is ordered by rank/position (it orders by `FIELD(position,...)` then name — acceptable; if best-by-rank matters, add rank to its SELECT/ORDER or read `search_rank` here). Keep auto-fill deterministic.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/LineupServiceTest.php`
Expected: PASS (both).

- [ ] **Step 7: Commit**

```bash
git add src/PlayerWeekStatsRepository.php src/LineupRepository.php src/Lineup/LineupService.php tests/LineupServiceTest.php
git commit -m "feat: Wave 3 slice 4 — lineup carry-forward and week-1 auto-fill"
```

### Task 10: Save a Lineup with legality validation

**Files:**
- Modify: `src/Lineup/LineupService.php`
- Create: `src/Lineup/LineupException.php`
- Test: `tests/LineupServiceTest.php` (add cases)

**Interfaces:**
- Produces: `LineupService::saveLineup(int $leagueId, int $seasonId, int $week, int $teamId, array $assignments): void` where `$assignments` is `list<array{roster_slot:string,slot_index:int,player_id:?string}>`. Validates: every non-null player is on the Team's roster; no player appears twice; each player's position is eligible for its slot (exact for QB/RB/WR/TE/K/DEF, `{RB,WR,TE}` for FLEX); the assignment covers exactly the slot plan. Throws `FFB\Lineup\LineupException` (HTTP-style `int $status`, `string $message` like `DraftPickException`) on any violation.

- [ ] **Step 1: Write the failing tests**

```php
    public function testSavingRejectsAPlayerNotOnTheRoster(): void
    {
        $team = $this->seedRosteredTeam();
        $this->expectException(\FFB\Lineup\LineupException::class);
        $this->service()->saveLineup($this->leagueId(), $this->seasonId(), 1, $team, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'NOT_ON_ROSTER'],
        ]);
    }

    public function testSavingRejectsWrongPositionForSlot(): void
    {
        $team = $this->seedRosteredTeam(); // WR1 is a WR
        $this->expectException(\FFB\Lineup\LineupException::class);
        $this->service()->saveLineup($this->leagueId(), $this->seasonId(), 1, $team, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'WR1'],
        ]);
    }
```

- [ ] **Step 2: Run and verify they fail**

Run: `vendor/bin/phpunit tests/LineupServiceTest.php`
Expected: FAIL — `saveLineup`/`LineupException` don't exist.

- [ ] **Step 3: Create LineupException**

```php
<?php

declare(strict_types=1);

namespace FFB\Lineup;

use RuntimeException;

/**
 * Thrown when a Lineup save is illegal (unrostered/ineligible/duplicate player)
 * or the week is locked. Carries an HTTP-style status, mirroring DraftPickException.
 */
final class LineupException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 4: Implement saveLineup**

```php
    /**
     * @param list<array{roster_slot:string,slot_index:int,player_id:?string}> $assignments
     */
    public function saveLineup(int $leagueId, int $seasonId, int $week, int $teamId, array $assignments): void
    {
        $rosterPos = [];
        foreach ($this->rosters->byTeam($seasonId)[$teamId] ?? [] as $p) {
            $rosterPos[$p['player_id']] = $p['position'];
        }

        $seen = [];
        foreach ($assignments as $a) {
            $pid = $a['player_id'];
            if ($pid === null) {
                continue;
            }
            if (!isset($rosterPos[$pid])) {
                throw new LineupException(422, 'That player is not on your roster.');
            }
            if (isset($seen[$pid])) {
                throw new LineupException(422, 'A player can only start in one slot.');
            }
            $seen[$pid] = true;
            if (!$this->eligible($a['roster_slot'], $rosterPos[$pid])) {
                throw new LineupException(422, "That player can't start at {$a['roster_slot']}.");
            }
        }

        $this->lineups->replaceForTeamWeek($leagueId, $seasonId, $week, $teamId, $assignments);
    }

    private function eligible(string $slot, string $position): bool
    {
        return $slot === 'FLEX'
            ? in_array($position, self::FLEX_ELIGIBLE, true)
            : $slot === $position;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/LineupServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Lineup/LineupService.php src/Lineup/LineupException.php tests/LineupServiceTest.php
git commit -m "feat: Wave 3 slice 4 — validate and save lineups"
```

---

## Slice 5 — Matchup scoring & standings

Delivers cached Matchup scores from lineups × stats, and the seed-ordered Standings.

### Task 11: MatchupScoringService — recompute a week's cached scores

**Files:**
- Create: `src/Scoring/MatchupScoringService.php`
- Modify: `src/MatchupRepository.php` (add `updateScores`)
- Test: `tests/MatchupScoringTest.php`

**Interfaces:**
- `MatchupRepository::updateScores(int $matchupId, float $homeScore, float $awayScore, string $status): void`.
- `FFB\Scoring\MatchupScoringService::scoreWeek(int $leagueId, int $seasonId, int $week, string $status): void` — for each Matchup that week: sum each Team's started players' points (resolved stat line via `PlayerWeekStatsRepository::resolvedForWeek`, scored by `ScoringEngine`), write `home_score`/`away_score` and `status` onto the row. `$status` is `'live'` (from live cron) or `'final'` (from settlement).
- Consumes: `LineupRepository::startersForWeek`, `PlayerWeekStatsRepository::resolvedForWeek`, `ScoringEngine::pointsFor`, `LeagueSettingsRepository::all`, `MatchupRepository::forWeek`+`updateScores`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Tests\Support\DatabaseTestCase;

final class MatchupScoringTest extends DatabaseTestCase
{
    public function testScoresAMatchupFromStartersAndStats(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();

        // Two teams, one QB starter each.
        $home = $this->makeTeam($leagueId, $seasonId, 'Home');
        $away = $this->makeTeam($leagueId, $seasonId, 'Away');
        (new PlayerRepository($this->pdo))->upsert('HQB', null, 'Home QB', 'QB', 'KC', 'Active', 1);
        (new PlayerRepository($this->pdo))->upsert('AQB', null, 'Away QB', 'QB', 'KC', 'Active', 1);

        $lineups = new LineupRepository($this->pdo);
        $lineups->replaceForTeamWeek($leagueId, $seasonId, 1, $home, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'HQB'],
        ]);
        $lineups->replaceForTeamWeek($leagueId, $seasonId, 1, $away, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'AQB'],
        ]);

        $stats = new PlayerWeekStatsRepository($this->pdo);
        $stats->upsert($seasonId, 1, 'HQB', 'sleeper', ['pass_yard' => 300, 'pass_td' => 2]); // 12 + 8 = 20.0
        $stats->upsert($seasonId, 1, 'AQB', 'sleeper', ['pass_yard' => 100]);                  // 4.0

        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id) VALUES (?,?,?,?,?)'
        )->execute([$leagueId, $seasonId, 1, $home, $away]);

        $this->service()->scoreWeek($leagueId, $seasonId, 1, 'live');

        $row = $this->pdo->query('SELECT home_score, away_score, status FROM matchups')->fetch();
        $this->assertSame('20.00', $row['home_score']);
        $this->assertSame('4.00', $row['away_score']);
        $this->assertSame('live', $row['status']);
    }

    private function makeTeam(int $leagueId, int $seasonId, string $name): int
    {
        return (new \FFB\TeamRepository($this->pdo))->create($leagueId, $seasonId, $name);
    }

    private function service(): MatchupScoringService
    {
        return new MatchupScoringService(
            new MatchupRepository($this->pdo),
            new LineupRepository($this->pdo),
            new PlayerWeekStatsRepository($this->pdo),
            new \FFB\Scoring\ScoringEngine(),
            new \FFB\LeagueSettingsRepository($this->pdo),
        );
    }
}
```

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/MatchupScoringTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Add `MatchupRepository::updateScores`**

```php
    public function updateScores(int $matchupId, float $homeScore, float $awayScore, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE matchups SET home_score = ?, away_score = ?, status = ? WHERE id = ?'
        )->execute([$homeScore, $awayScore, $status, $matchupId]);
    }
```

- [ ] **Step 4: Implement MatchupScoringService**

```php
<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerWeekStatsRepository;

/**
 * Recomputes the cached score of every Matchup in a week from each Team's
 * started Players and their resolved stat lines. Called by the Live cron
 * (status 'live') and by settlement (status 'final').
 */
final class MatchupScoringService
{
    public function __construct(
        private readonly MatchupRepository $matchups,
        private readonly LineupRepository $lineups,
        private readonly PlayerWeekStatsRepository $stats,
        private readonly ScoringEngine $engine,
        private readonly LeagueSettingsRepository $settings,
    ) {
    }

    public function scoreWeek(int $leagueId, int $seasonId, int $week, string $status): void
    {
        $settings = $this->settings->all($leagueId, $seasonId);
        $statLines = $this->stats->resolvedForWeek($seasonId, $week);
        $starters = $this->lineups->startersForWeek($seasonId, $week);

        foreach ($this->matchups->forWeek($seasonId, $week) as $m) {
            $home = $this->teamPoints((int) $m['home_team_id'], $starters, $statLines, $settings);
            $away = $this->teamPoints((int) $m['away_team_id'], $starters, $statLines, $settings);
            $this->matchups->updateScores((int) $m['id'], $home, $away, $status);
        }
    }

    /**
     * @param array<int, list<array{roster_slot:string,player_id:string}>> $starters
     * @param array<string, array<string,float>> $statLines
     * @param array<string,string> $settings
     */
    private function teamPoints(int $teamId, array $starters, array $statLines, array $settings): float
    {
        $total = 0.0;
        foreach ($starters[$teamId] ?? [] as $s) {
            $line = $statLines[$s['player_id']] ?? [];
            $total += $this->engine->pointsFor($line, $settings);
        }

        return round($total, 2);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/MatchupScoringTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Scoring/MatchupScoringService.php src/MatchupRepository.php tests/MatchupScoringTest.php
git commit -m "feat: Wave 3 slice 5 — recompute cached matchup scores"
```

### Task 12: Lineup lock at first kickoff

**Files:**
- Modify: `src/Lineup/LineupService.php` (enforce lock in `saveLineup`), add a lock-time source.
- Create: `src/Lineup/WeekLock.php` — resolves a week's lock time; Wave 3 uses a settings-driven per-week kickoff time (`schedule.week_<n>_kickoff`, ISO 8601), falling back to "unlocked" when unset so tests and pre-season editing work.
- Test: `tests/LineupServiceTest.php` (add lock cases with an injected clock)

**Interfaces:**
- `FFB\Lineup\WeekLock::isLocked(int $seasonId, int $week, int $now): bool` — reads `schedule.week_<week>_kickoff` from settings; locked when `now >= kickoff`. No setting → not locked.
- `LineupService::saveLineup(...)` gains lock enforcement: throw `LineupException(423, 'Lineups are locked for this week.')` when `WeekLock::isLocked` is true. Inject `WeekLock` and a `now` provider (a `callable(): int` defaulting to `time()`) so tests control the clock.

- [ ] **Step 1: Write the failing test**

```php
    public function testSavingAfterKickoffIsRejected(): void
    {
        $team = $this->seedRosteredTeam();
        // Set week-1 kickoff in the past.
        (new \FFB\LeagueSettingsRepository($this->pdo))->setMany($this->leagueId(), $this->seasonId(), [
            'schedule.week_1_kickoff' => '2020-09-10T20:20:00+00:00',
        ]);

        $this->expectException(\FFB\Lineup\LineupException::class);
        $this->service()->saveLineup($this->leagueId(), $this->seasonId(), 1, $team, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'QB1'],
        ]);
    }
```

(Update the `service()` helper to construct `LineupService` with a `WeekLock` and a `now` provider returning a fixed present time, e.g. `fn () => strtotime('2026-09-10')`.)

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/LineupServiceTest.php`
Expected: FAIL — lock not enforced (save succeeds).

- [ ] **Step 3: Implement WeekLock**

```php
<?php

declare(strict_types=1);

namespace FFB\Lineup;

use FFB\LeagueSettingsRepository;

/**
 * Resolves whether a week's Lineups are locked. Wave 3 locks the whole Lineup at
 * the week's first NFL kickoff, stored as schedule.week_<n>_kickoff (ISO 8601).
 * When no kickoff is configured the week is treated as unlocked (pre-season and
 * tests can edit freely).
 */
final class WeekLock
{
    public function __construct(private readonly LeagueSettingsRepository $settings, private readonly int $leagueId, private readonly int $seasonId)
    {
    }

    public function isLocked(int $week, int $now): bool
    {
        $all = $this->settings->all($this->leagueId, $this->seasonId);
        $kickoff = $all['schedule.week_' . $week . '_kickoff'] ?? null;
        if ($kickoff === null || $kickoff === '') {
            return false;
        }

        return $now >= strtotime($kickoff);
    }
}
```

Note: passing `leagueId`/`seasonId` into the constructor keeps `isLocked` clean; alternatively pass them per call. Match whichever the Kernel wiring prefers — keep the signature used here consistent across Task 13's controller.

- [ ] **Step 4: Enforce the lock in saveLineup**

Add `WeekLock $lock` and `\Closure $now` (or `callable`) to the constructor (default `$now = 'time'`). At the top of `saveLineup`, before validation:

```php
        if ($this->lock->isLocked($week, ($this->now)())) {
            throw new LineupException(423, 'Lineups are locked for this week.');
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/LineupServiceTest.php`
Expected: PASS (including earlier lineup tests — ensure their `service()` uses an unset kickoff so they stay unlocked).

- [ ] **Step 6: Commit**

```bash
git add src/Lineup/WeekLock.php src/Lineup/LineupService.php tests/LineupServiceTest.php
git commit -m "feat: Wave 3 slice 5 — lock lineups at kickoff"
```

### Task 13: StandingsService — seed-ordered standings

**Files:**
- Create: `src/StandingsService.php`
- Test: `tests/StandingsServiceTest.php`

**Interfaces:**
- `FFB\StandingsService::compute(int $seasonId): array` → `list<array{team_id:int,wins:int,losses:int,ties:int,points_for:float,win_pct:float}>` ordered by `win_pct` desc, then `points_for` desc, then `team_id` asc (deterministic). Only `final` Matchups count toward W/L/T; `points_for` sums the Team's own score across `final` matchups (both live and final carry a cached score, but standings use settled results only). A tie = equal `home_score`/`away_score`; counts half a win in `win_pct` (`(wins + 0.5*ties) / games`).
- Consumes: reads `matchups` directly (aggregate query) via a small repository method or inline PDO in the service — keep SQL in the service acceptable here since it is a read-only aggregate, matching the codebase's pragmatism; or add `MatchupRepository::finalForSeason(int $seasonId): array`. Prefer the repository method for consistency.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class StandingsServiceTest extends DatabaseTestCase
{
    public function testRanksByRecordThenPointsFor(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        $teams = new TeamRepository($this->pdo);
        $a = $teams->create($leagueId, $seasonId, 'A');
        $b = $teams->create($leagueId, $seasonId, 'B');
        $c = $teams->create($leagueId, $seasonId, 'C');

        // A beats B (final); C ties A (final).
        $this->finalMatchup($leagueId, $seasonId, 1, $a, $b, 100, 90);
        $this->finalMatchup($leagueId, $seasonId, 2, $a, $c, 80, 80);

        $rows = (new StandingsService($this->pdo))->compute($seasonId);

        // A: 1-0-1 win_pct .75, pf 180; C: 0-0-1 .5, pf 80; B: 0-1-0 0, pf 90.
        $this->assertSame($a, $rows[0]['team_id']);
        $this->assertSame(1, $rows[0]['wins']);
        $this->assertSame(1, $rows[0]['ties']);
        $this->assertSame(180.0, $rows[0]['points_for']);
        $this->assertSame($c, $rows[1]['team_id']); // .5 beats B's 0
        $this->assertSame($b, $rows[2]['team_id']);
    }

    private function finalMatchup(int $lid, int $sid, int $week, int $home, int $away, float $hs, float $as): void
    {
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,?,?,?,?,?,'final')"
        )->execute([$lid, $sid, $week, $home, $away, $hs, $as]);
    }
}
```

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/StandingsServiceTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement StandingsService**

```php
<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Computes seed-ordered Standings from settled (final) Matchups: record (win%,
 * a tie = half a win) then total points scored, then team id as a deterministic
 * final tiebreaker. No head-to-head. Feeds the (future) Playoff seeding.
 */
final class StandingsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{team_id:int,wins:int,losses:int,ties:int,points_for:float,win_pct:float}>
     */
    public function compute(int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT home_team_id, away_team_id, home_score, away_score'
            . " FROM matchups WHERE season_id = ? AND status = 'final'"
        );
        $stmt->execute([$seasonId]);

        /** @var array<int,array{wins:int,losses:int,ties:int,points_for:float}> $acc */
        $acc = [];
        $bump = static function (array &$acc, int $team): void {
            $acc[$team] ??= ['wins' => 0, 'losses' => 0, 'ties' => 0, 'points_for' => 0.0];
        };

        foreach ($stmt->fetchAll() as $m) {
            $home = (int) $m['home_team_id'];
            $away = (int) $m['away_team_id'];
            $hs = (float) $m['home_score'];
            $as = (float) $m['away_score'];
            $bump($acc, $home);
            $bump($acc, $away);
            $acc[$home]['points_for'] += $hs;
            $acc[$away]['points_for'] += $as;

            if ($hs > $as) {
                $acc[$home]['wins']++;
                $acc[$away]['losses']++;
            } elseif ($as > $hs) {
                $acc[$away]['wins']++;
                $acc[$home]['losses']++;
            } else {
                $acc[$home]['ties']++;
                $acc[$away]['ties']++;
            }
        }

        $rows = [];
        foreach ($acc as $teamId => $r) {
            $games = $r['wins'] + $r['losses'] + $r['ties'];
            $winPct = $games > 0 ? ($r['wins'] + 0.5 * $r['ties']) / $games : 0.0;
            $rows[] = [
                'team_id' => $teamId,
                'wins' => $r['wins'], 'losses' => $r['losses'], 'ties' => $r['ties'],
                'points_for' => round($r['points_for'], 2),
                'win_pct' => round($winPct, 4),
            ];
        }

        usort($rows, static function ($a, $b) {
            return [$b['win_pct'], $b['points_for'], $a['team_id']]
                <=> [$a['win_pct'], $a['points_for'], $b['team_id']];
        });

        return $rows;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/StandingsServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/StandingsService.php tests/StandingsServiceTest.php
git commit -m "feat: Wave 3 slice 5 — seed-ordered standings"
```

---

## Slice 6 — Stats ingestion & settlement (crons)

Delivers the two background jobs: Sleeper live scoring during games, nflverse official settlement that locks the week.

### Task 14: StatsImporter + SleeperStatsClient (live ingest)

**Files:**
- Create: `src/Scoring/SleeperStatsClient.php`, `src/Scoring/StatsImporter.php`
- Test: `tests/StatsImporterTest.php`, fixture `tests/fixtures/sleeper_week_stats.json`

**Interfaces:**
- `FFB\Scoring\SleeperStatsClient::fetchWeek(int $season, int $week): array` → `array<string, array<string,float>>` map `sleeper_player_id => normalized stat line` (keys already normalized to the scoring stat names: `pass_yard, pass_td, ...`, `def_points_allowed`, etc.). Real HTTP fetch modeled on `src/Players/SleeperClient.php`; keep the raw→normalized field mapping in a private method with the Sleeper field names.
- `FFB\Scoring\StatsImporter::importSleeper(int $seasonId, int $week, array $lines): int` — upserts each line into `player_week_stats` with `source='sleeper'`, returns count written. Only players present in `players` are written (skip unknown ids). Returns number upserted.
- Consumes: `PlayerWeekStatsRepository::upsert`, `PlayerRepository` (existence check).
- Note: read `src/Players/SleeperClient.php` and `src/Players/RemoteFile.php` to reuse the HTTP fetch pattern (don't hand-roll curl). The importer is tested with an injected array of lines (no network); the client's live fetch is exercised manually via the cron.

- [ ] **Step 1: Write the failing test (importer, no network)**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\StatsImporter;
use FFB\Tests\Support\DatabaseTestCase;

final class StatsImporterTest extends DatabaseTestCase
{
    public function testImportsKnownPlayersAndSkipsUnknown(): void
    {
        $seasonId = (new LeagueRepository($this->pdo))->currentSeasonId();
        (new PlayerRepository($this->pdo))->upsert('KNOWN', null, 'Known', 'QB', 'KC', 'Active', 1);

        $importer = new StatsImporter(new PlayerWeekStatsRepository($this->pdo), new PlayerRepository($this->pdo));
        $written = $importer->importSleeper($seasonId, 1, [
            'KNOWN' => ['pass_yard' => 250, 'pass_td' => 2],
            'GHOST' => ['pass_yard' => 999], // not in players -> skipped
        ]);

        $this->assertSame(1, $written);
        $resolved = (new PlayerWeekStatsRepository($this->pdo))->resolvedForWeek($seasonId, 1);
        $this->assertArrayHasKey('KNOWN', $resolved);
        $this->assertArrayNotHasKey('GHOST', $resolved);
    }
}
```

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/StatsImporterTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 3: Implement StatsImporter**

```php
<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;

/**
 * Writes normalized weekly stat lines into player_week_stats. Sleeper lines are
 * keyed by sleeper_id directly; nflverse lines are keyed by gsis id and mapped
 * back to sleeper_id via the Player crosswalk (importNflverse, Task 15).
 */
final class StatsImporter
{
    public function __construct(
        private readonly PlayerWeekStatsRepository $stats,
        private readonly PlayerRepository $players,
    ) {
    }

    /**
     * @param array<string, array<string,float>> $lines sleeper_id => stat line
     */
    public function importSleeper(int $seasonId, int $week, array $lines): int
    {
        $written = 0;
        foreach ($lines as $sleeperId => $line) {
            if (!$this->players->exists((string) $sleeperId)) {
                continue;
            }
            $this->stats->upsert($seasonId, $week, (string) $sleeperId, 'sleeper', $line);
            $written++;
        }

        return $written;
    }
}
```

- [ ] **Step 4: Add `PlayerRepository::exists` if missing**

Read `src/PlayerRepository.php`. If there's no existence check, add:

```php
public function exists(string $sleeperId): bool
{
    $stmt = $this->pdo->prepare('SELECT 1 FROM players WHERE sleeper_id = ? LIMIT 1');
    $stmt->execute([$sleeperId]);

    return (bool) $stmt->fetchColumn();
}
```

(If a suitable method like `isDraftable`/`find` exists, prefer a dedicated `exists` for clarity.)

- [ ] **Step 5: Implement SleeperStatsClient**

Model on `src/Players/SleeperClient.php`. Fetch `https://api.sleeper.app/v1/stats/nfl/regular/{season}/{week}` (verify the exact endpoint against Sleeper docs during implementation), decode JSON, and normalize each player's raw stat fields to the scoring stat names in a private `normalize(array $raw): array`. Return `array<string, array<string,float>>`. Keep it thin — no DB.

```php
<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\Players\RemoteFile;

/**
 * Fetches Sleeper's weekly player stats (the Live feed) and normalizes each
 * player's raw fields to the scoring stat names. Sleeper keys by sleeper_id.
 */
final class SleeperStatsClient
{
    public function __construct(private readonly RemoteFile $http = new RemoteFile())
    {
    }

    /**
     * @return array<string, array<string,float>> sleeper_id => normalized stat line
     */
    public function fetchWeek(int $season, int $week): array
    {
        $url = "https://api.sleeper.app/v1/stats/nfl/regular/{$season}/{$week}";
        $raw = json_decode($this->http->get($url), true) ?: [];

        $out = [];
        foreach ($raw as $sleeperId => $fields) {
            $out[(string) $sleeperId] = $this->normalize((array) $fields);
        }

        return $out;
    }

    /**
     * Map Sleeper's stat field names to our scoring stat names. Only the fields
     * the scoring config consumes are kept.
     *
     * @param array<string,mixed> $raw
     * @return array<string,float>
     */
    private function normalize(array $raw): array
    {
        $map = [
            'rec' => 'reception', 'pass_yd' => 'pass_yard', 'pass_td' => 'pass_td',
            'pass_int' => 'pass_int', 'rush_yd' => 'rush_yard', 'rush_td' => 'rush_td',
            'rec_yd' => 'rec_yard', 'rec_td' => 'rec_td', 'fum_lost' => 'fumble_lost',
            'fgm' => 'fg_made', 'xpm' => 'xp_made',
            'sack' => 'def_sack', 'int' => 'def_int', 'fum_rec' => 'def_fumble_rec',
            'def_td' => 'def_td', 'safe' => 'def_safety', 'pts_allow' => 'def_points_allowed',
        ];
        $line = [];
        foreach ($map as $from => $to) {
            if (isset($raw[$from])) {
                $line[$to] = (float) $raw[$from];
            }
        }

        return $line;
    }
}
```

Note: the exact Sleeper field names above must be verified against the live payload during implementation (Sleeper's stat keys are documented informally). Treat the `$map` as the single place to correct them; the importer and scorer are agnostic. If `RemoteFile` has no `get()`, use its actual fetch method (check `src/Players/RemoteFile.php`).

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/StatsImporterTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Scoring/StatsImporter.php src/Scoring/SleeperStatsClient.php src/PlayerRepository.php tests/StatsImporterTest.php
git commit -m "feat: Wave 3 slice 6 — sleeper live stats ingestion"
```

### Task 15: nflverse official ingest via crosswalk + SettlementService

**Files:**
- Create: `src/Scoring/NflverseStatsClient.php`, `src/Scoring/SettlementService.php`
- Modify: `src/Scoring/StatsImporter.php` (add `importNflverse`), `src/MatchupRepository.php` (add `settleWeek`)
- Test: `tests/SettlementServiceTest.php`

**Interfaces:**
- `StatsImporter::importNflverse(int $seasonId, int $week, array $lines): int` — `$lines` keyed by **gsis id**; map gsis → sleeper_id via `PlayerRepository::sleeperIdForNflverseId(string $gsisId): ?string`; upsert with `source='nflverse'`; skip unmapped. Returns count.
- `PlayerRepository::sleeperIdForNflverseId(string $gsisId): ?string` (add; the `players` table already carries `nflverse_id`).
- `MatchupRepository::settleWeek(int $seasonId, int $week): void` — set `status='final'` for that week's matchups (scores already written by `MatchupScoringService::scoreWeek(..., 'final')`).
- `FFB\Scoring\SettlementService::settleWeek(int $leagueId, int $seasonId, int $week, array $nflverseLines): void` — import official lines, rescore the week as `final`, mark matchups final (locks the week). Standings recompute is implicit (StandingsService reads final matchups).
- `FFB\Scoring\NflverseStatsClient::fetchWeek(int $season, int $week): array` — download the nflverse weekly stats CSV, parse to `array<string, array<string,float>>` keyed by gsis id, normalized to scoring stat names. Model the download on `src/Players/PlayerIdCrosswalk.php`/`RemoteFile` (both already fetch CSVs).

- [ ] **Step 1: Write the failing test (settlement changes result + locks)**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LineupRepository;
use FFB\PlayerRepository;
use FFB\Scoring\SettlementService;
use FFB\Tests\Support\DatabaseTestCase;

final class SettlementServiceTest extends DatabaseTestCase
{
    public function testOfficialStatsSettleAndCanFlipTheResult(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();

        $home = (new \FFB\TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Home');
        $away = (new \FFB\TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Away');
        // Players carry an nflverse_id so official lines map back.
        (new PlayerRepository($this->pdo))->upsert('HQB', 'GSIS_H', 'Home QB', 'QB', 'KC', 'Active', 1);
        (new PlayerRepository($this->pdo))->upsert('AQB', 'GSIS_A', 'Away QB', 'QB', 'KC', 'Active', 1);

        $lineups = new LineupRepository($this->pdo);
        $lineups->replaceForTeamWeek($leagueId, $seasonId, 1, $home, [['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'HQB']]);
        $lineups->replaceForTeamWeek($leagueId, $seasonId, 1, $away, [['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'AQB']]);

        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id) VALUES (?,?,?,?,?)'
        )->execute([$leagueId, $seasonId, 1, $home, $away]);

        // Official: away outscores home.
        $service = $this->makeSettlement();
        $service->settleWeek($leagueId, $seasonId, 1, [
            'GSIS_H' => ['pass_yard' => 100],           // 4.0
            'GSIS_A' => ['pass_yard' => 300, 'pass_td' => 2], // 20.0
        ]);

        $row = $this->pdo->query('SELECT home_score, away_score, status FROM matchups')->fetch();
        $this->assertSame('final', $row['status']);
        $this->assertSame('4.00', $row['home_score']);
        $this->assertSame('20.00', $row['away_score']);
    }

    private function makeSettlement(): SettlementService
    {
        // Build with the same collaborators Kernel will wire.
        $stats = new \FFB\PlayerWeekStatsRepository($this->pdo);
        $players = new PlayerRepository($this->pdo);
        $importer = new \FFB\Scoring\StatsImporter($stats, $players);
        $scoring = new \FFB\Scoring\MatchupScoringService(
            new \FFB\MatchupRepository($this->pdo),
            new \FFB\LineupRepository($this->pdo),
            $stats,
            new \FFB\Scoring\ScoringEngine(),
            new \FFB\LeagueSettingsRepository($this->pdo),
        );
        return new SettlementService($importer, $scoring, new \FFB\MatchupRepository($this->pdo));
    }
}
```

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/SettlementServiceTest.php`
Expected: FAIL — classes/methods missing.

- [ ] **Step 3: Add `PlayerRepository::sleeperIdForNflverseId`**

```php
public function sleeperIdForNflverseId(string $gsisId): ?string
{
    $stmt = $this->pdo->prepare('SELECT sleeper_id FROM players WHERE nflverse_id = ? LIMIT 1');
    $stmt->execute([$gsisId]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (string) $id;
}
```

- [ ] **Step 4: Add `StatsImporter::importNflverse`**

```php
    /**
     * @param array<string, array<string,float>> $lines gsis_id => stat line
     */
    public function importNflverse(int $seasonId, int $week, array $lines): int
    {
        $written = 0;
        foreach ($lines as $gsisId => $line) {
            $sleeperId = $this->players->sleeperIdForNflverseId((string) $gsisId);
            if ($sleeperId === null) {
                continue; // Unmatched Player — surfaced elsewhere (ADR-0004)
            }
            $this->stats->upsert($seasonId, $week, $sleeperId, 'nflverse', $line);
            $written++;
        }

        return $written;
    }
```

- [ ] **Step 5: Add `MatchupRepository::settleWeek`**

```php
    public function settleWeek(int $seasonId, int $week): void
    {
        $this->pdo->prepare(
            "UPDATE matchups SET status = 'final' WHERE season_id = ? AND week = ?"
        )->execute([$seasonId, $week]);
    }
```

- [ ] **Step 6: Implement SettlementService**

```php
<?php

declare(strict_types=1);

namespace FFB\Scoring;

use FFB\MatchupRepository;

/**
 * Settles a week to its Official result (ADR-0005): ingest nflverse lines,
 * rescore every Matchup from the now-Official stats, and mark the week final —
 * which locks it. Settlement may change a result; Standings recompute because
 * StandingsService reads only final Matchups.
 */
final class SettlementService
{
    public function __construct(
        private readonly StatsImporter $importer,
        private readonly MatchupScoringService $scoring,
        private readonly MatchupRepository $matchups,
    ) {
    }

    /**
     * @param array<string, array<string,float>> $nflverseLines gsis_id => stat line
     */
    public function settleWeek(int $leagueId, int $seasonId, int $week, array $nflverseLines): void
    {
        $this->importer->importNflverse($seasonId, $week, $nflverseLines);
        $this->scoring->scoreWeek($leagueId, $seasonId, $week, 'final');
        $this->matchups->settleWeek($seasonId, $week);
    }
}
```

- [ ] **Step 7: Implement NflverseStatsClient**

Model the CSV download on `src/Players/PlayerIdCrosswalk.php`. Fetch the nflverse weekly player-stats CSV (confirm the release URL/columns during implementation), parse rows, key by the gsis id column, normalize columns to scoring stat names in a private `normalize()`, return `array<string, array<string,float>>`. No DB. Keep the column map as the single correction point, as with Sleeper.

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/SettlementServiceTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Scoring/NflverseStatsClient.php src/Scoring/SettlementService.php src/Scoring/StatsImporter.php src/MatchupRepository.php src/PlayerRepository.php tests/SettlementServiceTest.php
git commit -m "feat: Wave 3 slice 6 — nflverse official settlement"
```

### Task 16: Cron entrypoints

**Files:**
- Create: `cron/live_scores.php`, `cron/settle_official.php`
- Modify: `DEPLOY.md` (document the two new cron jobs and cadence), `cron` scheduling notes.
- Test: manual (cron scripts are thin wiring; the services they call are covered). Optionally a smoke test that `require`-ing the file with a stub config doesn't fatal — skip if it needs network.

**Interfaces:**
- `cron/live_scores.php` — resolve current league/season, current NFL week (a `schedule.current_week` setting, Commissioner-set — add reading it; default derive is out of scope), fetch Sleeper stats, `importSleeper`, then `MatchupScoringService::scoreWeek(..., 'live')`.
- `cron/settle_official.php` — resolve league/season and the week to settle (`schedule.current_week - 1`, or a `schedule.settle_week` setting), fetch nflverse CSV, `SettlementService::settleWeek(...)`.

- [ ] **Step 1: Write `cron/live_scores.php`**

```php
<?php

declare(strict_types=1);

/**
 * Live scoring — run frequently during NFL game windows (ICDSoft cron).
 *
 * Fetches Sleeper's weekly stats, upserts the provisional Live lines, and
 * recomputes every Matchup's cached score for the current week (status 'live').
 *
 * Usage: php cron/live_scores.php
 */

use FFB\Database;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\ScoringEngine;
use FFB\Scoring\SleeperStatsClient;
use FFB\Scoring\StatsImporter;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

$settings = new LeagueSettingsRepository($pdo);
$all = $settings->all($leagueId, $seasonId);
$week = (int) ($all['schedule.current_week'] ?? 0);
$season = (int) ($all['schedule.season_year'] ?? date('Y'));
if ($week < 1) {
    fwrite(STDERR, "No current week set (schedule.current_week); nothing to score.\n");
    exit(0);
}

$stats = new PlayerWeekStatsRepository($pdo);
$importer = new StatsImporter($stats, new PlayerRepository($pdo));
$lines = (new SleeperStatsClient())->fetchWeek($season, $week);
$written = $importer->importSleeper($seasonId, $week, $lines);

$scoring = new MatchupScoringService(
    new MatchupRepository($pdo),
    new LineupRepository($pdo),
    $stats,
    new ScoringEngine(),
    $settings,
);
$scoring->scoreWeek($leagueId, $seasonId, $week, 'live');

echo "Live scoring week {$week}: {$written} stat lines, matchups updated.\n";
```

- [ ] **Step 2: Write `cron/settle_official.php`**

```php
<?php

declare(strict_types=1);

/**
 * Official settlement — run daily (ICDSoft cron), a day or two after a week's
 * games. Ingests nflverse official stats for the target week, rescores Matchups
 * as final, and locks the week (ADR-0005). May change a result; Standings then
 * reflect the settled outcome.
 *
 * Usage: php cron/settle_official.php
 */

use FFB\Database;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\NflverseStatsClient;
use FFB\Scoring\ScoringEngine;
use FFB\Scoring\SettlementService;
use FFB\Scoring\StatsImporter;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

$settings = new LeagueSettingsRepository($pdo);
$all = $settings->all($leagueId, $seasonId);
$week = (int) ($all['schedule.settle_week'] ?? ((int) ($all['schedule.current_week'] ?? 1) - 1));
$season = (int) ($all['schedule.season_year'] ?? date('Y'));
if ($week < 1) {
    fwrite(STDERR, "No week to settle yet.\n");
    exit(0);
}

$stats = new PlayerWeekStatsRepository($pdo);
$importer = new StatsImporter($stats, new PlayerRepository($pdo));
$scoring = new MatchupScoringService(
    new MatchupRepository($pdo), new LineupRepository($pdo), $stats, new ScoringEngine(), $settings,
);
$settlement = new SettlementService($importer, $scoring, new MatchupRepository($pdo));

$lines = (new NflverseStatsClient())->fetchWeek($season, $week);
$settlement->settleWeek($leagueId, $seasonId, $week, $lines);

echo "Settled week {$week} to official.\n";
```

- [ ] **Step 3: Document the crons in DEPLOY.md**

Add a "Wave 3 cron jobs" section: `live_scores.php` every 2 minutes during Thu/Sun/Mon game windows; `settle_official.php` daily (e.g. Tue 06:00). Note the `schedule.current_week`, `schedule.settle_week`, `schedule.season_year`, and `schedule.week_<n>_kickoff` settings the Commissioner maintains (a settings UI is a later wave).

- [ ] **Step 4: Sanity-check the scripts parse**

Run: `php -l cron/live_scores.php && php -l cron/settle_official.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Commit**

```bash
git add cron/live_scores.php cron/settle_official.php DEPLOY.md
git commit -m "feat: Wave 3 slice 6 — live + settlement cron entrypoints"
```

---

## Slice 7 — UI (lineup, scoreboard, standings)

Delivers the Manager- and league-facing pages. Follows the existing plain-PHP view + controller + route pattern.

### Task 17: Standings page

**Files:**
- Create: `src/Controllers/StandingsController.php`, `views/standings.php`
- Modify: `src/Kernel.php` (wire controller + `GET /standings`)
- Test: `tests/StandingsHttpTest.php`

**Interfaces:**
- Consumes: `StandingsService::compute`, `TeamRepository` for names.
- Route: `GET /standings` (auth: `authenticated`). Renders a ranked table: rank, team, W-L-T, points-for.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class StandingsHttpTest extends DatabaseTestCase
{
    public function testStandingsPageListsTeamsInSeedOrder(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        $winner = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Winners');
        $loser = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Losers');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,1,?,?,120,80,'final')"
        )->execute([$leagueId, $seasonId, $winner, $loser]);

        $session = new ArraySession(['user_id' => 1, 'role' => 'manager', 'league_id' => $leagueId, 'display_name' => 'M']);
        $response = Kernel::router($this->pdo)->dispatch(new Request('GET', '/standings'), $session);

        $this->assertSame(200, $response->status);
        $body = $response->body;
        $this->assertStringContainsString('Winners', $body);
        // Winners appear before Losers in the rendered table.
        $this->assertLessThan(strpos($body, 'Losers'), strpos($body, 'Winners'));
    }
}
```

(Confirm `Response` exposes `status`/`body` — check `src/Http/Response.php`; adjust property names if different.)

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/StandingsHttpTest.php`
Expected: FAIL — no `/standings` route.

- [ ] **Step 3: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\View;

/**
 * The league Standings page: Teams ranked by record then points, the seed order
 * for the (future) Playoffs.
 */
final class StandingsController
{
    public function __construct(
        private readonly StandingsService $standings,
        private readonly TeamRepository $teams,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $seasonId = $this->leagues->currentSeasonId();
        $rows = $this->standings->compute($seasonId);
        $names = $this->teams->namesForSeason($seasonId); // team_id => name

        return new Response($this->view->render('standings', [
            'rows' => $rows,
            'names' => $names,
        ]));
    }
}
```

(Check how existing controllers build a `Response` and how `View::render` is called — mirror `HomeController`. Add `TeamRepository::namesForSeason(int): array` if absent, keyed `team_id => name`.)

- [ ] **Step 4: Implement the view**

```php
<?php /** @var list<array<string,mixed>> $rows */ /** @var array<int,string> $names */ ?>
<?php $this->layout('layout', ['title' => 'Standings']); ?>
<h1>Standings</h1>
<table class="standings">
    <thead>
        <tr><th>#</th><th>Team</th><th>W-L-T</th><th>Points For</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($names[$r['team_id']] ?? ('Team ' . $r['team_id'])) ?></td>
            <td><?= (int) $r['wins'] ?>-<?= (int) $r['losses'] ?>-<?= (int) $r['ties'] ?></td>
            <td><?= number_format((float) $r['points_for'], 2) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
```

(Match the actual view/layout mechanism — inspect `views/layout.php` and an existing view like `views/home.php` for how `$this->layout(...)` / partials work, and adapt.)

- [ ] **Step 5: Wire the route in Kernel**

```php
$standings = new StandingsController(new StandingsService($pdo), $teams, $leagues, $view);
$router->get('/standings', [$standings, 'index'], 'authenticated');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/StandingsHttpTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/StandingsController.php views/standings.php src/Kernel.php src/TeamRepository.php tests/StandingsHttpTest.php
git commit -m "feat: Wave 3 slice 7 — standings page"
```

### Task 18: Scoreboard (weekly matchups) page

**Files:**
- Create: `src/Controllers/ScoreboardController.php`, `views/scoreboard.php`
- Modify: `src/Kernel.php` (`GET /scoreboard`)
- Test: `tests/ScoreboardHttpTest.php`

**Interfaces:**
- Consumes: `MatchupRepository::forWeek`, `TeamRepository::namesForSeason`, current week from `schedule.current_week` setting (accept `?week=` query override).
- Route: `GET /scoreboard` (auth: `authenticated`). Renders each Matchup's two teams, cached scores, and a **state label** (Scheduled / Live / Final) — honoring ADR-0005's "UI must always label which state a score is in."

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class ScoreboardHttpTest extends DatabaseTestCase
{
    public function testScoreboardLabelsLiveState(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        (new LeagueSettingsRepository($this->pdo))->setMany($leagueId, $seasonId, ['schedule.current_week' => '1']);
        $h = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Home');
        $a = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Away');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,1,?,?,50,40,'live')"
        )->execute([$leagueId, $seasonId, $h, $a]);

        $session = new ArraySession(['user_id' => 1, 'role' => 'manager', 'league_id' => $leagueId, 'display_name' => 'M']);
        $response = Kernel::router($this->pdo)->dispatch(new Request('GET', '/scoreboard'), $session);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsStringIgnoringCase('live', $response->body);
        $this->assertStringContainsString('Home', $response->body);
    }
}
```

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/ScoreboardHttpTest.php`
Expected: FAIL — no route.

- [ ] **Step 3: Implement controller + view + route**

Mirror the Standings controller/view/route. Controller reads `schedule.current_week` (default 1), allows `?week=` override, fetches `MatchupRepository::forWeek`, passes rows + team names + a human state label map (`scheduled`→"Scheduled", `live`→"Live", `final`→"Final"). The view lists each matchup as `Home  home_score — away_score  Away  [state]`, with a week selector. Keep markup consistent with `views/*.php`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/ScoreboardHttpTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/ScoreboardController.php views/scoreboard.php src/Kernel.php tests/ScoreboardHttpTest.php
git commit -m "feat: Wave 3 slice 7 — weekly scoreboard page"
```

### Task 19: Lineup page (set & save)

**Files:**
- Create: `src/Controllers/LineupController.php`, `views/lineup.php`
- Modify: `src/Kernel.php` (`GET /lineup`, `POST /lineup`)
- Test: `tests/LineupHttpTest.php`

**Interfaces:**
- Consumes: `LineupService::ensureLineup` (on GET, so the Manager sees a carried-forward/auto-filled default), `LineupService::slotPlan`, `RosterRepository::byTeam` (to offer eligible bench options per slot), `LineupService::saveLineup` (on POST), `TeamRepository` to resolve the session's Team, `schedule.current_week`.
- Routes: `GET /lineup` and `POST /lineup` (auth: `authenticated`). A Manager edits only their own Team (resolve `team_id` from the session's user, not from input). On lock, `saveLineup` throws `LineupException(423,...)`; the controller catches it and re-renders with the message and read-only slots.
- Note: how a session maps to a Team — inspect `DraftRoomController` for the existing "which Team is this Manager" resolution and reuse it. Do not trust a posted `team_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class LineupHttpTest extends DatabaseTestCase
{
    public function testManagerCanViewTheirCarriedLineup(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();

        // A team owned by a manager user, with one rostered QB. (Mirror how
        // DraftRoomController resolves a session -> team; set up the same link.)
        $teamId = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Mine');
        $userId = $this->linkManagerToTeam($teamId); // helper mirroring existing ownership setup
        (new PlayerRepository($this->pdo))->upsert('QB1', null, 'QB One', 'QB', 'KC', 'Active', 1);
        $this->pdo->prepare('INSERT INTO rosters (league_id, season_id, team_id, player_id) VALUES (?,?,?,?)')
            ->execute([$leagueId, $seasonId, $teamId, 'QB1']);

        $session = new ArraySession(['user_id' => $userId, 'role' => 'manager', 'league_id' => $leagueId, 'display_name' => 'M']);
        $response = Kernel::router($this->pdo)->dispatch(new Request('GET', '/lineup'), $session);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('QB', $response->body);
    }

    // Implement linkManagerToTeam() to match this repo's manager<->team ownership
    // (inspect TeamRepository / how DraftRoomController finds the session's team).
}
```

- [ ] **Step 2: Run and verify it fails**

Run: `vendor/bin/phpunit tests/LineupHttpTest.php`
Expected: FAIL — no `/lineup` route.

- [ ] **Step 3: Implement controller**

Build `LineupController` with `index()` (GET) and `save()` (POST). `index()`: resolve the session's `team_id` (reuse the DraftRoom pattern), read current week, `ensureLineup(...)`, load `forTeamWeek` + `slotPlan` + eligible bench options from `RosterRepository::byTeam`, render `lineup`. `save()`: build `$assignments` from POST (`slot`/`slot_index`/`player_id` fields), call `saveLineup(...)` in a try/catch for `LineupException` (re-render with `$error` and the submitted slots), redirect back to `/lineup` on success. Resolve `team_id` from session only.

- [ ] **Step 4: Implement the view**

`views/lineup.php`: a form POSTing to `/lineup`, one row per slot from `slotPlan`, each a `<select>` of the eligible rostered Players (filter by slot eligibility: exact position, or RB/WR/TE for FLEX) plus an empty option, preselected to the current assignment. Show a locked banner (read-only selects) when the controller passes `locked = true`. Match existing view markup/layout.

- [ ] **Step 5: Wire routes in Kernel**

```php
$lineup = new LineupController(/* LineupService, RosterRepository, TeamRepository, LeagueRepository, LeagueSettingsRepository, View */);
$router->get('/lineup', [$lineup, 'index'], 'authenticated');
$router->post('/lineup', [$lineup, 'save'], 'authenticated');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/LineupHttpTest.php`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS across all tests.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/LineupController.php views/lineup.php src/Kernel.php tests/LineupHttpTest.php
git commit -m "feat: Wave 3 slice 7 — lineup set/save page"
```

### Task 20: Navigation links + ADRs

**Files:**
- Modify: `views/layout.php` (nav links to Scoreboard, Standings, My Lineup), `views/home.php` if it hosts primary nav.
- Create: `docs/adr/0008-lineups-and-weekly-lock.md`, `docs/adr/0009-schedule-generation-and-standings.md`
- Test: none (nav is cosmetic; ADRs are docs). Optionally assert the links render on `/`.

**Interfaces:** none.

- [ ] **Step 1: Add nav links**

In `views/layout.php` (or wherever the authenticated nav lives), add links to `/scoreboard`, `/standings`, `/lineup`, shown to authenticated users. Match existing nav markup.

- [ ] **Step 2: Write ADR-0008 (lineups & weekly lock)**

```markdown
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
```

- [ ] **Step 3: Write ADR-0009 (schedule generation & standings)**

```markdown
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
```

- [ ] **Step 4: Commit**

```bash
git add views/layout.php views/home.php docs/adr/0008-lineups-and-weekly-lock.md docs/adr/0009-schedule-generation-and-standings.md
git commit -m "docs: Wave 3 — navigation + ADR-0008/0009"
```

---

## Self-Review

**Spec coverage (grilled decisions → tasks):**
- Separate `lineups` table keyed (season,week,team,slot) → Task 2, 9. ✓
- Whole-lineup lock at first kickoff → Task 12. ✓
- Raw stat lines, two rows per player-week by source, official-wins resolution → Task 3, 9. ✓
- Schedule generated at Draft completion, round-robin cycled to configurable weeks, byes as absent rows → Task 5, 6. ✓
- Matchups cache scores + status → Task 1, 11. ✓
- Standings: record (win%) → points, ties allowed, no head-to-head → Task 13. ✓
- Simplified K/DEF scoring as new settings → Task 4, 8. ✓
- Live cron + Official settle/lock cron; settlement may change result; standings recompute → Task 14, 15, 16, 13. ✓
- Carry-forward default + Week-1 auto-fill → Task 9. ✓
- Playoffs deferred → stated in header, ADR-0009; no bracket tasks. ✓
- UI labels score state (ADR-0005) → Task 18. ✓
- New ADRs → Task 20. ✓

**Placeholder scan:** Load-bearing algorithms (round-robin, scoring, standings sort, carry-forward, settlement) have full code + tests. UI Tasks 18–19 give one page in full (Standings/lineup) and describe the twin pages by explicit reference to that pattern rather than re-pasting — acceptable because the pattern file exists in-repo; the implementer mirrors it. Two clients (`SleeperStatsClient` full; `NflverseStatsClient` described) depend on external field names that MUST be verified live — flagged in-task as the single correction point, not left as silent TODOs.

**Type consistency:** Stat-line shape `array<string,float>` is uniform across `PlayerWeekStatsRepository::resolvedForWeek`, `ScoringEngine::pointsFor`, `StatsImporter`, `MatchupScoringService`. Slot shape `{roster_slot,slot_index,player_id}` is uniform across `LineupRepository`, `LineupService`. `ScheduleGenerator::generate` output `{week,home_team_id,away_team_id}` matches `MatchupRepository::insertMany` input. Scoring stat names match between the migration seed (Task 4), the engine's `LINEAR` list (Task 7/8), and both clients' normalize maps (Task 14/15).

**Assumptions to verify during implementation (facts, not decisions):**
- `TeamRepository` methods (`idsForSeason`, `namesForSeason`) and the `teams.season_id` column — added/confirmed in-task.
- `PlayerRepository::exists`, `sleeperIdForNflverseId` — added in-task.
- `Response` property names (`status`/`body`) and `View::render`/layout mechanics — mirror existing controllers/views before writing new ones.
- `RemoteFile` fetch method name — confirm before the clients call it.
- Sleeper stats endpoint + field names, nflverse weekly CSV URL + columns — verify against live sources; correct the normalize maps.
- The session→Team ownership resolution — reuse `DraftRoomController`'s existing approach in Task 19.
