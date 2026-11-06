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
- [x] Port `audit-summary-money` (sale, customer-payment, purchase and supplier-payment parts in Phases 4 and 5; the till shortage in Phase 6)
- [x] SettingsService, TaxService: port `settings`, `tax-setup`, `tax-components-crud`, and the journal-level and lock-management parts of `period-lock`
- [x] Port the per-document parts of `period-lock` (sale, adjustment and purchase in Phases 4 and 5; expense in Phase 6)
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
- [x] `CashbookService` (expenses, income, capital and drawings, voids, lists, totals, by day, by category), `TransferService`, `PaymentAccountService` (accounts, categories, balances, statement, movements, source types), `ReconciliationService`; cashbook and account-statement filter parsers; `Money::average` (the old Math.round)
- [x] Port `cashbook`, `transfers` (+ the service parts of `tests/app/transfer-action`; the action's form state, redirect and permission checks wait for the screens), `reconciliation`, `account-statement`, `list-order-by-trading-date` (the profit, P&L and cash-flow figures from journal sums or a test-side copy of the old read until Phase 7; each file header says which)
- [x] Payment accounts and categories own a GL leaf at the next free code in the heading's block, the heading row locked; archiving and counting lock the account and read its balance after the lock
- [x] Un-defer the expense parts of `module-filters` and `period-lock`, and the till shortage in `audit-summary-money`
- [x] Demo data: `DemoSeeder` (port of `src/db/seed/demo*.ts`, everything through the services); `DatabaseSeeder` refuses in production and on real records unless `--force`, and prints the demo credentials. Port `tests/db/demo-seed`. Against `tests/Fixtures/old-demo.db`: run in the order the fixture was made, every account code and every product's (Q, V) equal; in today's date order, everything but the 1200/5000 split and per-product values equal (DemoSeedTest)
- [x] Concurrency (`tests/Processes/AccountCodeConcurrencyTest.php`): four desks add payment accounts (filling 1012-1019) and expense categories at once: every code distinct, no failures. Run with the heading lock removed it failed (ConflictError from the unique code)

## Phase 7: Reports
- [x] Reporting services in `app/Services/Reporting/` (port of `src/services/reporting/*`): `BalancesService` (account balances, `excludeClosing`, trial balance of net balances, payment-account balances), `FinancialService` (P&L without closing entries, balance sheet with them and the `SubledgerSplit`, cash flow with transfers netted shop-wide, net profit), `LedgerService` (chart roll-up, journal list paged before totalling, entry detail, general ledger with opening and running balance, receivables ageing from the due date and payables from the purchase date as at a date with voids netted, `checkBooksIntegrity`), `OperationsService` (sales by day, line, customer, product, category and payment method, top product, purchases by day, supplier and product, stock valuation and movement summary), `MoneyTrend`, `TaxReturnService`, `YearEndService` (available years, year-end pack); `App\Domain\Dashboard`; `JsCompat::round` (Math.round)
- [x] `YearEndCloseService`: close (refused when already closed, unfinished or out of order; the `YearEndClose::buildClosingEntry` entry posted `is_closing` on the year end; lock moved; `year_end_closings`), reopen (refused when not closed or under a later closed year; reversal flagged closing; lock moved back), `listClosings`, `isYearClosed`
- [x] Port `accounting-reports`, `financial-reports`, `report-filters`, `report-invariants`, `receivables-ageing`, `tax-return`, `dashboard` (figures; the rendered page waits for Phase 9)
- [x] Port `year-end`, `year-end-close`, `year-end-ageing-as-at`, `year-end-check-line`
- [x] Concurrency (`tests/Processes/YearEndCloseConcurrencyTest.php`): four desks close the same finished year at once, five years in a row: one closing row, one closing entry and one lock move per year, the rest told "already closed"; a close racing three tills selling on the year end, five years in a row: each sale is either swept into the closing entry or refused as period-locked (both happen), the year's P&L accounts end at zero, the closing profit is the year's profit, retained earnings hold every year and the books balance. Sabotage: with the close's exclusive settings lock removed the first test still passed (the posting's shared settings lock upgrading to exclusive deadlocks the second closer, whose retry then sees the year closed); with that and `readLockDate`'s shared lock both removed it failed (the unique index refused the second closer, not the "already closed" check). With only `readLockDate`'s shared lock removed the second test failed (a 2021 sale landed after the close: P&L accounts 200 pesewas off zero)
- [x] CSV exports share each page's filter parser: `app/Reports/Csv/{Csv,ListExport,ReportExport}` (the logic of the old `api/exports/[list]` and `api/reports/[report]` routes; routes, throttles and permission checks wait for the screens); report filter parsers in `ListFilters`; `Filters::describeDateRange`. Port `tests/lib/csv`, the rest of `filters`, `tests/app/account-export*`, `sales-export-profit`, `document-figures` (service part)
- [x] Replace the test-side stand-ins (`Tests\Support\Ageing`, the copied cash-flow, P&L and line-report reads, `Shop::balance` / `trialBalanced`) with the real reports; un-defer every Phase 7 case
- [x] Invariant tests (`tests/Invariants`, old ARCHITECTURE §9, items 1–5) over a seeded random run through the real services
- [x] Reconcile v2: trial balance, P&L, balance sheet (totals and per-account lines), receivables and payables ageing, the tax return per component, the cash flow (per source and per payment account), sales by day and the stock valuation, for every financial year and as at the latest date, through the new services against plain SQL on the old copy (`OldReports`); `ImportTest` checks zero differences and that a moved figure shows
- [x] `nuna:benchmark` (port of `scripts/benchmark.ts`) on its own throwaway database; run at a year of trading (292k lines). It found the trial balance, balance sheet, general ledger and integrity check at 1–2.5 s; fixed with a covering `idx_journal_lines_account`, an all-time-less-outside sum for wide windows, a one-account general-ledger header and a hashed integrity scan; slowest report now 0.6 s (integrity check)

