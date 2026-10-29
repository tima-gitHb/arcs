# ARCS — Airline Reservation & Check-in System

ARCS is a staff-only web app for an airline: reservation → check-in → baggage → seat → boarding, in that order.
Four roles (reservation, check-in, gate, manager) each see only their own pages. Built with Laravel 12 + MySQL.

Team project, Software Development course (Antonine University). Rules for the code are in [CLAUDE.md](CLAUDE.md).

---

## Requirements

Install these on Windows before you start:

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2 | Comes with XAMPP 8.2. Enable the extensions `zip`, `fileinfo`, `pdo_mysql` and `intl` (see [Common problems](#common-problems)). |
| Composer | latest | [getcomposer.org](https://getcomposer.org/download/). Point it at the XAMPP `php.exe` during install. |
| Node.js | 22 | [nodejs.org](https://nodejs.org/). Includes npm. |
| MySQL | 8 | XAMPP's MySQL or MySQL Server, managed with **MySQL Workbench**. |
| Git | latest | [git-scm.com](https://git-scm.com/). Includes **Git Bash** — use it for all commands below. |

Check everything in Git Bash:

```bash
php -v          # PHP 8.2.x
php -m | grep -E "zip|fileinfo|pdo_mysql|intl"   # all four must be listed
composer -V
node -v         # v22.x
git --version
```

---

## Install

All commands run in **Git Bash**.

**1. Clone the project into `C:\softwareProject`**

```bash
mkdir -p /c/softwareProject
cd /c/softwareProject
git clone https://github.com/tima-gitHb/arcs.git
cd arcs
git checkout dev      # day-to-day work starts from dev, not main
```

**2. Install dependencies and build the CSS/JS**

```bash
composer install
npm install
npm run build
```

**3. Create your `.env` file and app key**

```bash
cp .env.example .env
php artisan key:generate
```

**4. Create the two databases in MySQL Workbench**

1. Open MySQL Workbench and connect to your local MySQL.
2. Click **Create a new schema** (the cylinder icon with a "+").
3. Name: `arcs` — Charset `utf8mb4`, Collation `utf8mb4_unicode_ci` → **Apply** → **Apply** → **Finish**.
4. Repeat for `arcs_test` (used by `composer test:mysql`).

Leave both empty: Laravel creates the tables.

**5. Point `.env` at MySQL**

Open `.env` and replace the `DB_` lines with (use your own MySQL password):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=arcs
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

Make sure none of these lines start with `#` or a space.

**6. Create the tables and the sample data**

```bash
php artisan migrate:fresh --seed
```

This creates the 15 ARCS tables plus four sample flights (ME 201, ME 305, ME 110, ME 422) with passengers.
Times are relative to now, so the data works on the day you seed it. Run it again any time to reset.

**7. Start the app**

```bash
php artisan serve
```

Open <http://127.0.0.1:8000> in Chrome or Edge.

---

## Seeded logins

All passwords are `password` (development only).

| Role | Email | Name |
|---|---|---|
| Reservation | `reservation@arcs.test` | Rami Nassar |
| Check-in | `checkin@arcs.test` | Maya Khoury |
| Gate | `gate@arcs.test` | Joe Saliba |
| Manager | `manager@arcs.test` | Hiba Daher |

After 5 wrong passwords an account locks. To unlock, run `php artisan migrate:fresh --seed` again.

---

## How we work

The full rules are in [CLAUDE.md](CLAUDE.md). In short:

- **Branches:**
  - `main` — milestone releases. Only the team lead merges `dev` into `main`, at milestones.
  - `dev` — where all finished work comes together.
  - `feature/<ID>-<name>` — one per task, e.g. `feature/C3-seat-map`, always created from an up-to-date `dev`:
  ```bash
  git checkout dev && git pull
  git checkout -b feature/C3-seat-map
  ```
- **Test first:** write the feature test from the SR in the BRD (named `test_SR7_...`), see it fail, then write the code.
- **Before every commit:** `php artisan test` must pass and `./vendor/bin/pint` must be run.
  Commit messages start with the task ID: `C3: seat map with taken seats blocked (SR-7)`.
- **Pull request:** push your branch and open a pull request **into `dev`** on GitHub
  (check the "base" dropdown says `dev`, not `main`). **CI must be green** before it is merged.
- **Never push or commit directly to `main` or `dev`.**
- **After every `git pull`:** `composer install`, `npm install`, `npm run build`, `php artisan migrate`.

---

## Common problems

**Composer asks for a GitHub token**

An old or wrong token is saved. Remove it and run `composer install` again:

```bash
composer config --global --unset github-oauth.github.com
```

**"The ... PHP extension is required" / missing PHP extension**

Open `php.ini` (find it with `php --ini`, e.g. `C:\xampp82\php\php.ini`), remove the `;` at the start of the line,
save, and open a new Git Bash window:

```ini
extension=zip
extension=fileinfo
extension=pdo_mysql
extension=intl
```

**Pages look unstyled (plain HTML, no colours)**

The CSS has not been built. Run:

```bash
npm run build
```

**403 errors, "wrong role", or you cannot log in with a seeded account**

Your database is out of date or an account is locked. Reset it:

```bash
php artisan migrate:fresh --seed
```

This deletes all data in `arcs` and recreates the sample data.
