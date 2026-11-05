# Rebuild NunaBooks in Laravel + MySQL

You are rebuilding **NunaBooks**, a working bookkeeping, inventory and point-of-sale
app for a small Ghanaian retail shop, from Next.js + SQLite + Drizzle into
**Laravel (latest stable) + MySQL 8**, in `C:\ClaudeProjects\bookkeeper-laravel`.

The existing app is the specification. It lives in `C:\ClaudeProjects\bookkeeper`.
Treat it as read-only: never edit, migrate or seed anything there, and never touch
its `data/bookkeeper.db`.

## The bar

The product is the figures. The question is never "does it run", it is "would a
shop owner be right to trust this number". A plausible wrong figure is worse than a
missing one, because nobody goes looking for it. When the old app and your new code
disagree on a number, the old app is right until you prove otherwise.

## Read before writing any code

In `C:\ClaudeProjects\bookkeeper`:

1. `ARCHITECTURE.md`, all of it. Especially §2 layering, §3 money and quantity
   precision, §4 double entry, §6 workflows, §7 permissions, §9 testing, §14 year-end
   close, §17 overpayment.
2. `README.md`, "Where things live", "How the money works", "Closing the books",
   "Closing the year", "Backups", "Security notes".
3. `CLAUDE.md`, the filtering rules and the traps.
4. `src/db/schema/*.ts`, the real data model, including every CHECK constraint
   (`_check.ts`).
5. `src/domain/` (money, quantity, rate, tax, inventory, accounting, business date,
   financial year) and `src/services/` (one service per module).
6. `tests/`. The 66 files in `tests/services/` are the behaviour you must keep. Each
   one names a bug that was fixed. Read them as acceptance criteria.
7. `git log` in the old repo. The recent fixes are the subtle rules, and each one is
   easy to lose in a rewrite: carton (pack) sizes that lock once stock moves, and
   replacing a locked product with one in new units; refusing a carton entry made at
   a size that has since changed; a stock adjustment entered as a unit cost or a total
   value; a backdated sale taking only stock that had arrived by its date; a return
   refunding no more than was paid, coming off the sale it belongs to, and reversing
   goods only once; void mirrors that are not debts, and tax that is not profit;
   unused duplicate products deleted while used ones are archived; transfers between
   accounts.
8. `docs/` (how-to, reference, explanation, tutorials). These are served as in-app
   Help, so carry them over and update any step that changes.

Then write `PLAN.md` in the new folder: the module list, the order you will build
them in, and how each old table maps to a MySQL table. Stop and show me the plan
before building.

## Non-negotiable rules carried over

**Money and quantity are integers.** Money in pesewas (`GHS 1,250.00` = `125000`),
quantity in milli-units (`1.5 kg` = `1500`). MySQL columns are `BIGINT`, never
`DECIMAL` for stored values and never `FLOAT`/`DOUBLE`. In PHP use `int` (64-bit) and
`intdiv`; any multiply that can overflow goes through `brick/math` `BigInteger`, the
equivalent of the old `BigInt` path in `domain/money.ts`. No float ever touches a
stored value. Rounding rules must match the old app to the pesewa.

**Weighted average cost by a running pair**, not a stored unit cost: each product
holds total quantity `Q` and total value `V`. Selling `q` costs `round(V × q / Q)`;
selling the last unit forces `V → 0`. Port this exactly, along with the batch / expiry
handling and carton (pack size) units.

**Double entry, every balance derived.** Same chart of accounts and codes (§4). No
stored balance columns. Every journal entry is balanced (`Σ debit = Σ credit`)
inside the same DB transaction as the business record, or the whole thing rolls back.
Every entry carries `source_type` + `source_id`.

**Database rules stay in the database.** MySQL 8.0.16+ enforces `CHECK`, so port every
CHECK: enums, `debit >= 0`, `credit >= 0`, exactly one non-zero, `normal_balance`
agrees with `type`, `source_id` required except opening balances. InnoDB, real
foreign keys, `utf8mb4`, strict SQL mode on.

**Concurrency.** SQLite serialised writes for free; MySQL does not. Anything that
reads stock, a running pair, a sequence number or a balance and then writes based on
it must lock (`lockForUpdate()` inside `DB::transaction`). Invoice/receipt numbering
must stay gap-free and unique under two tills selling at once. Keep the sale
idempotency key.

**Dates.** Trading dates are a business date (`DATE` column, `Africa/Accra`), never a
timestamp. Ranges are inclusive at both ends. Period locks and year-end close refuse
writes into a closed period.

**Filtering** (from `CLAUDE.md`, keep all six): parse once per module and share it
between page and CSV export; filter in SQL before the limit; totals come from the
same query conditions as the rows; validate every id, enum, date, amount and sort key
(a sort key reaches `ORDER BY`, so whitelist it); controls show the resolved value,
not the raw parameter; reads never write.

