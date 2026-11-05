# Tasks

**Last updated:** 2026-11-05 · Legend: `[ ]` todo · `[~]` in progress · `[x]` done

Each "port X" task means: the Laravel test asserts the same shop-owner-visible figures, in minor units, as the old `tests/services/X.test.ts` (or the `tests/domain/` / `tests/app/` / `tests/lib/` / `tests/db/` file named).

## Blocked
- [ ] Demo data seeder: needs the sales, purchase and cashbook services (Phases 4–6).

## Phase 0: Foundation
- [x] Create the Laravel project: Laravel 13.40.0 on PHP 8.4.19
- [x] MySQL connection: strict mode, `time_zone '+00:00'`, `utf8mb4_0900_bin`, InnoDB; test DB `nunabooks_test`
- [x] Pest + Pint set up: `php artisan test` and `vendor/bin/pint --test` run clean
- [x] Enums for every CHECK list (`app/Enums`)
- [x] 42 tables per [DATA_MODEL.md](DATA_MODEL.md), including self-reference triggers, the audit-log insert-only triggers, `open_start_year` and the `LOWER()` unique indexes
- [x] Schema tests: each of the 161 CHECKs refuses a bad row by name; triggers; parity with the old tables, CHECKs and unique indexes
- [x] Core seeder: settings, chart of accounts, sequences (15 prefixes), units, tax components, payment accounts (port of `seedCore`)
- [ ] Demo data equal to `src/db/seed/demo*.ts` (blocked, see above); demo seeding is already refused in production
- [x] Port `tests/db/foundation` (schema and seed parts; its numbering tests move to SequenceService in Phase 2)
- [ ] Port `tests/db/demo-seed` (with the demo seeder), `transaction-behaviour` (Phase 2); `units-migration` is not ported (see Phase 3)

## Phase 1: Domain
- [x] Port and pass `money`, `quantity`, `rate` (all 10,001 rates round-trip)
- [x] Port and pass `tax-components` (including the -5,000 to 5,000 sweeps), `sale-calculate`, `sale-present`
- [x] Port and pass `costing`, `costing-properties` (200 seeded sequences), `batch-allocation`
- [x] Port and pass `journal`, `year-end-close`, `financial-year`, `business-date`
- [x] Domain errors, books lock and sale-date rules
- [x] Structural test: `app/Domain` uses no framework, database, clock or I/O
- [x] `allocate()` matches the old output across the weight range (PLAN Q4)

## Phase 2: Ledger core
- [x] JournalService: balanced, period-locked (shared lock on the lock date), leaf accounts only, reversal links (original locked)
- [x] SequenceService: gap-free under rollback; refuses outside a transaction
- [x] Concurrency: four processes taking numbers and posting at once; numbers run with no gaps or repeats despite rollbacks (tests/Processes/ConcurrencyTest.php)
- [x] Structural test: services open transactions only through `App\Database\Tx`
- [x] AuditService: insert-only, redaction, filters and counts share conditions
- [ ] Port `audit-summary-money` (sale, customer-payment, purchase and supplier-payment parts done in Phases 4 and 5; the till shortage waits for Phase 6)
- [x] SettingsService, TaxService: port `settings`, `tax-setup`, `tax-components-crud`, and the journal-level and lock-management parts of `period-lock`
- [ ] Port the per-document parts of `period-lock` (sale, adjustment and purchase done; expense waits for Phase 6)
- [x] `nuna:import-sqlite` (all tables, ids kept, timestamps to UTC, refuses a non-empty target, FK checks off then its own orphan check, skips generated columns, lists refused rows and commits nothing)
- [x] `nuna:reconcile` v1: rows per table, trial balance per account, stock pair per product (cache and replay), receivables, payables, tax held. Zero differences on the old app's own demo database (tests/Fixtures/old-demo.db)

## Phase 3: Catalog and inventory
- [x] Port `inventory`, `catalog-lookup`, `product-delete`, `units`, `module-filters` (the parts that need sales, purchases, quotations or the stock-valuation report are listed in each file and wait for Phases 4, 5 and 7)
- [x] Port `batch-gateway`, `batch-integrity`, `batch-reporting`, `stock-integrity-check`, `stock-ledger-dates`, `costing-vs-ledger` (same: purchase/sale-driven cases and batch search wait for their services)
- [x] Port `product-import` and `product-replace-unit`; `tests/lib/spreadsheet` (CSV and XLSX, zlib only)
- [ ] `tests/app/product-import-action`, `product-replace-action`, `product-replace-guards`: they test the screen actions and their permission guards, so they wait for the screens (and the Phase 8 route-guard test)
- [x] Carton locks and stale-size refusal at the service (port of the write-off part of `tests/app/carton-size-actions`); the till, delivery and quote screen parts wait for Phases 4 and 5
- [x] Adjustment by unit cost or total value (`App\Support\CostFields`, port of `tests/app/adjustment-cost-fields`); `tests/ui/adjustment-form` waits for the screen
- [x] Reconcile: `(Q, V)` per product + full replay (Phase 2 reconcile; `tests/Processes/ImportTest` now also runs the inventory service checks on the imported shop)
- [x] `units-migration` not ported: it tests the old SQLite migration 0025 on an old schema, which this repo replaces with its own schema and the importer

