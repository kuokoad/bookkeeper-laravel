# NunaBooks (Laravel + MySQL)

Bookkeeping, inventory and point of sale for a small Ghanaian retail shop. It runs on the shop's own PC, with no internet needed to make a sale. This is a rebuild of the working Next.js + SQLite app in `C:\ClaudeProjects\bookkeeper` (the specification) in Laravel + MySQL 8.

> **Status: Phases 0 to 8 done, Phase 9 (proof and cut-over) under way** (schema, seed, domain layer, ledger core, importer and reconcile, catalog and inventory, sales, purchases, cashbook, reports and year-end; users and sign-in, search, notifications, backup and restore, preflight, installer and updater; every screen, clicked through module by module). Still to do: reconcile a copy of the real shop data, and a backup-restore rehearsal. [PLAN.md](PLAN.md) is approved; progress is in [docs/TASKS.md](docs/TASKS.md).

## Features
Carried over unchanged from the old app:
- Till with split tender (cash, MoMo, bank), discounts, Ghana tax (VAT, NHIL, GETFund), receipts, units and cartons.
- Credit sales with invoices, customer payments, statements, ageing, returns, voids and refunds.
- Purchases, suppliers, supplier payments and returns.
- Stock by weighted average cost, with batches and expiry, adjustments and product import.
- Expenses, other income, owner capital and drawings, transfers, cash/MoMo/bank reconciliation.
- Double-entry ledger with no stored balances: trial balance, P&L, balance sheet, cash flow, tax return, year-end pack, books lock, year-end close.
- Users with per-area rights and till PINs, insert-only audit log, settings, logo, search, notifications, in-app Help.

## Tech stack
- PHP 8.4, Laravel 13.40
- Blade + Livewire + Tailwind
- MySQL 8.4 (InnoDB, strict mode)
- Pest against a real MySQL test database
- Windows shop PC: MySQL and Apache as services (proposed, see PLAN.md Q1)

## Getting started

### Prerequisites
- Laragon (dev PC already has PHP 8.4.19 / 8.5.4, MySQL 8.4.0, Composer)
- Node, for building front-end assets on the dev PC only. `public/build` is committed: run `npm run build` before every commit that touches `resources/`, and `npm run build:check` (rebuild, then fail if `public/build` differs from git) before calling a change done

### Setup
```bash
composer install
cp .env.example .env && php artisan key:generate
```

Create the database with the binary collation, so text compares the way it did in the old SQLite app. The tests make their own (`nunabooks_test_run_<pid>`, one per run), so the MySQL user needs `CREATE` and `DROP`:

```sql
CREATE DATABASE nunabooks CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
```

```bash
php artisan migrate:fresh --seed
```

Creating the schema's triggers needs a MySQL user with the `TRIGGER` privilege (root on the dev PC; the shop installer will grant it to the app user).

### Environment
| Variable | Purpose |
|---|---|
| `DB_*` | MySQL connection (app user) |
| `NUNA_BACKUP_DB_USERNAME`, `NUNA_BACKUP_DB_PASSWORD` | Optional dump-only MySQL user for `mysqldump` (the installer creates one) |
| `NUNA_BACKUP_DIR`, `NUNA_BACKUP_KEEP` | Where `nuna:backup` writes, and how many verified copies it keeps (default `storage/backups`, 14) |
| `NUNA_MYSQL_BIN` | Folder with `mysqldump.exe` and `mysql.exe` (default: the newest `C:\laragon\bin\mysql\*\bin`, else the PATH) |
| `NUNA_APACHE_SERVICE` | The Apache Windows service; `nuna:restore` refuses while it runs (default `NunaBooksApache`) |
| `HASH_DRIVER` | `argon2id` (the default here): new password and PIN hashes |
| `SESSION_LIFETIME` | Idle minutes before a session ends (10080, a week, as the old app) |
| `SEED_DEMO_DATA` | Seed demo shop data (refused in production) |
| `SESSION_SECURE_COOKIE` | `true` only behind HTTPS |
| `NUNA_TRUST_PROXY_HEADERS` | Per-IP login throttle and audit address from `X-Forwarded-For`, only behind a proxy that overwrites it |
| `NUNA_SETUP_FROM_LAN` | Default `false`: first-run setup (creating the owner) answers only the shop PC itself; `true` lets anyone on the network reach it, and the first to post becomes the owner |
| `NUNA_OLD_LIVE_DATABASE` | Default `C:\ClaudeProjects\bookkeeper\data\bookkeeper.db`: the old app's live file; `nuna:import-sqlite` and `nuna:reconcile` refuse it, and any file with a `-wal`/`-shm` beside it. Copy it while the old app is stopped |

### Run
Laragon serves the app at `http://bookkeeper-laravel.com.gh`; without Laragon, `php artisan serve`. Sign in as the owner created at install (or a demo user when `SEED_DEMO_DATA` is on).

### Test
```bash
vendor/bin/pest --parallel --exclude-testsuite=Processes
vendor/bin/pest --testsuite=Processes   # on its own: real processes and their own databases
vendor/bin/pint --test
```

The Processes suite creates `nunabooks_concurrency`, `nunabooks_import`, `nunabooks_backup_source` and `nunabooks_backup_target` itself.

### Back up and restore
```bash
php artisan nuna:backup [--keep=14] [--dir=E:\backups]
php artisan nuna:restore storage/backups/nunabooks-2026-11-06T18-00-00.sql.gz --force
php artisan nuna:preflight
```

`nuna:backup` runs `mysqldump --single-transaction --quick --triggers --hex-blob` (a consistent copy while the shop trades) and gzips it. The copy counts only after it has been loaded into a scratch database (`<db>_scratch_<random>`, dropped afterwards) and found sound: every entry and the trial balance balance, receivables and payables equal their control accounts, every product's stock replays to its cached figures and the stock ledger equals GL 1200, every unit belongs to a batch, an owner can sign in; and its triggers, the row counts of the tables nothing deletes from (audit log, journal entries and lines) and the per-account totals match the live books. Other tables lose rows while the shop trades, so their counts are not compared. A mismatch is retried once with a fresh dump. A copy that fails is deleted and the command exits non-zero. Old copies are trimmed only after a verified one exists, and each backup writes the audit row the dashboard's backup notice reads.