**Permissions.** Same roles and per-module permission matrix. Users are switched off,
never deleted. Every write is audited. Login throttling, session expiry and PIN rules
as in `src/lib/auth/`.

## Things that change because the stack changes (decide each in PLAN.md)

- **Running it in the shop.** The old app is local-first: one process and one SQLite
  file on the shop PC, no internet needed, phones on the shop WiFi. MySQL is a server
  the owner cannot look after. Propose how it gets installed, started on boot and
  updated on Windows (for example Laragon, or MySQL as a Windows service plus
  `php artisan serve` / a small web server), and keep "no internet needed to make a
  sale" true.
- **Backups.** "Copy one file" is no longer true. Replace `services/backup.service.ts`
  and the `/api/backup` download with a consistent `mysqldump --single-transaction`
  (or an equivalent) plus a tested restore, and rewrite `docs/how-to/back-up-and-restore.md`.
- **Passwords and PINs.** The old app hashes with scrypt (`src/lib/auth/password.ts`,
  its own stored format). Laravel uses bcrypt or argon2. Imported users must still be
  able to sign in: verify the old scrypt hash once, then rehash it in Laravel's format
  on that first successful login. Never reset or weaken a hash in bulk.
- **Timestamps.** Old `created_at` / `updated_at` are integer milliseconds
  (`timestampMs` in `src/db/schema/_shared.ts`). Store them as `DATETIME(3)` in UTC and
  convert on import. Business dates stay `DATE`.
- **Next.js-only parts.** `AGENTS.md`, `next dev` and the App Router do not come over.
  Laravel's own conventions replace them; check the installed Laravel version's docs
  instead of relying on memory, as its APIs change between releases.

## Stack choices

- Laravel with Blade + Livewire for the screens (or Inertia if you can justify it in
  the plan). Tailwind for styling. Keep the old app's layout, wording and light/dark
  themes; it does not have to be pixel-identical.
- Eloquent is fine for CRUD. Money postings, costing and reports live in plain
  service classes (`app/Services`, `app/Domain`) mirroring the old `services/` and
  `domain/` split, so the layering in ARCHITECTURE.md §2 survives.
- Raw or query-builder SQL for reports is fine; always qualify columns
  (`sales.id`, not `id`) inside subqueries.
- Pest or PHPUnit, against a real MySQL test database (not SQLite in-memory: the point
  is to test MySQL behaviour).

## Build order

1. Schema migrations + seeders (chart of accounts, tax components VAT/NHIL/GETFund,
   demo data equivalent to `src/db/seed/`).
2. `Domain` layer: money, quantity, rate, tax breakdown, costing. Port the domain
   tests first and make them pass.
3. Journal service and period lock.
4. Catalog, inventory, stock adjustment, product import / replace.
5. Sales (till, credit sales, returns, voids, refunds, overpayment), customers,
   customer payments, quotations.
6. Purchases, suppliers, supplier payments.
7. Cashbook (income, expenses, drawings, transfers), payment accounts, reconciliation.
8. Reports: trial balance, P&L, balance sheet, ageing, statements, tax return,
   dashboard, year-end pack, year-end close. CSV exports.
9. Users, roles, settings, logo upload, search, notifications, backup.

For each module: port its tests, then the code, then compare figures.

## Proving it matches

- Write a one-off importer (`php artisan nuna:import-sqlite path/to/bookkeeper.db`)
  that copies a **copy** of the old SQLite database into MySQL unchanged.
- Write `php artisan nuna:reconcile` that, on the imported data, recomputes trial
  balance, stock `Q`/`V` per product, receivables, payables and tax payable, and
  checks they equal the old app's reports for the same dates. Zero differences is the
  only pass.
- Every old test in `tests/services/` has a Laravel equivalent that asserts the same
  shop-owner-visible numbers in minor units.

## Done means

- `php artisan test` green, `vendor/bin/pint --test` clean, `php artisan migrate:fresh
  --seed` works on an empty MySQL.
- `nuna:reconcile` reports zero differences on imported real data.
- Two tills selling the last unit of the same product at the same moment cannot both
  succeed, and invoice numbers stay gap-free. Prove it with a test that runs two
  connections at once.
- A backup has been taken and restored into an empty database, and the restored
  reports match the original.
- You have clicked through each module in a browser (Playwright CLI, screenshots in
  `screenshots/`) and the figures on screen match the old app for the same data.
- A short `README.md` and `ARCHITECTURE.md` in the new project, stating anything that
  behaves differently from the old app and why.

Work in small commits, one module at a time. When the old code's intent is unclear,
ask me rather than guess, especially about money.
