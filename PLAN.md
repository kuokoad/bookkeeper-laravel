# PLAN: rebuild NunaBooks in Laravel + MySQL

**Status:** approved 2026-11-05 with the recommended answers to Q1–Q8. Phase 0 built. · **Date:** 2026-11-05
**Spec:** `C:\ClaudeProjects\bookkeeper` (read-only). **Brief:** [PROMPT.md](PROMPT.md).

Sources read: the old ARCHITECTURE.md (all), README.md, CLAUDE.md, the schema as actually built by its 28 migrations (42 tables, every CHECK and index), `src/domain/` (money, quantity, rate, costing, batches, tax components, sale calculation, period lock, chart of accounts), every service in `src/services/` and `reporting/`, the 66 files in `tests/services/` (by test title and samples, not every assertion), the last 30 commits, `src/lib/auth/`, routes and actions, deployment scripts and docs.

Companion docs: [docs/DATA_MODEL.md](docs/DATA_MODEL.md) (full column and CHECK mapping), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [SECURITY.md](SECURITY.md), [docs/ROADMAP.md](docs/ROADMAP.md), [docs/TASKS.md](docs/TASKS.md).

---

## 1. Decisions I need from you before building

Each of these touches money, data or how the shop runs, so I am asking rather than guessing.

| # | Question | My recommendation |
|---|---|---|
| Q1 | **Shop install.** How should MySQL and the web server run on the shop PC? | MySQL 8.4 and Apache (with mod_fcgid and PHP NTS) as two Windows services that start at boot with no window. `php artisan serve` is out: on Windows it serves one request at a time, so a second till would queue behind the first. Laragon is the simpler fallback. |
| Q2 | **Laravel version.** | The latest stable release on the day Phase 0 starts, on PHP 8.4 (both on the dev PC through Laragon). I will check its docs, not memory. |
| Q3 | **Safe-integer range.** The old app refuses any money or quantity outside ±2^53−1, the JavaScript safe range. PHP's `int` goes to ±2^63−1. | Keep the old limit, so the two apps refuse exactly the same inputs and every imported value round-trips. |
| Q4 | **`allocate()` uses floats for weights** (`Math.round(w × 1_000_000)`), and so do the invoice-discount split, tax-residual absorption and line earnings. Above about 9×10⁹ pesewas (GHS 90 million) per weight, that multiply is inexact in JavaScript. | Port it in exact integer arithmetic (`BigInteger`, `w × 1_000_000`). That gives the same result as the old app for every weight below the limit, which is every real shop figure. I will add a test proving equality across that range. Say if you would rather I copy the float behaviour bit for bit. |
| Q5 | **Numbering quirk.** Owner capital takes its number from the `INC-` sequence and drawings from `EXP-`. | Keep it. Changing it would renumber future documents and differ from imported history. |
| Q6 | **Unit renames.** `products.unit` references `units.symbol` with `ON UPDATE CASCADE`. MySQL forbids a CHECK on a column that has an FK action. | Drop the cascade and rename a unit's symbol in `UnitsService`, updating the products in the same transaction. The CHECKs stay in the database. |
| Q7 | **Sessions at cut-over.** Old session rows are SHA-256 tokens that Laravel cannot use. | Do not import them. Everyone signs in once after the switch. Passwords and PINs still work (§6.3). |
| Q8 | **Screens.** | Blade + Livewire + Tailwind, as the brief suggests. Inertia would need a JavaScript build and client state the old app does not have. |

---

## 2. Module list

One Laravel service per old service, same names, same responsibilities. `app/Domain` mirrors `src/domain`.

