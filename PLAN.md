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

**D1. A supplier return puts its whole tax share on VAT Payable (2100). DONE
(2026-11-06, approved by the owner).**
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
Now: `ReturnsService::createSupplierReturn` credits each reclaimed tax to its
own account and leaves folded levies inside the goods' cost; shares are
cumulative, so partial returns sum to a full one (`SupplierReturnPostingTest`).

**D2. A supplier return values the goods before the invoice discount and
without folded levies, and ignores the costing residual. DONE (2026-11-06,
approved by the owner).** Old
`returns.service.ts:573` and :695. Example: 10 × 65.00 less a 65.00 invoice
discount, on credit (owed 585.00); return all 10: the credit applied is
650.00, so the supplier "owes" the shop 65.00 that was never paid, and
Inventory ends at -65.00 with an empty shelf.
Proposed: value the goods at their own stock-ledger cost, prorated (as a
purchase void already does), and post any residual like the void.
Now: the return reads the cost through the void's own helper
(`PurchaseService::goodsCostByItem`) and posts a residual with
`PurchaseService::postCostingResidual` (`SupplierReturnPostingTest`).

**D3. A supplier payment's explicit allocations are not checked against the
payment amount. DONE (2026-11-06, approved by the owner).** Old `supplier-payment.service.ts` allocation loop. Paying
10.00 with an allocation of 50.00 to one purchase settles 50.00 of it while
the payable falls by 10.00. Proposed: refuse allocations that add up to more
than the payment. (Check the customer side for the same.)
Now: refused with "The amounts allocated to purchases exceed the payment."
The customer side already refused ("...to invoices exceed the payment.");
both are tested in `AllocationCapTest`.

The items below are reports, not postings: they change what a screen or
export shows, never a stored figure.

**D4. Account balances with "leave out closing entries" and no dates still
count the closing entries. DONE (2026-11-06, approved by the owner).** Old `reporting/balances.service.ts:80-84`
(new `BalancesService::getAccountBalances`, `periodOnlyWhenDated`): the
WHERE that drops lines outside the window is applied only when a from or to
is given, and the totals sum journal lines, not entries, so the closing
condition in the JOIN filters nothing. Example: 2025 closed with sales of
10,000.00; all-time balances with `excludeClosing` show Sales Revenue (4000)
at 0.00, not 10,000.00. The P&L and year-end close always pass dates, so
neither is affected today. Proposed: apply the conditions whenever any is set.
Now: `BalancesService::loadTotals` applies every condition whenever any is set;
the `periodOnlyWhenDated` shim is gone (`ReportDecisionsTest`).

**D5. The dashboard's "week" is 8 days, "month" 31 and "quarter" 91. DONE
(2026-11-06, approved by the owner).** Old
`lib/dashboard.ts:9,16`: the range runs `today − 7 .. today`, both ends
inclusive. Example: on Friday 2026-11-06 "this week" covers Friday
2026-10-30 to Friday 2026-11-06, so last Friday's takings are counted with
today's. Proposed: `today − 6 .. today` (and 29 and 89), or calendar
week/month/quarter to date.
Now: `Dashboard::dashboardPeriod` runs `today − 6`, `− 29`, `− 89` to today, so
7, 30 and 90 days, as the 7d/30d/90d buttons say (`tests/Domain/DashboardTest`).

**D6. The money trend counts a transfer between your own accounts as money
in and money out, while the shop-wide cash flow leaves transfers out. DONE
(2026-11-06, approved by the owner).** Old
`reporting/money-trend.ts:80-95` sums every debit and credit on the money
accounts; `financial.service.ts:460` drops `TRANSFER` shop-wide. Example:
moving 2,000.00 from the till to the bank in March shows March's trend
with 2,000.00 more in and 2,000.00 more out than the cash-flow report for
March (the net is the same). Proposed: drop transfers from the trend too.
Now: `MoneyTrend::getMoneyByMonth` leaves `TRANSFER` entries (and their voids)
out, so a month's in and out equal the cash-flow report's (`ReportDecisionsTest`).