## Phase 8: Users, settings and shell
- [x] `AuthService` (password and PIN sign-in, lockout, first-run, createUser, changePassword), `UserService` (list, edit, roles, switch off and on, permissions, reset, own password, PIN, unlock; never delete), `App\Auth\{Passwords, LegacyScrypt, Principal, Permissions, RoleGuard, Sessions, ClientAddress}`, `App\Support\{RateLimit, HttpThrottle}`; Gate abilities `module.action`; middleware `permission:module,action` and `session.limits` (wired to routes in Phase 9); argon2id for new hashes
- [x] Port `auth`, `privilege`, `users`, `tests/lib/password`, `rate-limit`, `http-throttle` (the throttle's "routes use it" structural cases wait for the routes)
- [x] The demo users (`owner`, `ama`) sign in, PIN included (AuthTest)
- [x] Validate the client address taken from proxy headers before it reaches `rate_limits.key` / `audit_logs.ip_address` (the old app took it unchecked)
- [x] `LegacyScrypt`: RFC 7914 vectors, the old app's own hashes in `tests/Fixtures/old-demo.db`, and hashes made by running the old `hashPassword`/`hashPin` under Node (`tests/Fixtures/legacy-scrypt.json`, NFKC case included); rehash on first sign-in, password and PIN. Measured on the dev PC: 6.2 s plain PHP, 0.87 s with the opcache JIT; still to measure on the shop PC
- [ ] Route guard test (replaces `page-guards`, `action-guards`): waits for the routes (Phase 9); `SessionLimitsTest` already proves the middleware on test routes
- [ ] Settings screen, logo upload (port `tests/lib/image`), looks and light/dark (port `look`, `theme`, `motion`)
- [x] Port `search`, `notifications` (the notices; its write-off and per-product window cases are inventory and catalog behaviour, ported in Phase 3), `backup-status`, `update`, `preflight-money`
- [ ] Help pages from `resources/docs` (port `tests/lib/help`, `markdown`); carry over `docs/how-to`, `reference`, `explanation`, `tutorials`
- [x] `nuna:backup` / `nuna:restore` / `nuna:preflight` (port `tests/db/backup`, `restore`, `preflight`: `tests/Processes/BackupRestoreTest`, `tests/Services/PreflightTest`)
- [ ] Rewrite `how-to/back-up-and-restore.md`: waits for the Help pages
- [x] Windows installer and update scripts (PLAN Q1): `scripts/install.ps1`, `scripts/update.ps1`; ASCII and parse-checked (`tests/Feature/PowerShellScriptsTest`), not yet run on a shop PC

## Phase 9: Proof and cut-over
- [ ] `nuna:reconcile` reports zero differences on a copy of the real shop data
- [ ] Backup → restore into an empty DB → reports match
- [ ] Playwright CLI click-through of every module, screenshots in `screenshots/`, figures match the old app
- [ ] README and ARCHITECTURE list every behaviour difference and why

## Backlog
- [ ] HTTPS on the LAN
- [ ] Off-site backup copy

Phases defined in [ROADMAP.md](ROADMAP.md).
