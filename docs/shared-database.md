# Shared database (TiDB Cloud)

All three of us use one shared database for the app's data, so a plane, flight or booking
created on one laptop shows up for everyone. Tests never use it.

| Database | Used for | Where |
|---|---|---|
| TiDB Cloud Starter (MySQL-compatible) | The app's data, shared | Cloud, AWS Frankfurt, instance `arcs` |
| SQLite in memory | `php artisan test` | Your laptop (from `phpunit.xml`) |
| Local MySQL `arcs_test` | `composer test:mysql` | Your laptop (from `.env.testing`) |
| MySQL in GitHub Actions | CI checks on every PR | GitHub |

The password and the certificate are **never** committed. Ask Tima for them privately.

## Setup (once per laptop, about 5 minutes)

1. Get the latest dev: `git checkout dev` then `git pull`
2. Keep tests on your local MySQL: `cp .env.testing.example .env.testing`, set `DB_PASSWORD=`
   to your local MySQL password (or leave it empty), then `php artisan key:generate --env=testing`
3. Save the certificate Tima sends you as `C:\softwareProject\certs\tidb-ca.pem`
   (`mkdir -p /c/softwareProject/certs`)
4. Back up your local settings: `cp .env .env.local-backup`
5. In `.env`, change only these lines (host, user and password from Tima):

       DB_CONNECTION=mysql
       DB_HOST=gateway01.eu-central-1.prod.aws.tidbcloud.com
       DB_PORT=4000
       DB_DATABASE=arcs
       DB_USERNAME=<from Tima>
       DB_PASSWORD=<from Tima>
       MYSQL_ATTR_SSL_CA=C:/softwareProject/certs/tidb-ca.pem

6. Check: `php artisan config:clear` then `php artisan db:show`. You should see TiDB,
   database `arcs`, and about 31 tables. On a connection error, send Tima your IP so she can
   allow it (TiDB console, instance `arcs`, Settings, Networking).

Do **not** run `migrate` or `db:seed`. The shared database is already set up.

## Rules

1. **Never** run `migrate:fresh`, `migrate:refresh`, `db:wipe` or `db:seed` against the shared
   database. One of them deletes or duplicates everyone's data.
2. Only migrations that are **merged into dev** are run on the shared database, and only by Tima.
3. To test a new migration on a feature branch, switch to your local database first:
   `cp .env .env.shared-backup`, `cp .env.local-backup .env`, `php artisan config:clear`.
   Switch back with `cp .env.shared-backup .env` and `php artisan config:clear`.
4. Only **one** person runs `php artisan queue:work` at a time. Jobs are shared, so that worker
   sends everyone's emails.
5. `.env.testing` must exist before running tests. `composer test:mysql` also forces
   `DB_HOST=127.0.0.1`, so it can never reach the shared database.
6. Pages are a bit slower than with a local database. That is normal.

## Seed data

The seeders in `database/seeders` are the recipe for a clean copy of the data (Arcs Air, AR,
hub BEY). Day to day nobody runs them. They are used to rebuild the shared database after a
planned reset (announced to all three first), for the demo, for a local database, and for anyone
who clones the repo.

## If something goes wrong

- `SQLSTATE[HY000] [2002]` or timeout: check your internet, then ask Tima to allow your IP.
- SSL / certificate error: check the path in `MYSQL_ATTR_SSL_CA` and that the file exists.
- Wrong data or missing tables: don't fix it with migrations or seeders. Tell the team.
- Back to your local database: `cp .env.local-backup .env` then `php artisan config:clear`.