**D7. Purchases by product adds quantities across a unit change and shows one
unit. DONE (2026-11-06, approved by the owner).** Old `reporting/operations.service.ts:658-672` groups by product only
and takes any row's `unit`. Example: 5 cartons of Milo bought before the unit
changed to pieces (24 a carton), then 10 pieces: the report reads "15 pcs"
(or "15 carton"), where 130 pieces came in. Proposed: group by product and
unit, one row per unit bought in.
Now: `OperationsService::getPurchasesByProduct` groups by product and the
stored line unit (`purchase_items.unit`): "5 carton" and "10 pcs" are two rows;
the CSV names each row "Milo 400g (carton)" (`ReportDecisionsTest`).

**D8. Payables are aged from the purchase date; purchases have no due date.
DONE (2026-11-06, approved by the owner).**
Old `reporting/ledger.service.ts:585` (receivables use the due date, :523).
Example: a delivery on 60-day terms received 45 days ago shows as 31–60 days
overdue when nothing is due yet. Proposed: add a due date (or supplier terms)
to purchases and age from it, falling back to the purchase date.
Now: `purchases.due_date` (nullable DATE, new here; imported rows stay NULL;
suppliers have no terms, so it defaults to empty). The purchase form has an
optional Due date, held to a sale's terms (purchase date plus 0 to 365 days:
"The due date must be from the purchase date up to a year after it."), the
purchase page shows it, the Payables screen heads its date "Oldest due", and Payables ages from `COALESCE(due_date,
business_date)` (`ReportDecisionsTest`).