## Phase 4: Sales
- [x] `SaleService`, `ReturnsService` (customer half; the supplier half waits for Phase 5), `CustomerService`, `CustomerPaymentService`, `QuotationService`; sales, quotation and customer filter parsers
- [x] Port `sales`, `sales-tax`, `sale-idempotency`, `sale-price-override`, `invoicing`, `sale-line-earnings`, `sale-profit-tax`, `sales-filters` (reads never write), `top-product` (report figures from journal sums or a test-side copy of the old report read until Phase 7; each file header says which)
- [x] Port `sale-batches-by-date`, `sale-expiry`, `tests/app/sale-date-action` (service parts; the action and its responses wait for the screens)
- [x] Port `sale-void`, `sale-void-reporting`, `void-ageing` (the payables-ageing block waits for Phase 5)
- [x] Port `refund-cap`, `refunds-after-returns`, `credited-returns`, `return-tax` (customer side; supplier cases wait for Phase 5, "ages the credit with the sale it came off" for Phase 7)
- [x] Port `payment-allocation`, `payment-void` (supplier case waits for Phase 5), `overpayment`, `receivables-ageing`
- [x] Port `quotations`, `tests/app/quotation-convert-date` (service part)
- [x] Un-defer the sale, customer, return and quote parts of `units`, `product-delete`, `product-replace-unit`, `module-filters`, the sale part of `period-lock`, and the sale and customer-payment parts of `audit-summary-money` (purchase parts wait for Phase 5, reports for Phase 7)
- [x] Concurrency (`tests/Processes/SaleConcurrencyTest.php`): four tills sell the last unit at once, five times over: one sale each, the rest refused, stock 0 and value 0, receipts gap-free; the same cart from four tills at once: one sale, one movement, one entry, the same receipt for all. Each test was run once with its lock removed and failed
- [ ] Receipt, invoice and statement pages (port `tests/app/print`, `document-figures`): wait for the screens
- [x] Reconcile: receivables per customer (ledger, since Phase 2); `ImportTest` also checks that the imported shop's open invoices add up to each customer's ledger balance through the new services

## Phase 5: Purchases
- [x] `PurchaseService`, `SupplierService`, `SupplierPaymentService`, the supplier half of `ReturnsService` (duplicate-line guard, decides on locked lines); `App\Domain\Purchases\PurchaseCalculator`; purchase and supplier filter parsers
- [x] Port `purchases`, `purchase-tax`, `tests/app/purchase-form-parity` (service part; the form's own parsing waits for the screens)
- [x] Free-goods delivery, non-stock lines (6900), costing residual on void
- [x] Un-defer the purchase and supplier parts of `refund-cap`, `refunds-after-returns`, `credited-returns`, `return-tax`, `void-ageing` (payables, via a test-side copy of the old payables ageing), `payment-void`, `units`, `batch-gateway`, `batch-reporting`, `costing-vs-ledger`, `product-replace-unit`, `carton-size-actions` (delivery), `module-filters`, `period-lock` and `audit-summary-money`
- [x] Concurrency (`tests/Processes/PurchaseConcurrencyTest.php`): four desks return the same delivery line at once, five times over: one return each, stock out once, refund once, return numbers gap-free; a void racing a supplier payment on one delivery: exactly one goes through, the supplier owes nothing either way. Each test was run with its locks removed and failed (all of a return's locks together: the product and supplier locks serialise the desks before the line lock is reached, so the test proves the return is serialised, not which lock does it; for the void race, removing the void's locks, or the payment's supplier lock, fails it)
- [x] Reconcile: payables per supplier (ledger, since Phase 2); `ImportTest` also checks that the imported shop's open deliveries add up to each supplier's ledger balance through the new services

## Phase 6: Cashbook
- [ ] Port `cashbook`, `transfers` (+ `tests/app/transfer-action`), `reconciliation`, `account-statement`, `list-order-by-trading-date`
- [ ] Payment accounts own a GL leaf (next free code, locked)

## Phase 7: Reports
- [ ] Port `accounting-reports`, `financial-reports`, `report-filters`, `report-invariants`, `receivables-ageing`, `tax-return`, `dashboard`
- [ ] Port `year-end`, `year-end-close`, `year-end-ageing-as-at`, `year-end-check-line`
- [ ] CSV exports share each page's filter parser (port `tests/lib/csv`, `filters`, `tests/app/account-export*`, `sales-export-profit`)
- [ ] Invariant tests (old ARCHITECTURE §9, items 1–5)
- [ ] Reconcile: P&L, balance sheet, tax payable, ageing for every financial year

## Phase 8: Users, settings and shell
- [ ] Port `auth`, `privilege`, `users`, `tests/lib/password`, `rate-limit`, `http-throttle`
- [ ] Validate the client address taken from proxy headers before it reaches `rate_limits.key` / `audit_logs.ip_address` (the old app took it unchecked)
- [ ] `LegacyScrypt`: RFC 7914 vectors + old-app hashes; rehash on first sign-in; measure time on the shop PC
- [ ] Route guard test (replaces `page-guards`, `action-guards`)
- [ ] Settings screen, logo upload (port `tests/lib/image`), looks and light/dark (port `look`, `theme`, `motion`)
- [ ] Port `search`, `notifications`, `backup-status`, `update`, `preflight-money`
- [ ] Help pages from `resources/docs` (port `tests/lib/help`, `markdown`); carry over `docs/how-to`, `reference`, `explanation`, `tutorials`
- [ ] `nuna:backup` / `nuna:restore` / `nuna:preflight`; rewrite `how-to/back-up-and-restore.md` (port `tests/db/backup`, `restore`, `preflight`)
- [ ] Windows installer and update scripts (PLAN Q1)

## Phase 9: Proof and cut-over
- [ ] `nuna:reconcile` reports zero differences on a copy of the real shop data
- [ ] Backup → restore into an empty DB → reports match
- [ ] Playwright CLI click-through of every module, screenshots in `screenshots/`, figures match the old app
- [ ] README and ARCHITECTURE list every behaviour difference and why

## Backlog
- [ ] HTTPS on the LAN
- [ ] Off-site backup copy

Phases defined in [ROADMAP.md](ROADMAP.md).
