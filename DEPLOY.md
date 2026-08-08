# Deploying FFB to ICDSoft

Wave 1 runbook. These are the steps only you can perform (SSH login, Control
Panel, cron). The application itself is built and tested; this stands it up on
your hosting.

> Replace `USER`, `yourdomain.com`, and paths to match your account.

## 1. Enable SSH

Control Panel → **SSH Access** → enable it. Note the **host**, **username**, and
**port**. Then connect:

```
ssh USER@yourdomain.com
```

## 2. Create the MySQL database

Control Panel → **MySQL Databases**:

1. Create a database, e.g. `USER_ffb`.
2. Create a database user and set a password.
3. Grant that user all privileges on the database.

Note the **database name**, **user**, **password**, and **host** (usually
`localhost`).

## 3. Get the code onto the server

From your SSH session:

```
cd ~
git clone https://github.com/mkhall257/FFB.git
cd FFB
```

(Or upload the project folder over SFTP if you prefer not to use git.)

## 4. Install the autoloader (no dev dependencies)

The app has **no runtime dependencies**, but it uses Composer's autoloader:

```
composer install --no-dev --optimize-autoloader
```

If `composer` is not available on the server, run the same command locally and
upload the generated `vendor/` folder.

## 5. Create the config file

```
cp config/config.example.php config/config.php
```

Edit `config/config.php` and fill in the database values from step 2. This file
is git-ignored — never commit real credentials.

## 6. Point the web root at `public/`

Control Panel → **Domains / Subdomains** → set the document root (the
"Directory") for the site to:

```
/home/USER/FFB/public
```

This keeps `config/`, `src/`, `migrations/`, and `vendor/` out of the web. The
included `public/.htaccess` routes all requests to the front controller.

> **If you cannot change the document root:** don't drop the whole project into
> the public web folder — that would expose `config/config.php`. Instead keep
> the project outside the web root and copy only `public/`'s contents in, with
> `require`s adjusted, or ask ICDSoft support to set the directory. Setting the
> document root to `public/` is strongly preferred.

Also confirm the PHP version: Control Panel → **PHP settings** → select **8.3**
or newer.

## 7. Run the database migrations

```
php bin/migrate.php
```

Creates all tables and seeds the League, the 2026 Season, and the default
Half-PPR / standard-roster settings. Safe to re-run (idempotent).

## 8. Create your Commissioner login

```
php bin/create-commissioner.php <username> <password> "Your Name"
```

(Repeat for a backup Commissioner if you want one.)

## 9. Populate players and schedule the sync

Run it once now to fill the player catalog:

```
php cron/sync_players.php
```

You should see something like `upserted 3228 players, 173 unmatched`.

Then Control Panel → **Cron Jobs** → add a **daily** job:

```
/usr/bin/php /home/USER/FFB/cron/sync_players.php
```

(Use the PHP CLI path and absolute project path for your account — daily is
plenty during the preseason.)

## 9a. Wave 3 scoring cron jobs (in-season)

Once the Draft has run and the Schedule exists, two cron jobs keep scores live
and settle them to official:

**Live scoring** — every ~2 minutes during game windows (Thu evening, Sun, Mon
evening). Fetches Sleeper stats and updates the current week's Matchup scores:

```
/usr/bin/php /home/USER/FFB/cron/live_scores.php
```

**Official settlement** — once daily (e.g. Tue 06:00). Ingests nflverse official
stats for the completed week, rescores it as final, and locks it:

```
/usr/bin/php /home/USER/FFB/cron/settle_official.php
```

Both read Commissioner-maintained `league_settings`. Set these from the
**Commissioner tools → Season control** page (`/admin/season`) — no database
editing needed:

- **Start a week** sets `schedule.current_week`, `schedule.season_year`, and that
  week's `schedule.week_<n>_kickoff` (the lineup-lock time, prefilled to the
  coming Thursday 8:20pm league time and editable).
- The **Scoring** and **Roster shape** forms edit the `scoring.*` / `roster.*`
  settings the engine reads.
- `schedule.settle_week` defaults to `current_week - 1`; set it explicitly only
  to re-settle a specific past week.

## 9b. Wave 4 transactions (Add/Drop + Trades)

No new cron or server step — the transaction system is entirely in-app and its
tables (`transactions`, `transaction_items`) are created by the migrations in
step 7 / the re-deploy below. Transactions open automatically once the Draft is
complete. The optional **trade deadline** is set from **Season control**
(`/admin/season`); leave it blank for no deadline. Managers use **Free Agents**
(`/players`), **Trades** (`/trades`), and **Activity** (`/transactions`); the
Commissioner reverses from **Activity** and fixes rosters by hand from **Roster
edit** (`/admin/roster-edit`).

## 10. Verify

1. Visit `https://yourdomain.com/login` and log in as the Commissioner.
2. Open **Commissioner tools** → create your Teams and Manager logins.
3. Open **Unmatched players** → confirm the catalog is populated and the
   unmatched list is short.

## Re-deploying later

```
git pull
composer install --no-dev --optimize-autoloader
php bin/migrate.php   # applies any new migrations; no-op if none
```