**D9. The taxable-sales box counts a voided sale and its void. DONE
(2026-11-06, approved by the owner).** Old
`reporting/tax-return.service.ts:151` sums `subtotal − discount` over every
sales row in the period, voided originals and their negative mirrors
included. A void in the same period nets to zero, but the document count
goes up by two. Example: one sale of 100.00 and a void of another of 50.00
reads "taxable sales 100.00 over 3 sales". Across periods (sale in March,
void in April) April shows −50.00. Proposed: count only `POSTED` sales and
returns for the count; keep the value netting as is.
Now (the owner chose "as at the period end", for purchases too):
`TaxReturnService` counts a sale, return or purchase dated in the period unless
a void of it is dated on or before the period end; a `VOID` mirror never
counts. The value is unchanged. The example reads "100.00 over 1 sale"; a March
sale voided in April stays "1 sale" in March and April counts 0
(`ReportDecisionsTest`; reconcile's OldReports counts the same way).

**D10. An invalid report `from` falls back to the 1st of this month, and a
`from` after `to` is not swapped. DONE (2026-11-06, approved by the owner).** Old `app/api/reports/[report]/route.ts:59`.
Example: `from=2026-02-30&to=2026-01-31` on 2026-11-06 becomes
2026-11-01..2026-01-31, an empty report with no warning. Proposed: refuse or
swap the pair, and show the resolved dates.
Now: `ListFilters::parseReportDates` resolves a report CSV's dates through the
page's own `Filters::resolveDateRange('custom', …)`: a backwards pair is
swapped, one end alone means "from then on" or "up to then", neither valid is
this month so far, so the file and the page always agree; the page shows the
resolved dates. The sales and purchases files take those dates rather than
re-reading the period (`ReportDecisionsTest`).

**D11. Margins are `Math.round` of a float ratio. DONE (2026-11-06, approved
by the owner).** Old
`reporting/financial.service.ts:198,202` and `operations.service.ts:397`
(new: `JsCompat::round`). Math.round sends a tie towards +∞, where every
money figure in the app rounds half away from zero, and the float division
can land a hair either side of a tie. Example: a loss of 1.00 on sales of
20,000.00 is exactly −0.5 bp: the old app shows a margin of 0 bp (0.00%),
half away from zero gives −1 bp (−0.01%). The effect is at most 1 bp.
Proposed: integer `Money::divRoundHalf(profit × 10,000, revenue)`.
Now: P&L gross and net margins, sales-by-product margins and
`SaleCalculator::marginBp` use `Money::mulDiv(profit, 10_000, revenue)`.
`JsCompat::round` stays for the stock valuation's retail value
(`ReportDecisionsTest`, `SaleCalculatorTest`).

**D12. A sign-in for an account still on its old-app password answers more
slowly.** Old `services/auth.service.ts` spent one scrypt on every attempt,
real or not, so timing never told a guesser which usernames exist. Here an
unknown username, or one already on a Laravel hash, costs one argon2id check
(about 50 ms); an account whose old scrypt hash has not yet been replaced
costs a plain-PHP scrypt (about 6 s, under 1 s with the opcache JIT the
installer turns on). Until everyone has signed in once after the switch, the
delay says "this username exists and has not signed in since the cut-over".
Burning a full scrypt for every unknown username would close that, but would
let anyone keep the till PC busy for seconds per guess. Kept as is (the gap
closes as people sign in); say if you would rather pay the scrypt.
Decided 2026-11-06: kept as is, approved by the owner. The gap closes as people sign in after the switch.

**D13. Changing your own password signs you out too.** Old
`user.service.ts` `changeOwnPassword` says "every OTHER session is ended;
this one keeps working", but the code deletes every session of that person,
the current one included (and the old test asserts zero sessions). Ported as
the code behaves. Proposed: Phase 9's password page calls
`Sessions::begin()` again after a successful change, so the person stays
signed in on that screen only, as the comment intended.
DONE 2026-11-06, approved by the owner. Now: changing your own password ends every other session; this one stays signed in under a new session id (`PasswordController`, `AuthScreensTest`).

**D14. Taking over another account through its PIN or password. DONE (for
security, without waiting).** Old `user.service.ts:312` (`resetUserPassword`)
and `:397` (`setUserPin`) let anyone with `users` edit set any account's
credential, an owner's included, though `role-guard.ts` says its two rules
"close every route". Setting a credential is signing in as that person, so a
staff member trusted with the team could become the owner. Now an owner's PIN
or password needs an owner, and anyone else's needs the actor to hold every
right the target holds (`UserService::assertMayTakeOver`, tested in
`PrivilegeTest`). Role changes already needed an owner; switching someone off
grants the actor nothing, so it is unchanged.

**D15. Without a trusted proxy, every sign-in shares one throttle bucket.**
With `NUNA_TRUST_PROXY_HEADERS=false` (the default, and right for the shop)
every attempt counts against one key, so 10 wrong guesses in 10 minutes from
anyone block every till, and one success clears the bucket for the guesser
too. Proposed: throttle per username plus a looser shared ceiling (say 50 per
10 minutes), and do not clear the shared count on a success.
DONE 2026-11-06, approved by the owner. Now: 10 tries per 10 minutes per username, plus a shop-wide 50 (per address behind a trusted proxy); a success clears only that name's count, never the shared one. An owner account is counted but never refused by either bucket; only the D16 wait holds it back (the owner chose this after review) (`AuthScreensTest`). Anyone can still hold a staff username's 10 for 10 minutes.

**D16. Anyone can lock the owner out for 15 minutes.** Five wrong passwords
for the owner's username lock that account, from any till, and again as soon
as the lock ends. Proposed: keep the lock for staff; for an owner, slow down
instead (a growing delay per failure) and show the failed attempts on the
owner's next sign-in.
DONE 2026-11-06, approved by the owner. Now: an owner is never locked; from the 5th failure in a row a 1, 2, 4... second wait (at most 60 s), and "N failed sign-in attempts since your last sign-in" on the next success. The throttle, a staff lock and the owner's wait all show one message, "Too many attempts. Wait a few minutes and try again.", so the owner's name is not revealed by wording (a patient guesser can still notice the owner answers again within a minute). Staff keep 5 failures / 15 minutes (`AuthTest`, `AuthScreensTest`).

**D17. Owner accounts can sign in with a till PIN.** A 4 to 6 digit PIN is
the weakest credential in the shop, and on an owner account it opens
everything. Proposed: refuse PIN sign-in for owners, who keep their password.
DONE 2026-11-06, approved by the owner. Now: an owner's PIN sign-in is refused exactly as a wrong PIN and counted; a PIN cannot be set on an owner, and old owner PINs are ignored (`AuthTest`, `UsersTest`, `UsersScreensTest`).

**D18. A restore drops the live database before loading the file.** If the
load fails part way (disk full, MySQL stops), the database is empty or
partial until the owner restores the `...-before-restore` file; the error
names it. Renaming aside is impossible (MySQL cannot move a table with
triggers to another schema). Proposed: keep it, and have the Phase 9 restore
screen offer the safety file in one click when a restore fails.
DONE 2026-11-06, approved by the owner. Now: if a restore's load or check fails, the `-before-restore` copy is loaded straight back and checked, then the failure is reported; if that also fails, it says so loudly naming both files. The safety copy must be complete (checked writes and mysqldump's completion line) before anything is dropped, and a database that was empty before is left empty after a failed load (`BackupRestoreTest`).