`nuna:restore` refuses without `--force` and while the Apache service runs. It checks the file in a scratch database first (a bad file changes nothing), dumps the books it is about to replace to `...-before-restore.sql.gz` (not checked against themselves: they may be the broken thing being replaced), then empties the database, loads the file and checks it again, triggers included. Restoring into an empty database (a new PC) skips the safety copy.

`nuna:preflight` is the old `npm run preflight`: configuration, migrations, broken references, the books, the stock replay, batches, demo data and accounts, an owner, mysqldump, the last backup; it warns when the binary log would refuse `CREATE TRIGGER` and when an interrupted backup left a `*_scratch_*` database behind. It exits non-zero on any failure.

### Shop install and update (Windows)
Step-by-step guide for the person setting up the shop PC: [docs/INSTALL.md](docs/INSTALL.md).

`scripts/install.ps1` (PowerShell as Administrator, from the cloned app folder) sets up PLAN Q1: PHP's extensions plus opcache with the JIT in `php.ini`; MySQL as the `NunaBooksMySQL` service on `127.0.0.1` with its own `my.ini` and a random root password, shown once on screen for the owner to write down and saved nowhere (it warns if an existing MySQL has the binary log on and `log_bin_trust_function_creators` off); the database, an app user (with rights on `<db>_scratch_%` for backup checks) and a dump-only backup user, written to `.env` with `APP_ENV=production`; `composer install --no-dev`, the app key, a `nuna:backup` if the database already exists, `migrate --force`, the baseline data (`db:seed --class=Database\Seeders\CoreSeeder`: settings row, document numbering, chart of accounts, payment accounts and taxes; it only adds what is missing) and the config, route and view caches; the built CSS and JS come with the code (`public/build` is committed), so the shop PC needs no Node; Apache with mod_fcgid as the `NunaBooksApache` service; the firewall rule (`-Firewall`, private networks only), the hosts entry and a desktop shortcut; the `NunaBooksUpdate` scheduled task; and, when `origin` is on GitHub, a read-only deploy key for it. Updates run as SYSTEM, which has no GitHub sign-in, so the installer creates an ed25519 key in `-DeployKeyDir` (default `C:\NunaBooks\update-key`, no spaces, locked to Administrators and SYSTEM and owned by Administrators, as Windows' OpenSSH requires). It writes GitHub's pinned host keys next to it, switches `origin` to SSH and sets `core.sshCommand` in the app's `.git/config`, so `update.ps1` and the Health page's update check both use it unchanged. It then tests with `git ls-remote`; until the key is added on GitHub (Settings > Deploy keys, write access off), it prints the public key and leaves a copy on the desktop. It ends with `nuna:preflight`. Paths to PHP, MySQL and Apache are parameters (Laragon's by default); it downloads nothing.

`scripts/update.ps1` is what "Update now" runs, through that scheduled task (a child of Apache would die when the update stops it): stop Apache (so no sale lands after the backup), a verified `nuna:backup` (it starts Apache again and stops there if that fails), `git pull --ff-only`, `composer install --no-dev`, `migrate --force`, the baseline data (CoreSeeder adds any row a new release brings and changes none that exist), the caches, start Apache and check `/up`. A failure before the migration puts the code back to the commit it started from; any failure once the migration has begun puts the code back AND restores the backup, since the books may be half migrated. Native commands are judged by their exit code, so a warning on stderr does not stop it under PowerShell 5.1. The outcome goes to `storage/app/update-status.json` for the Health page, the output to `storage/logs/update.log`.

Each run of `install.ps1` also runs `ALTER USER` for the app user and the backup user, so a MySQL password changed by hand comes back to the value in `.env`: change it in `.env`, not in MySQL. Before migrating, the install skips the backup only when the database answers that it has no active owner (a new database, or setup not yet done); any other answer, a failed check included, runs `nuna:backup`, and the install stops if that fails.

Neither script has been run on a shop PC yet; both are checked for plain ASCII and parsed by Windows PowerShell 5.1 in the test suite.

### Prove it matches the old app
Into a freshly migrated, unseeded database:

```bash
php artisan migrate:fresh
php artisan nuna:import-sqlite path/to/COPY-of-bookkeeper.db
php artisan nuna:reconcile path/to/COPY-of-bookkeeper.db
```

The import commits nothing if any row is refused or any link dangles, and lists each problem. Reconcile compares the figures to the pesewa; zero differences is the only pass. Version 1 checks rows, the trial balance, stock, receivables, payables and tax held; later phases add the reports.

## Project structure
```
app/Domain/      pure money, quantity, tax, costing maths (no DB)
app/Services/    one per old service; transactions, locks, journal, audit
app/Livewire/    screens
database/        migrations, seeders
resources/docs/  in-app Help (ported from the old docs/)
tests/           Domain, Database, Services, Processes (real concurrent processes), Feature
docs/            planning docs
```

## How the money works
Money is an integer count of pesewas (`GHS 1,250.00` = `125000`), and quantity an integer count of thousandths (`1.5 kg` = `1500`). No balance is stored: every figure is computed from journal lines. Corrections are reversing entries, never edits.

## Differences from the old app
Every behaviour difference is listed here with its reason.

