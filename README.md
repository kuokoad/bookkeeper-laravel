# NunaBooks (Laravel + MySQL)

Bookkeeping, inventory and point of sale for a small Ghanaian retail shop. It runs on the shop's own PC, with no internet needed to make a sale. This is a rebuild of the working Next.js + SQLite app in `C:\ClaudeProjects\bookkeeper` (the specification) in Laravel + MySQL 8.

> **Status: Phase 0 done** (schema, seed, tests). No services or screens yet. [PLAN.md](PLAN.md) is approved; progress is in [docs/TASKS.md](docs/TASKS.md).

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
- Node, for building front-end assets on the dev PC only

### Setup
```bash
composer install
cp .env.example .env && php artisan key:generate
```

Create the two databases with the binary collation, so text compares the way it did in the old SQLite app:

```sql
CREATE DATABASE nunabooks CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE DATABASE nunabooks_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
```

```bash
php artisan migrate:fresh --seed
```

Creating the schema's triggers needs a MySQL user with the `TRIGGER` privilege (root on the dev PC; the shop installer will grant it to the app user).

### Environment
| Variable | Purpose |
|---|---|
| `DB_*` | MySQL connection (app user) |
| `DB_BACKUP_*` | MySQL user for `mysqldump` |
| `SEED_DEMO_DATA` | Seed demo shop data (refused in production) |
| `SESSION_SECURE_COOKIE` | `true` only behind HTTPS |
| `TRUST_PROXY_HEADERS` | Per-IP login throttle behind a proxy |

### Run
No screens yet.

### Test
```bash
php artisan test
vendor/bin/pint --test
```

### Prove it matches the old app
Planned from Phase 2: `php artisan nuna:import-sqlite <copy of bookkeeper.db>`, then `php artisan nuna:reconcile`.

## Project structure
```
app/Domain/      pure money, quantity, tax, costing maths (no DB)
app/Services/    one per old service; transactions, locks, journal, audit
app/Livewire/    screens
database/        migrations, seeders
resources/docs/  in-app Help (ported from the old docs/)
tests/           Domain, Services, Invariants, Concurrency
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
| Unit rename | `ON UPDATE CASCADE` to products | No cascade; the service will rename in one transaction | MySQL forbids a CHECK on a column with an FK action (PLAN Q6). |
| Sessions | Hashed tokens in `sessions` | Laravel's session table; old sessions not imported | PLAN Q7. |
| Text comparison | SQLite BINARY: exact, trailing spaces count | `utf8mb4_0900_bin` (binary, NO PAD): the same | `utf8mb4_bin` would treat `'X1'` and `'X1 '` as equal. |
| Case-insensitive uniqueness (usernames, SKUs, category, unit and payment-account names) | `lower()` folds ASCII only | MySQL `LOWER()` folds all Unicode letters, so "Éclair" and "éclair" now collide | Closer to what a person means. The importer will report any old rows that clash. |
| Column widths | SQLite text has no length | Names and codes sized to what the old CSV import accepted (1,000 characters; 768 where a key needs it) | MySQL needs a length; anything longer will be reported by the importer, not truncated. |
| `audit_logs.user_id`, `users.created_by` | Plain integers | Foreign keys with RESTRICT | Real FKs; RESTRICT because an FK action would rewrite audit rows without firing the insert-only triggers. |
| Demo data | `SEED_DEMO_DATA=true` seeds a demo shop | Refused with a message until the sales, purchase and cashbook services exist | The old demo seed records trading through those services. |

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