**D19. Till PINs are now rehashed on sign-in (new behaviour).** The old app
never replaced a PIN hash; here an old scrypt PIN becomes argon2id on its
first successful PIN sign-in, as passwords do (PLAN 6.3). Nothing changes for
the person. Proposed: keep it.
Decided 2026-11-06: kept, approved by the owner.

The next five came out of the Phase 9 click-through. Each is old-app
behaviour the new screens reproduce on purpose; nothing changes until you
decide.

**D20. After "Save on credit" the till says "Payment received in full."**
Old `sales/new/pos.tsx:551` (new `pos.blade.php:26`): the banner shows "Give
change" when there is change and otherwise always "Payment received in full."
Example: a GHS 50.00 sale, GHS 20.00 cash, saved on credit to Adwoa: the
banner reads "Sale saved — R-0001 · Payment received in full." while she
owes GHS 30.00 on it. The figures (the sale, her balance) are right; the
words are not. Proposed: when the sale left money owing, say "GHS 30.00 left
on Adwoa Mensah's account." instead.
DONE 2026-11-06, approved by the owner. Now: after a sale that leaves money owing the till says "GHS 30.00 left on Adwoa Mensah's account." (the amount is the service's `outstanding`); "Give change" and "Payment received in full." stay for the rest (`PosScreenTest`).

**D21. No confirmation after a customer return or owner money in/out.** Old
`purchase.actions.ts:456` redirects to `/sales/{id}?returned=1`, which
`sales/[id]/page.tsx` never reads; old `cashbook.actions.ts:271` returns `{}`
and `accounts/owner-money-form.tsx` shows only errors (new
`Sales/Show.php:150`, `Accounts/Index.php:203`). Example: return 2 of 5 bags
of rice: the sale page reloads with the return listed and no message; record
GHS 500.00 put in by the owner: the form clears and nothing says it was
saved. Proposed: "Return recorded. Stock and the customer's balance were
updated." and "Recorded. The account balance was updated.", as the other
forms do.
DONE 2026-11-06, approved by the owner. Now: a customer return shows "Return recorded. Stock and the customer's balance were updated." and owner money in or out shows "Recorded. The account balance was updated." (`SalesScreensTest`, `AccountScreensTest`).

**D22. The purchase report ignores supplier returns.** Old
`reporting/operations.service.ts:688` (by day), `:628` (by supplier) and
`:658` (by product) sum the purchase documents only (new
`Reporting/OperationsService.php:367` and neighbours). Example: one purchase of
GHS 4,500.00, then goods worth GHS 450.00 sent back: the report shows
GHS 4,500.00 bought, not GHS 4,050.00, while the supplier's balance and the
ledger already show the return. Proposed: a "Returned" column dated by the
return, and a net total, so the report agrees with the supplier pages.
DONE 2026-11-06, approved by the owner. Now: the purchase report and its CSV have Returned and Net in every view (day, supplier, product per unit), summed from supplier return documents on their own date under the same filters as the rows; 4,500.00 bought and 450.00 returned reads Net 4,050.00 (`ClickThroughDecisionsTest`).

**D23. The sales report's daily sale count includes voided sales.** Old
`reporting/operations.service.ts:266` counts every `kind = 'SALE'` row dated
in the period, voided ones too (new `Reporting/OperationsService.php:140`);
the money columns net out through the void's mirror row, the count does not.
Example: three sales on 03 Nov, one of them voided: the day shows 3 sales
and the takings of two. Proposed: count only sales still standing (POSTED),
or show "3 (1 voided)".
DONE 2026-11-06, approved by the owner. Now: the daily sale count uses D9's "as at the period end" rule (`TaxReturnService::standingAtEndSql`); three sales with one voided read 2 (`ClickThroughDecisionsTest`; reconcile's OldReports counts the same way).

