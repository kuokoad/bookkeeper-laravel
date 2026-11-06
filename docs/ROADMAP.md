# Roadmap: NunaBooks (Laravel + MySQL)

**Last updated:** 2026-11-05 · Status key: ⬜ not started · 🟡 in progress · ✅ done

Every phase follows the same loop: port the old tests → write the code → compare figures with the old app → commit. Details: [../PLAN.md](../PLAN.md) §3. No target dates have been set (TBD).

## Phase 0: Foundation ✅
**Goal:** an empty MySQL database gets the full schema, with every rule the old one enforced.
- Laravel skeleton, MySQL in strict mode with UTC and `utf8mb4_0900_bin`, Pest on a real MySQL test DB, Pint (PRD FR-1)
- All 42 migrations with CHECKs, triggers and functional unique indexes
- Seeders: chart of accounts, sequences, units, tax components, payment accounts (demo data arrived in Phase 6, once the services it records through existed)

**Exit criteria:** `php artisan migrate:fresh --seed` works on an empty database; each CHECK is shown to refuse a bad row.

## Phase 1: Domain ✅
**Goal:** the pure money, quantity, tax and costing maths matches the old app to the pesewa.
- Money, Quantity, Rate, TaxComponents, Calculate, Costing, Batches, Journal, PeriodLock, FinancialYear, BusinessDate, YearEndClose

**Exit criteria:** all 13 old `tests/domain` files ported and green.

## Phase 2: Ledger core ✅
**Goal:** balanced, gap-free, locked, audited postings.
- JournalService, SequenceService, PeriodLockService, AuditService, SettingsService, TaxService
- First cut of `nuna:import-sqlite` and `nuna:reconcile` (trial balance)

**Exit criteria:** period-lock, settings and tax tests green; reconcile passes on the trial balance of imported data.

## Phase 3: Catalog and inventory ✅ (PRD FR-2, FR-3)
- Products, units and cartons, categories, the stock gateway, adjustments, batches and expiry, import, replace, delete vs archive

**Exit criteria:** catalog and inventory tests green; reconcile passes on `(Q, V)` per product.

## Phase 4: Sales ✅ (FR-4 to FR-7)
- Till, credit sales and invoices, backdating, returns, voids, refunds, customer payments, overpayment, quotations

**Exit criteria:** sales tests green; the two-till concurrency test passes; reconcile passes on receivables.

## Phase 5: Purchases ✅ (FR-8)
- Suppliers, deliveries, supplier returns and voids, supplier payments

**Exit criteria:** purchase tests green; reconcile passes on payables.

## Phase 6: Cashbook ✅ (FR-9, FR-10)
- Payment accounts, expenses, income, capital and drawings, transfers, reconciliation
- Demo data (`SEED_DEMO_DATA=true`), checked against the old app's own demo database

## Phase 7: Reports ✅ (FR-11 to FR-13)
- Trial balance, P&L, balance sheet, cash flow, ledger, ageing, statements, tax return, dashboard, year-end pack and close, CSV exports

**Exit criteria:** report and invariant tests green; reconcile passes on the P&L, balance sheet and tax payable for every year.

## Phase 8: Users, settings and shell 🟡 (FR-14 to FR-16)
- ✅ Users, permissions, PINs, legacy hash upgrade, sign-in limits, search, notifications, backup and restore, preflight, installer, update (services, gates, middleware, commands, scripts)
- ✅ Settings screen, logo upload, looks, help pages: screens, with Phase 9

## Phase 9: Proof and cut-over 🟡
- ⬜ Reconcile shows zero differences on a copy of real data (waiting for a copy of the shop database; zero differences on the old demo database)
- ✅ Backup restored into an empty DB with matching reports (BackupRestoreTest; nuna:backup and nuna:restore run on the demo shop 2026-11-06, verified)
- ✅ Playwright click-through with screenshots (`screenshots/final/`, 96 files, all modules passed, figures agree)
- ✅ README and ARCHITECTURE list every behaviour difference

**Exit criteria:** the "Done means" list in [../PROMPT.md](../PROMPT.md) is all true.

## Later / ideas
- HTTPS on the LAN; off-site backup sync; FIFO costing behind the costing interface (old §19 trade-offs).

Tasks for each phase: [TASKS.md](TASKS.md) · Requirements: [PRD.md](PRD.md)