| Area | Old app | Here | Why |
|---|---|---|---|
| Carton without a size | `ck_products_alt_unit` accepted a carton unit with a NULL size (a CHECK that evaluates to NULL passes, in SQLite and MySQL alike) | Refused | A carton with no size cannot convert to base units. The old services always set a size, so real data should not be affected; the importer will report any row that is. |
| Self-references (`x <> id`) | CHECK constraints | Triggers with the same rule | MySQL forbids a CHECK on an AUTO_INCREMENT column. Also added for the `voided_by_*` and `reversed_by_*` columns. |
| Trading-date format checks (`GLOB 'YYYY-MM-DD'`) | CHECK on text | `DATE` column type | Strict mode refuses an impossible date outright. |
| Audit log | Insert-only because no code path changes it | Also refused by database triggers | Holds even against a hand-written SQL fix. |
| Self-referencing links (`voids_sale_id` and the like), `accounts.parent_id` | No foreign key | Foreign key | Real FKs, as the brief asks. |
| Unit rename | `ON UPDATE CASCADE` to products | No cascade: `UnitsService::updateUnit` renames the unit and every product using it in one transaction, with foreign-key checks off for those three statements only | MySQL forbids a CHECK on a column with an FK action (PLAN Q6). The shop sees the same result. |
| Sessions | Hashed tokens in `sessions` | Laravel's session table; old sessions not imported | PLAN Q7. |
| Text comparison | SQLite BINARY: exact, trailing spaces count | `utf8mb4_0900_bin` (binary, NO PAD): the same | `utf8mb4_bin` would treat `'X1'` and `'X1 '` as equal. |
| Case-insensitive uniqueness (usernames, SKUs, category, unit and payment-account names) | `lower()` folds ASCII only | MySQL `LOWER()` folds all Unicode letters, so "Éclair" and "éclair" now collide | Closer to what a person means. The importer will report any old rows that clash. |
| Column widths | SQLite text has no length | Names and codes sized to what the old CSV import accepted (1,000 characters; 768 where a key needs it) | MySQL needs a length; anything longer will be reported by the importer, not truncated. |
| `audit_logs.user_id`, `users.created_by` | Plain integers | Foreign keys with RESTRICT | Real FKs; RESTRICT because an FK action would rewrite audit rows without firing the insert-only triggers. |
| Audit-log search | `%` and `_` in the search box were SQL wildcards, so `%` matched every entry | Matched literally | Filter rule 4: input narrows, it never widens. |
| Product and stock-movement search | Same: `%` and `_` were wildcards | Matched literally | Same reason. |
| Sale, customer and quotation search | Same: `%` and `_` were wildcards | Matched literally | Same reason. |
| The same cart submitted twice at once (`clientRef`) | One writer at a time, so the second always found the first and replayed it | The lookup takes a lock first; the second submission waits for the first or loses a deadlock to it, and is retried (Tx, then a few more rounds for a deadlock) until it finds the stored sale and replays it. Never a second sale, never a "saved at the same moment" error | MySQL runs tills side by side. Proved by `tests/Processes/SaleConcurrencyTest`. |
| Transaction isolation | SQLite: one writer at a time | MySQL `READ COMMITTED`, and decisions read the locked row (a return checks the lines it locked; a quote's status is read from the locked quote) | At MySQL's default `REPEATABLE READ`, a plain read after waiting for a lock still saw the data from before the wait: two tills could return the same goods, or convert the same quote, twice. Proved by `tests/Processes/SaleConcurrencyTest`. |
| The same item twice in one customer return | Accepted: `[{item, 5}, {item, 5}]` on a 6-unit line each passed the "still returnable" check, so 10 came back and more cash could be refunded than was paid | Refused: "Each item can appear only once in a return." | A deliberate fix of a hole in the old app. |
| The same item twice in one supplier return | Accepted, as on the customer side | Refused with the same message | The same hole: a supplier could be asked for more goods back, and more cash, than the delivery held. |
| Concurrent writes to one supplier (a payment, a return, a void, a delivery) | One writer at a time | The supplier row is locked after the products and before the purchase and its lines; a return decides on the lines it locked; every writer that changes what a supplier is owed (delivery, void, return, payment, payment void) holds the supplier lock | So two desks cannot return the same goods twice, and a void and a payment on one delivery cannot both go through. Proved by `tests/Processes/PurchaseConcurrencyTest`. |
| The same purchase twice in one supplier payment's allocations | Refused by the unique index (`uq_supplier_payment_allocations`) with SQLite's constraint error | Refused up front: "Each purchase can appear only once in a payment." | As for customer payments. |
| Purchase and supplier search | `%` and `_` were wildcards | Matched literally | Filter rule 4. |
| Purchase reads for one supplier (`getOutstandingByPurchase`, open purchases) | Summed the tender of every purchase in the shop | Sums only that supplier's purchases; `getPurchase` reads only the batches its lines filled, not every batch | Same figures; no whole-shop scan for one party's numbers. |
| The same sale twice in one payment's allocations | Refused by the unique index (`uq_customer_payment_allocations`) with SQLite's constraint error | Refused up front, with a message that says so | The index's error would otherwise read as "someone else saved the same code or name". |
| Duplicate-key tests (`client_ref`, a quote converted twice) | Expected SQLite's "UNIQUE constraint failed" | Expect MySQL's "Duplicate entry" | Same guarantee, the database's own wording. |
| Product import: codes, units and categories matched ignoring case | SQLite `lower()` folded ASCII only on the database side; JavaScript folded every letter on the file side | Both sides fold every letter (`mb_strtolower`) | One rule for both sides, the one MySQL's unique indexes apply. |
| Product-import test "lands whole or not at all" | Forced the second insert to fail with a SQLite trigger | Forces it with a name longer than the column, which MySQL strict mode refuses | `CREATE TRIGGER` commits in MySQL, ending the test's transaction. The behaviour tested is unchanged. |
| Demo users' passwords and PIN | scrypt hashes from the old auth service | Laravel `Hash::make` hashes of the same published credentials | Sign-in is Phase 8; the demo users exist now so the demo shop has an owner to record through. |
| `db:seed --force` with `SEED_DEMO_DATA=true` | The old runner's own `--force` meant "seed demo data beside real records" | Laravel's `--force` (also its production-confirmation flag) does that job; production is refused regardless. `migrate:fresh --seed` always passes it, on a database it has just emptied | One flag, the old wording kept in the refusal. |
| Cashbook and account-statement search | `%` and `_` were wildcards | Matched literally | Filter rule 4. |
| Date of the reversal when an expense, income or count is voided | The server's local calendar day (`getFullYear()` and friends); a transfer's void used the shop's day | The shop's business day (`Africa/Accra`) for all four | One rule; identical whenever the server clock is on Accra time (UTC+0), as the shop PC is. |
| Payment-account balances on the accounts page, the reconciliation overview | One ledger query per account, and per account again for its counts | One grouped query for the balances, one for the counts; `getPaymentAccount` reads only that account | Same figures; no query per row and no sweep of every account for one. |
| Two people adding a payment account or category under one heading at once | One writer at a time | The heading's account row is locked before its children are read, so they take turns and each gets the next free code | Proved by `tests/Processes/AccountCodeConcurrencyTest`; without the lock the second desk hit the unique code. |
| Archiving an account, and a count's expected figure | Read inside SQLite's single writer | The payment account row is locked, then its balance read; every writer that moves money through an account (cashbook, transfers, sale and purchase tenders, customer and supplier payments, return refunds, and each of their voids) share-locks it first, so none can move it in between | So money cannot land on an account as it is archived, and a count's expected figure cannot change under it. Proved by `tests/Processes/ArchiveRaceTest`. |
| Report rows that tie on their sort key (customers, suppliers, payment methods, products by revenue or name, ageing parties, stock rows) | Whatever order SQLite's GROUP BY happened to return | The same sort, then the id (a walk-in's NULL first, as SQLite sorted it) | A report that reorders itself on refresh is not believed. No figure changes. |
| A name shown beside a grouped figure where the name could differ inside the group (purchases by product, the tax return's component name) | SQLite's bare column: the name from whichever row it read last | `MAX(name)` | MySQL's ONLY_FULL_GROUP_BY refuses a bare column. Identical unless a product was renamed between deliveries or a tax code changed its name. |
| Account totals behind the trial balance, P&L, balance sheet and chart | Every account LEFT JOINed to every line, the period in the join | Lines summed per account in a derived table, then joined to the chart (the period, or `is_closing = 0`, inside it) | Same figures (`excludeClosing` with no dates now applies too, PLAN D4); one pass over the lines instead of a join that grows with them. |
| Cash flow per payment account | Two aggregates per account | Two grouped queries for all accounts | Same figures, no query per row. |
| Journal list filtered by an account | Read every matching entry id first, then `IN (...)` | `EXISTS` on that account's lines | Same rows; no id list that grows with the shop. |
| First-run setup and the lock order | No locks (SQLite's single writer) | Setup locks the settings row, then writes the owner and the shop's settings: the one write that takes the settings row before anything else, outside the usual order (products, batches, sequences, then documents) | It runs once, before any account exists, so no trading can be holding the other locks; two first-run posts queue on the settings row and the second finds the owner. |
| Closing a year, reopening one | Inside SQLite's single writer | The settings row is locked exclusively first, then the open closing for the year, before anything is read or posted | The close moves the books lock after posting; taking the exclusive lock first avoids a shared-to-exclusive upgrade against another poster (see `Tx`). |
| `idx_journal_lines_account` | `(account_id, entry_id)` | `(account_id, entry_id, debit_minor, credit_minor)`, and a window holding most of the ledger is summed as all time less the entries outside it | MySQL walked the old index and looked up every line: the all-time trial balance took about 1 s at a year of trading, 150 ms now. Same figures. |
| Books integrity check, unbalanced entries | Every entry LEFT JOINed to its lines | Lines grouped by entry in one hashed scan, then joined to the few that differ | Same result (an entry with no lines balances at 0 = 0 either way); 2.5 s became 0.6 s at a year of trading. |
| General ledger's account header | Built the whole chart of accounts to find one account | That account's own subtree | Same balance, roll-up and depth; about 1.1 s became 80 ms. |
| `nuna:benchmark` | `npm run benchmark` on a temp SQLite file, raw queries and their plans | Its own MySQL database (`nunabooks_bench*`, dropped afterwards), the old raw queries plus every report service as the app calls it; no query plans | The figure that matters is what a screen waits for. |
| New password and PIN hashes | `scrypt$32768$8$1$...` (Node `crypto.scrypt`) | Laravel's `Hash` with argon2id, from the same NFKC-normalised password | Laravel's hasher, as the brief asks; argon2id rather than bcrypt, which reads only the first 72 bytes of a password the old app let run to 200 characters. |
| Old-app hashes | Verified with scrypt; rehashed only when its parameters were weaker | Verified by `App\Auth\LegacyScrypt` (RFC 7914 in plain PHP, the old bounds and refusals plus N x r x p no more than the old app's own 2^15 x 8 x 1, NFKC, timing-safe compare) and replaced with an argon2id hash on that person's first successful sign-in, inside the sign-in write, one person at a time; a till PIN likewise on its first PIN sign-in | PLAN 6.3. Never a bulk rewrite. The old app never rehashed a PIN (PLAN D19). A tampered row with each bound in range but N=2^20, r=32, p=16 would have tied up the till for minutes; the combined cap refuses it at once. |
| The first sign-in after the switch | Nothing else happens | That person's OTHER sessions end, once | The rehash changes the stored hash, and every session carries a fingerprint of it (see "Ending a person's sessions"). Sessions from before the switch are not imported (PLAN Q7), so this only ends a session on another till they signed into since. |
| First sign-in with an old-app password | About as fast as any other | About 6 s in plain PHP, under 1 s with the opcache JIT (the installer turns it on) | PHP has no scrypt that takes N, r and p. Paid once per person. See PLAN D12 for what it means for timing. |
| A legacy hash with an N that is not a power of two | Node threw: the sign-in failed with an error | Refused like any malformed hash (the password simply does not match) | No real hash has one; an error page is not an answer. |
| A sign-in whose checked hash was replaced meanwhile (an owner reset it, or it was switched off, while the password was being checked) | Signed in on the old password | Refused as a wrong password | The check and the write are separate (a scrypt check must not hold a row lock); the write re-reads the locked row and refuses if what was checked is no longer the credential. `changeOwnPassword` likewise refuses if the password changed under it. |
| Sessions | Hashed tokens; the expiry slid a week forward only when less than a day was left (so idle sessions ended after 6 to 7 days); a 30-day ceiling from sign-in | Laravel's database sessions: idle exactly a week (`SESSION_LIFETIME`), the same 30-day ceiling (`EnforceSessionLimits`) | PLAN Q7. |
| Ending a person's sessions (password change or reset, role or permission change, switched off) | Their session rows deleted | Their rows deleted, and every request also compares a fingerprint of the password hash, role and permissions taken at sign-in | Laravel saves a session back at the end of a request, so a request already in flight would otherwise bring a deleted session back. A PIN change, as before, ends nothing. |
| Sign-in throttle and lockout | 10 attempts per 10 minutes per client (one shared bucket without a trusted proxy); 5 failures lock the account for 15 minutes; serving the lock gives the attempts back | The same numbers and rules (`AuthService` constants, `RateLimit` on the `rate_limits` table) | Unchanged. The throttle is applied by the Phase 9 sign-in controller, before `AuthService::login`. |
| Client address from proxy headers | Taken as sent | Must parse as an IP address, else unknown | So a forged header cannot write arbitrary text into `rate_limits.key` or the audit log. |
| Setting another person's PIN or resetting their password | Allowed to anyone with `users` edit, an owner's included (old `user.service.ts:312`, `:397`), though `role-guard.ts` says its two rules "close every route" | An owner's needs an owner; anyone else's needs the actor to hold every right the target holds (`RoleGuard`) | Setting a credential is signing in as that person: a staff member with `users` edit could take over the owner. PLAN D14. |
| Switched-off account | Refused by the session check | Also refused by every Gate ability (`Principal::load` returns null) | Holds even where no middleware stands guard. |
| Audit row of a sign-in for an unknown username | The typed name in `username`, any length | Cut to the column's 40 characters; the summary keeps it whole | `audit_logs.username` is `VARCHAR(40)` here. |
| Global search | SQLite `LIKE ... ESCAPE '\'` (case folding of ASCII only); rows in whatever order SQLite returned on ties | `LOWER(col) LIKE LOWER(?)` with escaped wildcards, every Unicode letter folded; ties broken by id | The collation is binary, so LIKE alone would be case-sensitive. |
| Backups | A consistent copy of the SQLite file, checked for integrity, foreign keys and a balanced trial balance; kept in `./backups` | `mysqldump` gzipped, checked by loading it into a scratch database: the balance checks plus stock replay, stock ledger = GL 1200, AR and AP = their control accounts, batch coverage, an active owner, and triggers, insert-only row counts and per-account totals against the live books; kept in `storage/backups` | PLAN 6.2. A shop that has not yet created its owner cannot take a verified backup. |
| Restore | Refused with `NODE_ENV=production` unless `ALLOW_PRODUCTION_RESTORE=true`; the replaced file copied aside as `.replaced-<time>` | Refused while the Apache service runs; the replaced books kept as `...-before-restore.sql.gz`, a plain dump that is not checked, so broken books can still be replaced; the restored triggers are compared with the file's | PLAN 6.2 asked to rename the database aside, but MySQL cannot move a table with triggers to another schema; a verified dump keeps the same undo. |
| Preflight | `Database is not corrupt` (SQLite `integrity_check`), `Session secret is real`, migrations counted in `__drizzle_migrations` | No corruption check (InnoDB checksums its own pages; `CHECK TABLE` would commit mid-test); `App key is real`; pending migration files against those run; new: `Backups can be taken` (mysqldump found), `A backup has been taken`, `Migrations can create triggers` and `No scratch databases left over` (both warnings) | Same questions for this install. |
| "Update now" | `update.ps1` started as a child of the Node server; `npm ci`, a build; migrations ran at startup | The `NunaBooksUpdate` scheduled task runs `update.ps1`; `composer install --no-dev` and `migrate --force` (no build: assets ship built); stops Apache before the backup; rolls back the code if the update fails before migrating, and the code and the backup once a migration has begun | PLAN 6.1. Apache, unlike Node, is a service the script stops. |
| The demo users' passwords | Laravel `Hash::make` (bcrypt) since Phase 6 | argon2id, the driver now configured | They sign in with the published credentials (tested). |
| A query-string key given twice (`?from=2026-01-01&from=2026-02-01`) on a list or report page or its CSV | `searchParams.get` took the first value | PHP keeps the last value before any code runs | Not reachable from the app's own links and forms, which send each key once. An explicit array (`from[]=`) still takes its first element. |
| Messages after a save that moves to another page ("Account created", "Customer added", "Sale voided") | Carried in the address (`/users/5?created=1`), so the banner came back on a refresh or a shared link | A one-shot session flash (`->with('status')`, `<x-flash />`); the account page shows "Account created" itself and clears it on the next action | Laravel's way; the message belongs to the action, not the URL. |
| Switch user | Only "Sign out", back to the sign-in page | Also "Switch user": signs out and opens the sign-in page on the till-PIN tab (`/login?mode=pin`) | Switching is how the next cashier takes the till, and they sign in by PIN. |
| Date fields | A home-made calendar (`ui/date-field.tsx`) | The browser's own `<input type="date">` (`<x-date-input>`), still showing the resolved date and its min and max | The native picker has the keyboard, screen-reader and phone wheel for free, with no script to port. |
| Till product search | The whole catalogue sent to the browser on page load and searched there | The server is asked per search (debounced), for at most 8 matches; Enter scans the box's own value; the customer list (200, as the old default) is read once per till and again after each sale | A Livewire page has no client-side catalogue; a shop with thousands of products no longer loads them all to ring up one. |
| Receipt printing | A "feeding paper" animation before the print dialog | The print dialog opens straight away | Decoration with no figure in it; `?print=1` still prints on arrival. |
| The shop's logo (`/logo`) | Served to anyone, signed in or not (`api/logo/route.ts`), so a printed receipt could show it | Behind sign-in like every page | Every receipt is printed from a signed-in page, so nothing is lost, and nothing is served to a stranger. |
| Batch page (`/inventory/batches/{id}`) | Needs `products` view; changing the expiry needs `inventory` edit | The same | Listed because the planning table (UI-CONVENTIONS §9) said `inventory` view; the old page wins. |
| Report CSV links | Carried the query as typed (`?period=month`), and the export worked the dates out again | Carry the resolved `from` and `to` the page used | Filter rule 5: the export matches what was on screen. |
| Who owes you / who you owe, `asAt` | Read only `asAt` | Falls back to `to` when `asAt` is missing, so the CSV link and a hand-typed range give the same date | One parser for the page and its CSV (filter rule 1). |
| Journal list with an unknown `?source=` | Passed through as a filter, so the list showed nothing | Ignored: an unknown kind narrows nothing | Filter rule 4: a value outside the list is not a filter. |
| Receivables and payables pages | No CSV download | A CSV button, through the shared export route | Every list that shows figures can be exported with the same filters. |
| Product stock tracking | Could change at any time | Fixed once the product has history | PLAN D28. |
| Refund account | Any account | An archived payment account is refused | PLAN D29. |
| Backups (schedule) | Manual only | A daily `NunaBooksBackup` scheduled task at 21:00, registered by `install.ps1` | A backup nobody remembers to take is no backup. |
| Session limits on screens | Not applied to live screen updates | The 30-day limit and the forced password change also apply to Livewire calls | A screen left open must not outlive the session rules. |
| Cash flow "reconciles" check | Compared opening plus movement with itself, so it could never fail | Compares the closing balance with the sum of the per-account closing balances, a separate query | A check that cannot fail checks nothing. |
| Journal, ledger, trial balance and audit dates | A from after the to was used as typed, so the page showed nothing | Swapped, as the report pages already did; the ledger starts at the 1st of the month by default, earlier lines in its opening balance; a `to` alone before the 1st starts at its own month | Filter rule 5, and a ledger of every line ever is slow to read (PLAN D30). |
| List CSV file name | Built from the raw `from` and `to` | Built from the resolved dates, only `A-Z a-z 0-9 . _ -` in the header | The name matches the rows; nothing typed can break the header. |
| Restore screen | `npm run db:restore` on the shop PC; no screen | Still the command line only (`php artisan nuna:restore`); the Health screen offers backups, not restore | A restore replaces the books; it should not be one click away on a till (PLAN D18). |
| Money shown without a currency (`Format::money`) | Each page passed the shop's currency | Defaults to the shop's `currency_code`, read once per request | So a page that forgets to pass it shows the shop's currency, not GHS. |
| Record ids on a page (`/suppliers/1/edit`) | Fixed by the server component | `#[Locked]` Livewire properties; a browser that sends another id gets an error | Without the lock, a tampered request could save onto another record. |
| Voiding a customer or supplier payment from their page | The payment had to be theirs | The same, checked with one query by id, so a payment older than the newest 500 can still be voided | The first port looked only at the newest 500. |
| Tax on a supplier return | The whole tax share credited to VAT Payable (2100), a folded levy included (old `returns.service.ts:761`) | Each reclaimed tax credited to its own account (`TaxService::accountFor`, from the purchase's snapshotted component and `is_recoverable`); a folded levy leaves inside the goods' cost, through the stock movement (1200) or 6900 for non-stock lines. Shares are cumulative, so a full return, or 3 then 7, undoes exactly what the purchase posted | Returning a Ghana-taxed delivery left VAT overstated by the levies and the levy reclaim on goods that went back. PLAN D1, approved by the owner 2026-11-06. New returns only; imported ones are copied as posted. |
| Value of goods on a supplier return | The line total before the invoice discount, without folded levies, and no costing residual (old `returns.service.ts:573`, `:695`) | The stock-ledger cost of the line, as a void reads it (`PurchaseService::goodsCostByItem`), prorated cumulatively; a residual on an emptied shelf is posted as the void posts it (`postCostingResidual`). The return document's discount carries its share of the invoice discount | 10 x 65.00 less 65.00, returned in full, credited 650.00 against 585.00 owed and left Inventory at -65.00 on an empty shelf. PLAN D2, approved by the owner 2026-11-06. |
| Supplier payment allocations | Not checked against the payment: 10.00 paid with 50.00 allocated settled 50.00 of a purchase while the payable fell by 10.00 | Refused: "The amounts allocated to purchases exceed the payment." The customer side already refused ("...to invoices exceed the payment.") and is unchanged | PLAN D3, approved by the owner 2026-11-06. |
| Account balances with "leave out closing entries" and no dates | The condition applied only when a from or to was given, so all-time balances still counted the closing entries (old `balances.service.ts:80-84`) | Every condition applies whenever any is set: a closed year's Sales Revenue shows its real total | PLAN D4, approved by the owner 2026-11-06. The P&L and year-end close always pass dates and are unchanged. |
| Dashboard windows | `today − 7`, `− 30`, `− 90` to today, both ends counted: 8, 31 and 91 days (old `lib/dashboard.ts:9,16`) | `today − 6`, `− 29`, `− 89`: exactly 7, 30 and 90 days, as the 7d/30d/90d buttons say | Last Friday's takings were counted in "this week" on a Friday. PLAN D5, approved by the owner 2026-11-06. |
| Money trend (dashboard) | Every debit and credit on the money accounts, a transfer between them included (old `reporting/money-trend.ts:80-95`) | Transfers (and their voids) left out, as the shop-wide cash flow leaves them out | Moving 2,000.00 from the till to the bank showed as 2,000.00 more in and out than the cash-flow report. PLAN D6, approved by the owner 2026-11-06. |
| Purchases by product | Grouped by product only, with any row's unit (old `operations.service.ts:658-672`) | Grouped by product and the line's stored unit: one row per unit bought in; the CSV names the unit beside the product | 5 cartons then 10 pieces read "15 pcs". PLAN D7, approved by the owner 2026-11-06. |
| Purchase due date | None; Payables aged every delivery from its date (old `ledger.service.ts:585`) | Optional `purchases.due_date` (new column; imported rows stay empty; no supplier terms exist to default it), on the purchase form and page, held to a sale's terms (the purchase date plus 0 to 365 days, refused with "The due date must be from the purchase date up to a year after it."); Payables ages from the due date, else the purchase date, and heads the column "Oldest due", as Receivables does | A delivery on 60-day terms received 45 days ago showed as 31-60 days overdue. PLAN D8, approved by the owner 2026-11-06. |
| Tax return's sales count | Every sales row in the period, a voided original and its void mirror included (old `tax-return.service.ts:151`) | The documents standing at the period end, for sales and purchases alike: one dated in the period counts unless a void of it is dated on or before the end, and a void mirror never counts; the taxable value still nets every row, unchanged | One sale of 100.00 and a void of another read "over 3 sales". A March sale voided in April stays "1 sale" in March; April counts 0. PLAN D9, approved by the owner 2026-11-06. |
| Report CSV dates | An invalid `from` fell back to the 1st of this month, and a `from` after `to` gave an empty file (old `api/reports/[report]/route.ts:59`) | Resolved exactly as the page resolves a custom range (`ListFilters::parseReportDates` calls `Filters::resolveDateRange`): a backwards pair is swapped, one end alone means "from then on" or "up to then", neither valid is this month so far; the sales and purchases files use those dates rather than re-reading the period | PLAN D10, approved by the owner 2026-11-06. |
| Margins (P&L gross and net, sales by product) | `Math.round` of a float ratio: a tie went towards +infinity (old `financial.service.ts:198,202`, `operations.service.ts:397`) | Exact integer arithmetic, half away from zero (`Money::mulDiv(profit, 10_000, revenue)`) | A loss of 1.00 on 20,000.00 showed 0 bp, now -1 bp; at most 1 bp either way. PLAN D11, approved by the owner 2026-11-06. |
| Changing your own password | Signed out every session, this one too (old `user.service.ts` `changeOwnPassword`, against its own comment) | The other sessions end; this one stays signed in under a new id | PLAN D13, approved by the owner 2026-11-06. |
| Sign-in throttle | One shared bucket, 10 per 10 minutes, cleared by any success | 10 per 10 minutes per username plus 50 shop-wide (per address behind a trusted proxy); a success clears only that name; an owner account is never refused by either, only slowed (D16) | One guesser no longer blocks every till. PLAN D15, approved by the owner 2026-11-06. |
| Owner failed sign-ins | Locked for 15 minutes after 5 failures | Never locked: a growing wait up to 60 s, and the failed count shown on the next sign-in; staff keep 5 / 15 minutes; every limit shows one message | Anyone could lock the owner out. PLAN D16, approved by the owner 2026-11-06. |
| Owner till PIN | Allowed | Refused, as a wrong PIN; a PIN cannot be set on an owner | PLAN D17, approved by the owner 2026-11-06. |
| Restore that fails part way | Database left empty or partial | The live books are reloaded from the safety copy at once and checked; the safety copy must be complete before anything is dropped | PLAN D18, approved by the owner 2026-11-06. |
| Till after a part-paid sale | "Payment received in full." | "GHS 30.00 left on <customer>'s account." | PLAN D20, approved by the owner 2026-11-06. |
| Customer return / owner money saved | No message | Confirmation shown | PLAN D21, approved by the owner 2026-11-06. |
| Purchase report | Purchases only (old `operations.service.ts:628,658,688`) | Returned and Net columns, page and CSV | Agrees with the supplier pages. PLAN D22, approved by the owner 2026-11-06. |
| Sales report sale count | Included voided sales (old `operations.service.ts:266`) | Sales standing at the period end, as the tax return counts | PLAN D23, approved by the owner 2026-11-06. |
| Customer and supplier "Total bought / Total paid later" | Summed the latest 100 rows; the customer page took each void off twice | All-time sums over posted documents, net of returns on both pages | PLAN D24, approved by the owner 2026-11-06. |
| Customer return in pieces | Each piece's revenue, cost and tax rounded on its own (old `returns.service.ts:182-183`, `:236`): 3 x 10.00 less 1.00 returned a unit at a time credited 29.01 and put back 9.99 of a 10.00 cost | Cumulative shares, as supplier returns: 9.67, 9.66, 9.67 credited, 3.33, 3.34, 3.33 of cost, each tax component on its own running share | Pieces add up to exactly a full return. Stored and imported returns keep their figures. PLAN D25, approved by the owner 2026-11-06. |
| Stock back from below zero (purchase, customer return, sale void, adjustment in) | The costing residual dropped (old `purchase.service.ts:286`, `returns.service.ts:327`, `sale.service.ts:806`, `stock-adjustment.service.ts:206`): Inventory kept 2.00 on an empty shelf after selling one at 10.00 from none and buying one at 12.00 | Posted with `postCostingResidual` under the document (Dr 5000, Cr 1200, or the reverse), as a purchase void already did | Inventory (1200) equals the stock value. PLAN D26, approved by the owner 2026-11-06. |
| Customer return of goods whose cost share is zero | Not put back on the shelf (old `returns.service.ts:319`): of 3 nails costing 0.02, returned one at a time, the middle one stayed off; a free sample never came back | Always restocked, at a zero cost when the share is zero | The shelf count matches what came back. PLAN D27, approved by the owner 2026-11-06. |
| Receivables and payables ageing as at a past date | Counted only payments still posted today, so one voided after the date went missing from that day: a sale paid on 10 Jan and the payment voided on 3 Feb showed 100.00 owed as at 31 Jan, against 0.00 on the balance sheet (old `reporting/ledger.service.ts` ageing) | A payment counts as it stood that day: dated by then and either still posted or voided after it (the void's reversal entry date); a void dated on or before the day takes it off. Sale, purchase and return voids already counted this way. An imported voided payment with neither a void date nor a reversal entry has no date to judge by, so it drops out of every past date, as in the old app | The ageing as at any date agrees with the control account and the subledger as at that date. PLAN D32, approved by the owner 2026-11-06. |
| Customer statement as at a past date | Only printed as at today; the invoices were cut off at the date but their amounts and the total were today's (old `(app)/customers/[id]/statement/page.tsx`), so the same code as at 15 Jan showed a 100.00 invoice of 5 Jan as 40.00 after a 60.00 payment on 20 Jan | Each invoice and the total as they stood that day, by ageing's rule: later payments, returns and credits left out, a payment voided after the day still counted | The total equals the customer's receivables ageing as at the same date. The Statement page (as at today) is unchanged. PLAN D33, approved by the owner 2026-11-07. |
| Sales report line tables | The lines read and shared out per receipt once per table (up to four times a page) | Under a product or category filter, read once per page or CSV, streamed one receipt at a time, and shared. Without one, the stock item and group tables read only each line's figures, a calendar year at a time, and share each receipt out in integers by the same largest-remainder rule (a receipt whose lines already add up to it keeps them as they are), tax on top of the price or inside it alike | Same figures to the pesewa (`SalesByLineSqlTest` with no tax, tax on top and tax inside; reconcile v2 compares both paths per product per month). At 146,000 lines a year (`nuna:benchmark`), the year's stock item table went from 6.3 s to 0.7 s and the stock group table from 8.7 s to 0.7 s; with `--tax-inclusive` both take 0.8 s; all within the default 512M (it needed 1G). |
| Cash flow and tax return speed | One-column foreign-key index on `journal_lines.payment_account_id`; the tax-return count probed every sale | Covering index `(payment_account_id, entry_id, debit_minor, credit_minor)` (migration 2026_11_07_000100); cash flow in two grouped queries instead of four; the D9 count is originals less voided originals; the per-code tax query uses a hash join | Same figures (old and new compared across 8 periods and 4 accounts; reconcile v2 at zero). At a year of trading (`nuna:benchmark`) a month's cash flow went from about 1.2 s to 0.35 s and the year's tax return from about 1.2 s to 0.4 s. |
| Backup download | Anyone with Settings edit could download the full backup (every password hash and figure) | Owners only, as updates are; staff with full Settings rights get 403 and "Only the shop owner can take a backup". `php artisan nuna:backup` on the PC is unchanged | PLAN D31, approved by the owner 2026-11-06. |
| Receivables and payables CSVs | `reports` view was enough to download them | They also need `accounts` view, as their screens do; anyone else gets 403 "You do not have permission to export this." | The file gave customer and supplier names, phones and balances to people the screens refuse. Owner decision, 2026-11-07. |
| First-run setup | Reachable from any machine that could reach the app | Answers only the shop PC itself unless `NUNA_SETUP_FROM_LAN=true` | The first person to post the setup form becomes the owner. |
| Importing the old data | Nothing stopped a run against the old app's live file | `nuna:import-sqlite` and `nuna:reconcile` refuse `NUNA_OLD_LIVE_DATABASE` and any file with a `-wal`/`-shm` beside it | A live SQLite file may be mid-write; copy it while the old app is stopped. |
| Editing a saved document | A stock adjustment, expense, income, purchase or sale could only be voided and entered again | The owner can edit one that is not voided and is in an open period, giving a reason. On its own day the old stock and entry are reversed and the corrected document posted under the same number; the old version stays in its edit history. An edited sale keeps what its goods cost on its own day; only units it adds are costed at today's average. Refused while a return or payment is set against it, or a purchase's stock has gone out since | PLAN D34, approved by the owner 2026-11-09. |
| Date of a customer return or a customer payment | Any date, past or future, for anyone who could record one | The sale's rule: never in the future, an earlier day only with the edit right (`sales` for a return, `customers` for a payment) and never inside the books lock; a return is never dated before its sale | Owner decision 2026-11-12 (fourth audit, finding 10). A cash refund dated back left today's drawer short with no report for the day showing why. |
| Printed year-end pack | Counted NHIL, GETFund, customer credits, supplier prepayments and last year's expense accounts in its totals without a line for them, so the lines did not add up | Every line its totals count is printed, in both years' columns; the CSV, which already had the balance-sheet lines, adds last year's expense accounts | Owner decision 2026-11-12 (fourth audit, finding 13). The accountant is handed a statement whose lines add up. |
| Date of a cash count, an expense, an income or a transfer | Any date, future included | Never after today (the shop's day) | Owner decision 2026-11-12 (fourth audit, finding 14). The latest count is picked by date, so a count mistyped into next week hid today's real shortage; a void dated today could land before its own original. |
| Date of a stock adjustment | Any date, future included, when recorded or edited | Never after today (the shop's day), when recorded or edited | Owner decision 2026-11-12: no future-dated money. An adjustment moves stock value in the books, as an expense moves cash. |
| Date of a purchase | Any date, future included, when recorded or edited | Never after today (the shop's day), when recorded or edited | Owner decision 2026-11-12: no future-dated money. A purchase puts stock and a supplier debt in the books, as a supplier payment moves cash. |
| A save sent after the sign-in ended | Lost what was typed: the page reloaded or went to sign-in empty | What was typed is kept in that browser tab; signing in again as the same person returns to the page and puts it back to check and save. Signing in as someone else does not get it | Owner request 2026-11-12. Covers signed out or switched user in another tab, a password or access change, and a week idle. |
| Quotes filtered as Expired | Included a quote valid until today (`<=`), though it still converted without a warning | Only quotes whose valid-until day has passed (`<`), the same rule as conversion | Owner decision 2026-11-12 (fourth audit, finding 17). A quote is good through its last day. |
| Year-end pack for someone with reports view only | Showed, and its CSV gave, every customer's and supplier's balance and the full trial balance | The statements only; the customer and supplier lists, the trial balance and the CSV need accounts view as well | Owner decision 2026-11-12 (fifth audit, finding 3), as for the receivables and payables files (2026-11-07). |
| Tax return "Sales the tax was charged on" / "Purchases the tax was paid on" | Added up every sale or purchase in the period, taxed or not | Only documents carrying a tax line, value and document count alike (voids and returns carry their own) | Owner decision 2026-11-12 (fifth audit, finding 8). The box is the base the tax was worked out on; `nuna:reconcile` reads the old figures by the same rule. |
| A delivery that takes oversold stock past zero | Kept the fallback-cost gap in the stock value: after overselling 3 at a 1.50 fallback, receiving 4 at 3.00 left 1 unit worth 7.50 | Keeps only what is left of the delivery, at its own cost (1 unit worth 3.00); the gap goes to cost of goods, as it already did when a delivery landed exactly on zero (D26) | Owner decision 2026-11-12 (fifth audit, finding 12). Only with negative stock allowed; the shop's own data never went below zero. |
| A customer return of oversold goods after a delivery cleared them | Put them back at the fallback price the sale recorded: 2 tins at 1.00 and 3 oversold at a 1.50 fallback, cleared by a delivery at 3.00, came back worth 6.50 | Oversold units come back at what the delivery that cleared them cost (11.00), so the shelf and cost of goods match what was paid; voiding such a sale or stock adjustment is refused, as editing one is | Owner decision 2026-11-12 (fifth audit follow-up) |

### Owner decisions pending

Old-app behaviour the port keeps on purpose but that may give a wrong figure or message is listed in [PLAN.md §10](PLAN.md#10-owner-decisions-old-app-money-behaviour-kept-for-now) (D1 to D24). All 24 are now decided (2026-11-06): D12 and D19 kept, the rest changed and listed above.

## Documentation
- [Build plan](PLAN.md)
- [Product requirements](docs/PRD.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Data model](docs/DATA_MODEL.md)
- [API](docs/API.md)
- [Roadmap](docs/ROADMAP.md) · [Tasks](docs/TASKS.md)
- [Security](SECURITY.md)
- Agents: [AGENTS.md](AGENTS.md)

## License
Proprietary (TBD).