| Module | Old service(s) | New service(s) | Old tests to port (`tests/services/`) |
|---|---|---|---|
| Domain | `domain/*` | `App\Domain\{Money, Quantity, Rate, BusinessDate, FinancialYear, Errors}`, `Inventory\{Costing, Batches}`, `Tax\Components`, `Sales\{Calculate, Present, SaleDate}`, `Accounting\{ChartOfAccounts, Journal, PeriodLock, YearEndClose}` | all 13 in `tests/domain/` |
| Ledger core | journal, sequence, period-lock, audit | `JournalService`, `SequenceService`, `PeriodLockService`, `AuditService` | period-lock |
| Settings and tax | settings, tax | `SettingsService`, `TaxService` | settings, tax-setup, tax-components-crud |
| Catalog | catalog, units, product-import, product-replace | `CatalogService`, `UnitsService`, `ProductImportService`, `ProductReplaceService` | catalog-lookup, product-delete, product-import, product-replace-unit, units, module-filters |
| Inventory | inventory, stock-adjustment | `InventoryService` (the single stock gateway), `StockAdjustmentService` | inventory, batch-gateway, batch-integrity, batch-reporting, stock-integrity-check, stock-ledger-dates, costing-vs-ledger |
| Sales | sale, returns, customer, customer-payment, quotation | `SaleService`, `ReturnsService`, `CustomerService`, `CustomerPaymentService`, `QuotationService` | sales, sales-tax, sale-idempotency, sale-price-override, invoicing, sale-line-earnings, sale-profit-tax, sales-filters, sale-batches-by-date, sale-expiry, sale-void, sale-void-reporting, void-ageing, refund-cap, refunds-after-returns, credited-returns, return-tax, payment-allocation, payment-void, overpayment, top-product, quotations |
| Purchases | purchase, supplier, supplier-payment | `PurchaseService`, `SupplierService`, `SupplierPaymentService` | purchases, purchase-tax |
| Cashbook | cashbook, transfer, payment-account, reconciliation | `CashbookService`, `TransferService`, `PaymentAccountService`, `ReconciliationService` | cashbook, transfers, reconciliation, account-statement, list-order-by-trading-date |
| Reports | reporting/* (balances, financial, ledger, operations, money-trend, subledger-split, tax-return, year-end), year-end-close | `Reporting\*` same split, `YearEndCloseService` | accounting-reports, financial-reports, report-filters, report-invariants, receivables-ageing, tax-return, dashboard, year-end, year-end-close, year-end-ageing-as-at, year-end-check-line, audit-summary-money |
| Users and auth | auth, user, role-guard | `AuthService`, `UserService`, `RoleGuard`, `LegacyScrypt` | auth, privilege, users |
| Shell | search, notifications, backup, update | `SearchService`, `NotificationsService`, `BackupService`, `UpdateService` | search, notifications, backup-status, update, preflight-money |

The old `tests/app`, `tests/lib`, `tests/db` and `tests/ui` hold structural and UI tests (page guards, action guards, motion, print, CSV, filters, image sniffing, password, rate limit, backup and restore). Each gets a Laravel equivalent in the module it belongs to; they are listed in [docs/TASKS.md](docs/TASKS.md).

---

## 3. Build order

As the brief sets it, one module at a time. Each step: port its tests → write the code → compare figures with the old app on the same data → commit.

| Phase | Builds | Done when |
|---|---|---|
| 0. Foundation | Laravel skeleton, MySQL connection (strict, UTC, `utf8mb4_0900_bin`), Pest on a real MySQL test DB, Pint, all 42 migrations with every CHECK and trigger, enums, seeders (chart of accounts, sequences, units, tax components switched off, payment accounts, demo data matching `src/db/seed/`) | `migrate:fresh --seed` works on empty MySQL; a test inserts a bad row for every CHECK and sees it refused |
| 1. Domain | Money, Quantity, Rate, tax breakdown, sale calculation, costing (running pair), FEFO batches, journal builder, period lock, financial year, business date, year-end closing entry | All 13 old domain test files ported and green, including the costing property tests and the 10,001-rate round trip |
| 2. Ledger core | Journal posting (balanced, period-locked, leaf accounts only), reversal, gap-free sequences, audit (insert-only), settings, tax setup | period-lock, settings and tax tests green |
| 3. Catalog and inventory | Categories, units, products (unit and carton locks), stock gateway, adjustments (unit cost or total value), batches, import with opening stock, replace a locked product, delete unused vs archive used | catalog and inventory tests green; stock ledger value = GL 1200 |
| 4. Sales | Till, split tender, credit sales and invoices, backdated sales, idempotency, price-override gate, expiry, returns, refund cap, credited returns, voids and void mirrors, customer payments, allocation, overpayment, quotations | all sales tests green; the two-till concurrency test passes |
| 5. Purchases | Suppliers, deliveries (free goods, non-stock lines, recoverable tax), supplier returns, voids with costing residual, supplier payments | purchase tests green |
| 6. Cashbook | Payment accounts (GL leaf per account), expenses, income, capital, drawings, transfers, reconciliation | cashbook tests green |
| 7. Reports | Trial balance, P&L, balance sheet (with the subledger split), cash flow, journal, general ledger, ageing, statements, tax return, sales/purchase/inventory reports, dashboard, year-end pack, year-end close and reopen, every CSV export | report tests and the five invariant tests green |
| 8. Users and shell | Users, roles, permissions, PINs, legacy hash upgrade, audit log screen, settings screen, logo upload, search, notifications, help pages, backup and restore, preflight, installer, update script | all remaining tests green |
| 9. Proof | `nuna:import-sqlite`, `nuna:reconcile` on a copy of real data, backup → restore → reports match, Playwright click-through with screenshots, README and ARCHITECTURE differences | the brief's "Done means" list is all true |

The importer and reconcile commands are started in Phase 2 and grow with each module, so every module is checked against real data as it lands rather than all at the end.

---

## 4. Old table → MySQL table

All 42 tables keep their names and columns. The rules for every table are in [docs/DATA_MODEL.md](docs/DATA_MODEL.md): money, quantity and rates as `BIGINT`; text dates as `DATE`; millisecond integers as `DATETIME(3)` UTC; booleans as `TINYINT(1)` with a CHECK; `length()` as `CHAR_LENGTH()`; every CHECK kept with its old name. Only the differences are listed here.

| Old table | MySQL table | Change |
|---|---|---|
| `users` | `users` | `UNIQUE((LOWER(username)))`. Hash column widened to 255; scrypt strings imported as they are. |
| `sessions` | Laravel `sessions` | Not imported (Q7). |
| `user_permissions` | same | none |
| `rate_limits` | same | Kept as a table, so throttles survive a restart, as in the old app. |
| `audit_logs` | same | New `BEFORE UPDATE` / `BEFORE DELETE` triggers that refuse. |
| `business_settings` | same | `logo_data` becomes `MEDIUMBLOB`; `books_locked_before` becomes `DATE`. |
| `sequences` | same | Read `FOR UPDATE` inside the document's transaction. |
| `accounts` | same | `parent_id <> id` becomes a trigger. |
| `journal_entries` | same | `reverses_entry_id <> id` becomes a trigger. |
| `journal_lines` | same | none |
| `year_end_closings` | same | The partial unique index becomes a generated column `open_start_year` plus `UNIQUE`. |
| `units` | same | `UNIQUE((LOWER(symbol)))`. |
| `categories` | same | `UNIQUE((LOWER(name)))`. |
| `products` | same | `UNIQUE((LOWER(sku)))`. FK to `units` without `ON UPDATE CASCADE` (Q6). |
| `product_batches`, `stock_ledger`, `stock_ledger_batches` | same | Dates become `DATE`. |
| `stock_adjustments`, `stock_adjustment_items` | same | `voids_adjustment_id <> id` becomes a trigger. |
| `customers`, `suppliers` | same | none |
| `sales`, `sale_items`, `sale_payments`, `sale_taxes` | same | `voids_sale_id <> id` and `returns_sale_id <> id` become triggers. |
| `quotations`, `quotation_items` | same | none |
| `purchases`, `purchase_items`, `purchase_payments`, `purchase_taxes` | same | Self-reference checks become triggers. |
| `customer_payments`, `customer_payment_allocations`, `supplier_payments`, `supplier_payment_allocations` | same | none |
| `payment_accounts` | same | `UNIQUE((LOWER(name)))`. |
| `expenses`, `incomes`, `owner_movements`, `account_transfers`, `reconciliations` | same | none |
| `tax_components` | same | none |
| `__drizzle_migrations` | Laravel `migrations` | Not imported. |

Collation is `utf8mb4_0900_bin`, so equality and `ORDER BY` match SQLite's default binary comparison. Search must use `LOWER(col) LIKE LOWER(?)`, because SQLite's `LIKE` is case-insensitive and MySQL's is not under a binary collation.

---

## 5. Concurrency: what SQLite hid

The old app runs every write through `writeTransaction` with `BEGIN IMMEDIATE`, one global write lock, so nothing ever raced. MySQL lets transactions run together, so every read-then-write below takes a lock in the same transaction.

| Read then written | Where | MySQL protection |
|---|---|---|
| `products` running pair `(Q, V)` | `InventoryService::recordStockMovement` | `lockForUpdate()` on the product rows, in id order |
| `product_batches.qty_milli` / `is_closed` | batch cache | `lockForUpdate()` after the products |
| `sequences.next_number` | `SequenceService::next` | `lockForUpdate()`, last lock taken. A rollback returns the number, so numbering stays gap-free. |
| `sales.client_ref` | idempotent `createSale` | Look up first; on a duplicate-key error, roll back and replay the stored sale |
| `sale_items` / `purchase_items.returned_qty_milli`, document `status` | returns and voids | Lock the original document and its lines |
| `journal_entries.reversed_by_entry_id` | `reverseJournalEntry` | Lock the original entry |
| Customer / supplier balance | credit limit, overpayment, refund cap, allocation | Lock the party row before reading its balance |
| `business_settings.books_locked_before` | every posting; lock changes; year-end close | Shared lock on posting, `FOR UPDATE` when changing it |
| `year_end_closings` | close / reopen | `FOR UPDATE` on settings, plus the unique open-year index |
| `accounts.code` | new payment account or category (next free code) | Lock the parent account row; unique `code` |
| `users.failed_login_count`; only-active-owner checks | auth, users | Lock the user rows |
| `quotations.status` / `converted_sale_id` | convert | Lock the quote; unique `converted_sale_id` |

Lock order is always products → batches → party → documents → settings → sequences, so two tills cannot deadlock. `DB::transaction($fn, 3)` retries on a deadlock only.

**Proof:** `tests/Concurrency/LastUnitTest` opens two real MySQL connections in separate PHP processes, both selling the last unit of one product at the same moment. Exactly one succeeds, the other gets "not enough stock", and receipt numbers have no gap. A second test fires 50 concurrent sales and checks that the numbers run continuously and that Σ stock_ledger = product cache = GL 1200.

---

## 6. What changes because the stack changes

### 6.1 Running it in the shop
Proposed (Q1): MySQL 8.4 and Apache httpd + mod_fcgid + PHP NTS as Windows services, set to start at boot and restart on failure. MySQL listens on `127.0.0.1` only. A PowerShell installer replaces `install.ps1` / `deploy.ps1`. It creates the database and two MySQL users (app, and backup with dump rights only), writes `.env` with a fresh `APP_KEY`, migrates, adds the firewall rule (private network) and the hosts entry with a desktop shortcut. Built CSS and JS ship with each release, so the shop PC needs neither Node nor the internet. Making a sale needs only the LAN.

**Updates** keep the old shape: owner presses Update → back up (abort if it fails) → stop Apache → `git pull --ff-only` → `composer install --no-dev` → `php artisan migrate --force` → cache config, routes and views → start. If the new version will not serve, roll the code back; if the old code will not start either, restore the backup.

### 6.2 Backups
"Copy one file" no longer works. Instead:
- `php artisan nuna:backup [--keep=14] [--dir=E:\]` runs `mysqldump --single-transaction --quick --routines --triggers --hex-blob`, which gives a consistent snapshot while the shop trades, and gzips it.
- **Verify before it counts:** restore the dump into a scratch database, then check that the trial balance balances, AR and AP equal their control accounts, the stock replay matches the product caches and GL 1200, and there is an active owner. A copy that fails is deleted and the command fails loudly. Old copies are trimmed only after a verified one exists.
- `php artisan nuna:restore <file>` refuses while Apache is serving, verifies the file first, renames the current database to `..._replaced_<date>`, then restores.
- The Settings → Health download (`/backup`, settings edit) streams a fresh verified dump. Backup status is still read from the audit log.
- `docs/how-to/back-up-and-restore.md` is rewritten for the shop owner.
- **Tested:** a test backs up, restores into an empty database, and asserts that every report matches the original.

### 6.3 Passwords and PINs
The old format is `scrypt$32768$8$1$<salt>$<hash>` (b64url, 16-byte salt, 64-byte key, NFKC-normalised password). PHP's sodium extension does not let you choose N, r and p, so I will write `App\Auth\LegacyScrypt`: scrypt built on `hash_pbkdf2('sha256', …)` plus Salsa20/8 in PHP, tested against the RFC 7914 vectors and against hashes made by the old app.

On sign-in, if the stored hash starts with `scrypt$`, verify it with `LegacyScrypt`. On success, rehash with Laravel's `Hash` in the same transaction. The same applies to `pin_hash`. Nothing is reset in bulk. Expect about a second, maybe a few, on each user's first sign-in on the shop PC; I will measure it.

### 6.4 Timestamps
Millisecond integers become `DATETIME(3)` UTC (`FROM_UNIXTIME(ms/1000)` in the importer, with the connection in UTC). Business dates stay `DATE`. "Today" is computed in `Africa/Accra`.

### 6.5 Next.js-only parts
`AGENTS.md`-for-Next, the App Router, server actions, `loading.tsx` and the in-house markdown renderer do not come over. Server actions become controller or Livewire actions that check session and permission and then call a service. The two old structural tests (every page guarded, every action authenticated) become Laravel tests over the route list: every route outside a small allow-list carries the auth middleware and a permission middleware. Help pages render the ported markdown with `league/commonmark`, which Laravel already depends on.

---

## 7. Rules that must survive (from the commits and tests)

Each of these becomes at least one test before the code that implements it.

- **Carton (pack) sizes:** the base unit locks after any movement, opening stock included; the carton unit and size lock after any movement except opening stock, so a wrong size can be fixed while only opening stock is in. Every carton line carries the size it was shown at; a changed size is refused before anything moves (`StaleUnitError`), and the till corrects the line in place and keeps the cart.
- **Replace a locked product:** in one transaction, create the new product, move stock at the same total value with a `UNIT_CHANGE` adjustment, carry over SKU and barcode, and archive the old product. Refused for dated, negative or moved-meanwhile stock, or unchanged units. That adjustment cannot be voided, and the old product cannot be restored.
- **Stock adjustment by unit cost or total value:** whichever was typed last leads; only the total is posted; a unit-led total uses `extendPrice` rounding.
- **Backdated sale:** needs sales edit; never in the future or inside the lock; takes only batches received on or before its date (opening batches count for any day); expiry is judged as of today.
- **Returns:** the refund is capped at `min(returned, max(0, returned − min(sale outstanding, party balance)))`, and the rest is credited; the credit comes off the original sale; the invoice discount is re-shared, so a refund never includes discount; goods reverse only once (`returned_qty_milli`); a sale with returns cannot be voided.
- **Voids:** the mirror document is dated today with negative figures; mirrors count for nothing in outstanding, lists, totals or ageing; ageing as at a date before the void still counts the original.
- **Tax is not profit:** profit is `total − tax − cogs` everywhere; line earnings split `total − tax` by value so lines tie to the P&L.
- **Duplicates:** an unused product is deleted (audited with a snapshot); a used one is archived, and only at zero stock.
- **Transfers:** Dr to / Cr from on the chosen date, inside the lock rules; voided by a reversal dated today; excluded from shop-wide cash flow; no overdraft check (as today).
- **Weighted average:** `cogs = round_half_away(V × q / Q)`; selling the last unit sends the residual pesewa to COGS; voiding a purchase or adjustment after the average has moved posts a 5000↔1200 residual so the ledger equals GL 1200.
- **Year-end:** closing entries are flagged `is_closing` and excluded from the P&L; closing locks the books to the year end; refused if unfinished, twice or out of order; reopening goes newest first.
- **Filtering:** all six rules from the old CLAUDE.md, enforced by porting `sales-filters.test` (reads never write) and `module-filters.test`.

---

## 8. Proving it matches

- `php artisan nuna:import-sqlite <path>` reads a **copy** of `bookkeeper.db` through PDO SQLite (read-only), inserts every row unchanged with the same ids, converts timestamps, re-seeds AUTO_INCREMENT counters, and refuses a non-empty target. Some links point forward (`voided_by_sale_id`, `reversed_by_entry_id` and the like), so it loads with `FOREIGN_KEY_CHECKS=0` inside one transaction and then runs its own orphan check on every FK before committing; CHECKs and triggers still apply to every row. It never writes the generated columns (`*_lower`, `open_start_year`). Any row MySQL refuses (a value wider than its column, two names that now collide under Unicode `LOWER()`) is listed with its table and id, and nothing is committed.
- `php artisan nuna:reconcile [--as-at=YYYY-MM-DD]` reads the old database's figures directly (the same SQL the old reports run, against the SQLite copy) and the new app's figures through the new services. It compares the trial balance per account, `(Q, V)` per product plus a full replay, receivables and payables per party, tax payable per component, P&L and balance sheet for each financial year, and ageing. It prints every difference and exits non-zero on any. **Zero differences is the only pass.**
- Every old `tests/services/` file gets a Laravel equivalent that asserts the same figures in minor units. Test names keep the old behaviour wording.
- The five invariant tests from old §9: every entry balances; the balance sheet balances after a random sequence; stock ledger = product cache = GL 1200; AR = GL 1100 and AP = GL 2000; a rolled-back operation leaves zero rows.
- Playwright CLI click-through of every module with screenshots in `screenshots/`, side by side with the old app on the same imported data.

---

## 9. Out of scope for this rebuild

New features, multi-branch, FIFO costing, a free-form journal screen, putting the app on the internet, and pixel-identical styling. The three looks (Standard, Ledger, Classic) and light/dark mode do come over.

## 10. Owner decisions (old-app money behaviour kept for now)

The rule is "the old app is right until proven otherwise", and the brief says
to ask about money rather than guess. Each item below is old-app behaviour
the port reproduces exactly; reviews showed it produces wrong figures. Nothing
changes until you decide. Imported history is unaffected either way: the
importer copies posted rows unchanged, so a fix applies only to new documents.

**D1. A supplier return puts its whole tax share on VAT Payable (2100).**
Old `returns.service.ts:761` (new `ReturnsService.php`, supplier side).
Ghana taxes, 10 bags at 65.00 on credit, all returned:
- levies reclaimable (the default): after the return 2100 = +32.50,
  2110 = -16.25, 2120 = -16.25. The trial balance balances, but VAT is
  overstated by the levies and the levy reclaim stays on goods that went
  back. A tax return over that period will disagree with the ledger.
- levies folded into stock cost: after the full return Inventory (1200)
  holds 32.50 with an empty shelf, and 2100 is +32.50.
Proposed: credit each recoverable tax line to its own account
(`TaxService::accountFor`), and take a non-recoverable line back where it
went in: 1200 through the stock movement for stock lines, 6900 for non-stock.

**D2. A supplier return values the goods before the invoice discount and
without folded levies, and ignores the costing residual.** Old
`returns.service.ts:573` and :695. Example: 10 × 65.00 less a 65.00 invoice
discount, on credit (owed 585.00); return all 10: the credit applied is
650.00, so the supplier "owes" the shop 65.00 that was never paid, and
Inventory ends at -65.00 with an empty shelf.
Proposed: value the goods at their own stock-ledger cost, prorated (as a
purchase void already does), and post any residual like the void.

**D3. A supplier payment's explicit allocations are not checked against the
payment amount.** Old `supplier-payment.service.ts` allocation loop. Paying
10.00 with an allocation of 50.00 to one purchase settles 50.00 of it while
the payable falls by 10.00. Proposed: refuse allocations that add up to more
than the payment. (Check the customer side for the same.)
