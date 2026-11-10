# Commands

Every command this app is run with, what it does, and when you want it.

On the shop PC, PHP is not on the Git Bash `PATH`. Run these from Laragon's terminal,
in the app's folder.

## Daily

| Command | What it does |
|---|---|
| `composer dev` | Runs `php artisan dev` for local development. Laragon serves the app without it, at `http://bookkeeper-laravel.com.gh`; `php artisan serve` also works. |
| `php artisan test` | Runs the whole suite against MySQL (`nunabooks_test`). Never SQLite. |
| `php artisan test --filter=<name>` | Runs the tests whose name matches. |
| `vendor/bin/pint --test` | Checks formatting. `vendor/bin/pint` fixes it. |

## Before you claim it works

| Command | What it does |
|---|---|
| `php artisan test` | All of it, not only the file you touched. |
| `vendor/bin/pint --test` | Formatting, including `declare(strict_types=1);` in every file. |
| `npm run build:check` | Rebuilds the CSS and JavaScript, then fails if `public/build` differs from what git holds. The shop PC has no Node, so the built files ship in the repo. |
| `php artisan nuna:reconcile <copy>` | For anything that touches a figure: the old app's figures against these books. |

## Database

| Command | What it does |
|---|---|
| `composer setup` | `composer install`, a `.env` from `.env.example`, an app key, `migrate --force` (no prompt), then `npm install` and `npm run build`. **Dev PC only**: it needs Node, which the shop PC does not have (install there with `scripts/install.ps1`). It does not seed: run `php artisan db:seed` after it. |
| `php artisan migrate` | Applies pending migrations from `database/migrations/`. |
| `php artisan db:seed` | The chart of accounts, payment accounts and tax components. Running it twice changes nothing. Adds demo data too when `SEED_DEMO_DATA=true`. Refuses in production, always. Also refuses on a database that already holds real records, **unless `--force` is given**: then it adds invented sales beside the real ones and renames the shop. |
| `php artisan migrate:fresh --seed` | **Destroys and rebuilds the database.** Development only. |

## Moving from the old app

| Command | What it does |
|---|---|
| `php artisan nuna:import-sqlite <copy>` | Copies the old app's `bookkeeper.db` into this freshly migrated, unseeded database, unchanged. Always a **copy**, never the live file. |
| `php artisan nuna:reconcile <copy>` | Compares the old app's figures with these books. Zero differences is the only pass. `--as-at=YYYY-MM-DD` compares as at a date. |

## Records and health

| Command | What it does |
|---|---|
| `php artisan nuna:backup` | Backs the database up with `mysqldump`, restores it into a scratch database and checks it before it counts. Keeps the newest 14; `--keep=30` and `--dir=E:\backups` change that. |
| `php artisan nuna:restore <file> --force` | Replaces the database with a verified backup. First it saves what it replaces as a `...-before-restore.sql.gz` copy, checked to be complete but not for soundness (those books may be the broken thing), and only when the database had tables. On Windows it refuses while the web server service is running: stop it first (`Stop-Service <service>`). |
| `php artisan nuna:preflight` | Checks this install is ready for real trading: configuration, migrations, books, stock, owners and demo data. Run it when the numbers look wrong. |
| `php artisan nuna:benchmark` | Times every report at a year of trading, on a throwaway database whose name starts with `nunabooks_bench`. |

## Notes

- **`migrate:fresh` destroys data.** It is for development databases only.
- **`nuna:restore` replaces every record in the shop.** It will not run without
  `--force`, and it saves what it replaces first.
- **`nuna:import-sqlite` and `nuna:reconcile` take a copy.** Never point them at the
  old app's live `data/bookkeeper.db`.

## Related

- [Back up and restore](../how-to/back-up-and-restore.md): `nuna:backup` and
  `nuna:restore` in context.
