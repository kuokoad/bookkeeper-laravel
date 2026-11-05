# AGENTS.md

Instructions for AI coding agents working in this repo.

## Project
NunaBooks is bookkeeping, inventory and point-of-sale software for a small Ghanaian retail shop. It is being rebuilt from Next.js + SQLite + Drizzle (`C:\ClaudeProjects\bookkeeper`, the **specification**) into Laravel + MySQL 8 here. Phase: planning. [PLAN.md](PLAN.md) awaits the owner's approval, and no code is written until it is approved. See [docs/PRD.md](docs/PRD.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

**The bar:** the product is the figures. The question is never "does it run" but "would a shop owner be right to trust this number". A plausible wrong figure is worse than a missing one. When the old app and the new code disagree on a number, the old app is right until you prove otherwise.

## Commands
Planned; none of these exist until Phase 0 is built.

| Task | Command |
|---|---|
| Install | `composer install` then `npm ci && npm run build` |
| Dev server | Laragon (`http://bookkeeper-laravel.test`) or `php artisan serve` |
| Test (all) | `php artisan test` (needs MySQL database `nunabooks_test`) |
| Test (single) | `php artisan test --filter=<name>` |
| Lint / format | `vendor/bin/pint --test` (check) · `vendor/bin/pint` (fix) |
| Build assets | `npm run build` |
| DB migrate | `php artisan migrate` · fresh: `php artisan migrate:fresh --seed` |
| Import old data | `php artisan nuna:import-sqlite <path to a COPY of bookkeeper.db>` |
| Prove figures match | `php artisan nuna:reconcile` (pass = zero differences) |
| Backup / restore | `php artisan nuna:backup` · `php artisan nuna:restore <file>` |

Dev PC tools: Laragon at `C:\laragon` (PHP 8.4.19 / 8.5.4, MySQL 8.4.0, Composer). PHP and MySQL are not on the Git Bash `PATH`; use Laragon's terminal or full paths.

## Code map
- `app/Domain/`: pure money, quantity, rate, tax, costing, batches, journal builder, dates. No DB, no clock.
- `app/Services/`: one per old `src/services/*.service.ts`. Owns transactions, locks, journal posting, audit.
- `app/Livewire/`, `app/Http/Controllers/`: screens and CSV/receipt routes. No business logic.
- `app/Support/Filters/`: one filter parser per module, used by both the page and its CSV export.
- `app/Enums/`: one PHP enum per database `CHECK ... IN` list.
- `database/migrations/`, `database/seeders/`: schema and seed data.
- `resources/docs/`: in-app Help, ported from the old `docs/`.
- `tests/Domain`, `tests/Services`, `tests/Invariants`, `tests/Concurrency`: mirror the old `tests/`.

## Conventions
- **Money is `int` pesewas** (`GHS 1,250.00` = `125000`). **Quantity is `int` milli-units** (`1.5 kg` = `1500`). **Rates are basis points.** Columns are `BIGINT`, never `DECIMAL`, `FLOAT` or `DOUBLE`. No float ever touches a stored value. Multiplication that can overflow goes through `brick/math` `BigInteger`. Rounding is half away from zero (`divRoundHalf`), matching the old `domain/money.ts` to the pesewa.
- **Weighted average by a running pair** `(Q, V)`. Selling `q` costs `round(V × q / Q)`; selling the last unit forces `V → 0`. No stored unit cost.
- **Double entry, nothing stored.** No balance columns. Every journal entry balances inside the same `DB::transaction` as its business row, carries `source_type` + `source_id`, and is never edited. Corrections are linked reversals.
- **Locks.** Anything that reads stock, a running pair, a sequence or a balance and then writes based on it uses `lockForUpdate()` inside `DB::transaction`. Lock order: products (by id), batches, then sequences. Keep the sale idempotency key (`sales.client_ref`).
- **Dates.** Trading dates are `DATE` in `Africa/Accra`, inclusive at both ends. Timestamps are `DATETIME(3)` UTC. Never filter trading on a timestamp.
- **Filtering** (all six, from the old CLAUDE.md): parse once per module and share it with the CSV export; filter in SQL before the limit; totals use the same conditions as the rows; validate every id, enum, date, amount and sort key (whitelist sort keys); controls show the resolved value; reads never write.
- **SQL:** always qualify columns inside subqueries (`sales.id`, not `id`). Search uses `LOWER(col) LIKE LOWER(?)` with escaped wildcards. Never write the generated columns (`*_lower`, `open_start_year`).
- **`updated_at`** has `ON UPDATE CURRENT_TIMESTAMP`. Where an old service set `updatedAt` to a specific moment (inventory does), set it explicitly so the value matches.
- **Tests:** port the old test first, then the code. Assert what a shop owner would check, in minor units (`5_000` is GHS 50.00). Every fixed bug gets a test named for the behaviour that broke. Run tests against real MySQL, never SQLite.

## Guardrails
- **Never edit, migrate, seed or run anything that writes in `C:\ClaudeProjects\bookkeeper`.** Never open its `data/bookkeeper.db` directly; import from a copy.
- Never commit secrets; config comes from `.env` (see `.env.example`). Never copy values from the old `.env`.
- Never reset or bulk-rewrite password or PIN hashes. Legacy scrypt hashes are rehashed one at a time on successful sign-in.
- Never delete financial history, audit rows or users.
- When the old code's intent is unclear, **ask the owner rather than guess, especially about money.**
- Before saying a task is done: `php artisan test`, `vendor/bin/pint --test`, and for figure-affecting work `php artisan nuna:reconcile`.
- Browser checks use the Playwright CLI only; screenshots go to `screenshots/`.

## Workflow
- Planning docs live in `docs/`; the build plan is [PLAN.md](PLAN.md). Tick items in [docs/TASKS.md](docs/TASKS.md) as they finish.
- Small commits, one module at a time: port tests → code → compare figures with the old app.
- Commit messages: conventional style (`feat:`, `fix:`, `docs:`, `test:`), matching the old repo.