**D24. A customer's "Total bought" and "Total paid later" cover only their
latest 100 sales and payments.** Old `customers/[id]/page.tsx:54` lists 100
sales and 100 payments and sums those (new `Customers/Show.php:152`).
Example: a regular with 150 sales of GHS 10.00 sees "Total bought 1,000.00",
not 1,500.00; the balance owed (computed separately) stays right. Proposed:
one SQL sum over all of the customer's posted sales and payments in
`CustomerService`, keeping the list at 100.
DONE 2026-11-06, approved by the owner. Now: "Total bought" and "Total paid later" on the customer and supplier pages are sums over all posted documents (`getActivityTotals`); returns subtract on both pages (the owner chose one rule after review) and a voided sale drops out, as the balances have them (the old customer page took each void off twice; the old supplier page left returns out); the lists still show 100 rows (`ClickThroughDecisionsTest`).

**D25. A customer return rounds each piece on its own. DONE (2026-11-06,
approved by the owner).** Old `returns.service.ts:182-183` (revenue and cost,
`mulDiv` of the piece) and `:236` (`taxShareOf` of the piece's revenue), new
`ReturnsService::createCustomerReturn`. Example: 3 x 10.00 less a 1.00
invoice discount, 29.00 on credit, cost 10.00; returned a unit at a time,
each piece credits 9.67 and puts back 3.33 of cost: 29.01 credited and 9.99
of cost back, so the customer ends 0.01 in credit and Inventory 0.01 short
on a full shelf. Under taxes a piece's split of a rounded total can step a
component backwards and be refused.
Proposed: cumulative shares, as the supplier side (D1/D2).
Now: each piece's revenue, cost and each tax component is the share of
(returned so far + this piece) less the share of (returned so far), per
component with `cumulativeShare`; the pieces credit 9.67, 9.66, 9.67 and
put back 3.33, 3.34, 3.33. Returns already stored (imported ones too) keep
their figures; a new piece is measured from the quantity already back
(`CustomerReturnSharesTest`).

**D26. Stock coming back from below zero drops its costing residual. DONE
(2026-11-06, approved by the owner).** Old `purchase.service.ts:286`,
`returns.service.ts:327` (customer return), `sale.service.ts:806` (sale
void) and `stock-adjustment.service.ts:206` (adjustment in) ignore the
`residual` that `costing.ts` reports when a movement brings the quantity
back to zero (only the purchase void, `purchase.service.ts:666`, and the
adjustment void, `stock-adjustment.service.ts:496`, posted it).
Example, negative stock allowed, card cost 10.00: sell 1 with none on the
shelf (Inventory -10.00), then buy 1 at 12.00: the shelf is empty and worth
0.00, but Inventory (1200) holds 2.00, and the inventory report says the
stock value does not match the accounts.
Proposed: post the residual as the void does.
Now: each of the four sums the movements' residuals and posts them with
`PurchaseService::postCostingResidual` in the same transaction, under the
document's own number, date and source (Dr 5000 / Cr 1200, or the reverse):
1200 reads 0.00 and cost of goods sold 12.00 (`CostingResidualTest`,
`BooksInvariantsTest` with negative stock allowed).

**D27. A customer return whose cost share is zero leaves the goods off the
shelf. DONE (2026-11-06, approved by the owner).** Old
`returns.service.ts:319` restocks only when the piece's cost share is not
zero (new `ReturnsService::createCustomerReturn`). Example: 3 nails costing
0.02 in all, returned one at a time: the cost shares are 0.01, 0.00, 0.01, so
the middle nail never goes back on the shelf; a free sample never comes back
at all. Proposed: always restock a stock-tracked line.
Now: every returned stock-tracked line goes back on the shelf, at a zero cost
when its share is zero; all 3 nails are back and stock value (0.02) agrees
with 1200 (`CustomerReturnSharesTest`).

**D28. A product's stock tracking could change after it had history. DONE (2026-11-06, approved by the owner).** Old `products.service.ts` let the flag change at any time, and returns and voids read it when they run, so a product sold untracked and then switched on put stock back with no cost. Now: the change is refused once the product has stock movements or sale or purchase lines ("…has already been bought, sold or stocked, so whether it tracks stock cannot change. Create a new stock item instead."). A new product can still change (`ProductDeleteTest`).

**D29. Refunds could be paid from an archived payment account. DONE (2026-11-06, approved by the owner).** Old `returns.service.ts` refund loops did not check `is_active`, though a till tender did. Now customer and supplier refunds from an archived account are refused with "Payment account "X" is not active." (`RefundCapTest`).

**D30. An account ledger with no from date shows every line ever posted. DONE
(2026-11-06, approved by the owner).** Old `ledger/[id]/page.tsx` read all time
when no `from` was given, so a busy account rendered years of lines.
Now: the ledger starts on the 1st of this month, as the journal does, and the
earlier lines are carried in the opening balance; the closing figure is
unchanged. A `to` alone before the 1st starts at the 1st of its own month
(`AccountingFiltersTest`, `AccountingScreensTest`). README differences row
"Journal, ledger, trial balance and audit dates".

**D31. Backup download is for owners only. DONE (2026-11-06, approved by the owner).** Old: anyone with settings edit could download the full backup, which holds every password hash and figure. Now the Health screen's download needs an owner, as updates do; staff with full Settings rights get 403 and see "Only the shop owner can take a backup" (`HealthScreenTest`). `php artisan nuna:backup` on the PC is unchanged.

**D32. Ageing as at a past date leaves out a payment voided after that date.
DONE (2026-11-06, approved by the owner).** Old `reporting/ledger.service.ts`
ageing (new `SaleService::getOutstandingBySale` and
`PurchaseService::getOutstandingByPurchase` with `asAt`) counts only payments
still `POSTED` today. Example: a credit sale of 100.00 on 5 Jan, paid on
10 Jan, the payment voided on 3 Feb: receivables ageing as at 31 Jan shows
100.00 owed, while the balance sheet and the subledger as at 31 Jan show 0.00.
Proposed: show it as it stood that day.
Now: with `asAt`, a customer or supplier payment's allocations count when the
payment is dated on or before that day and is still posted or was voided
after it (the date of its reversal entry, else `voided_at`); a void dated on
or before the day removes it. Sale, purchase and return voids already used
their mirror's date. The example reads 0.00 as at 31 Jan, agreeing with the
balance sheet and subledger, and 100.00 as at 3 Feb
(`VoidedPaymentAgeingTest`; reconcile's `OldReports::ageing` works out the
same rule from the old copy on its own).

**D33. A customer statement as at a past date shows what was owed that day.
DONE (2026-11-07, approved by the owner).** Old
`(app)/customers/[id]/statement/page.tsx` printed only as at today: it cut
the invoices off at the date but took each one's amount from
`getOutstandingBySale` with no date and the total from `getCustomerBalance`
with no date, so the same code as at an earlier day would count later
payments. The port's `CustomerPaymentService::getCustomerStatement` took an
`asAt` with that gap. Example: an invoice of 100.00 on 5 Jan, 60.00 paid on
20 Jan: as at 15 Jan the statement showed 40.00 owed.
Proposed: as it stood that day, by ageing's rule (D32).
Now: with `asAt`, each invoice's amount is `SaleService::getOutstandingBySale`
with `asAt` (later payments, allocations, returns and credits left out; a
payment voided after the day still counts), a sale voided after the day
still shows, and the total is `CustomerService::getCustomerBalance` with
`asAt` (A/R entries dated on or before it). The example reads 100.00 as at
15 Jan and 40.00 as at 20 Jan, and the total equals that customer's
receivables ageing as at the same day (`CustomerStatementAsAtTest`). Without
`asAt` (the Statement page) nothing changes.
