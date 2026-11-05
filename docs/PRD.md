# Product Requirements: NunaBooks (Laravel + MySQL rebuild)

**Status:** Draft · **Owner:** TBD · **Last updated:** 2026-11-05

This is a rebuild, not a new product. The requirements are the old app's behaviour (`C:\ClaudeProjects\bookkeeper`), unchanged unless [../PLAN.md](../PLAN.md) records a reason.

## 1. Problem
A small Ghanaian retail shop needs its sales, stock, debts, cash and tax kept correctly, on its own PC, with no internet needed to make a sale. NunaBooks already does this on Next.js + SQLite. It is being moved to Laravel + MySQL (reason: the owner's choice of stack, given in PROMPT.md; any wider motive is TBD). The risk is that the move silently changes a figure.

## 2. Goals
- Every figure the old app shows is the same in the new app for the same data: `nuna:reconcile` reports zero differences.
- Two tills selling at once can never oversell or leave a gap in invoice and receipt numbers.
- The shop still trades with no internet, and the owner can back up and restore without help.

### Non-goals
- New features, multiple branches, FIFO costing, a free-form journal, internet hosting, pixel-identical styling.

## 3. Users and personas
| Persona | Description | Key need |
|---|---|---|
| Owner | Runs the shop; role OWNER | Trustworthy figures, control of staff rights, backups, year-end for the accountant |
| Till staff | Sells at the counter; role STAFF with per-module rights; signs in with a PIN | Fast sales, no way to break the books |
| Accountant | Receives the year-end pack | P&L, balance sheet, owner's-stake movement, trial balance, tax return |

## 4. User stories
- As **till staff**, I want to sell by unit or by carton and take cash, MoMo or a split, so the queue keeps moving.
- As the **owner**, I want to click any balance and see the lines behind it, so I can answer "why is cash GHS 5,240?".
- As the **owner**, I want to close a month or year, so nobody quietly rewrites it.
- As the **owner**, I want a verified backup I can restore, so a dead PC does not end the business.

## 5. Functional requirements
| ID | Requirement | Priority | Phase |
|---|---|---|---|
| FR-1 | Schema enforces every old rule (CHECKs, FKs, uniqueness); integer money and quantity | Must | 0 |
| FR-2 | Products, categories, units, cartons (locks), import from Excel/CSV, replace a locked product, delete unused / archive used | Must | 3 |
| FR-3 | Stock by weighted average running pair; batches and expiry (FEFO); adjustments by unit cost or total value | Must | 3 |
| FR-4 | Till: split tender, discounts (with right), tax inclusive or exclusive (VAT, NHIL, GETFund), receipts, idempotent submission | Must | 4 |
| FR-5 | Credit sales with invoices, terms and due dates; customer payments with allocation; overpayment setting; statements | Must | 4 |
| FR-6 | Returns with refund cap and credit; voids with dated mirrors; backdated sales | Must | 4 |
| FR-7 | Quotations that convert once to a sale at the quoted price | Must | 4 |
| FR-8 | Purchases with tax recovery rules, supplier returns, voids, supplier payments | Must | 5 |
| FR-9 | Expenses, other income, owner capital and drawings, transfers between accounts | Must | 6 |
| FR-10 | Cash, MoMo and bank reconciliation with over/short | Must | 6 |
| FR-11 | Trial balance, P&L, balance sheet, cash flow, journal, general ledger, ageing, tax return, sales/purchase/inventory reports, CSV exports | Must | 7 |
| FR-12 | Books lock; year-end close and reopen; year-end pack | Must | 7 |
| FR-13 | Dashboard, search, notifications | Must | 7–8 |
| FR-14 | Users, roles, per-module permissions, PINs, lockout, insert-only audit log | Must | 8 |
| FR-15 | Settings, logo, three looks, light/dark, in-app Help | Must | 8 |
| FR-16 | Verified backup and tested restore; installer and in-app update on Windows | Must | 8 |

## 6. Non-functional requirements
- **Correctness:** figures match the old app to the pesewa; every journal entry balanced inside its transaction.
- **Performance:** at least as fast as the old benchmarks at a year of trading (journal page < 10 ms, trial balance and balance sheet < 1 s). Exact targets TBD after the first benchmark.
- **Availability:** works with no internet; services start at boot.
- **Security and privacy:** [../SECURITY.md](../SECURITY.md).
- **Accessibility:** keep the old app's rules (charts have text equivalents, reduced motion means none). Standard: TBD.
- **Platforms:** Windows shop PC as the server; current Chrome/Edge on the PC and Android/iOS phones on the LAN.

## 7. Success metrics
| Metric | Target | How measured |
|---|---|---|
| Figure differences on real data | 0 | `nuna:reconcile` |
| Old service tests with a Laravel equivalent | 66 of 66 | TASKS.md |
| Oversell or number gap under concurrent sales | 0 | concurrency tests |
| Backup restore matches | yes | restore test |

## 8. Assumptions and constraints
- One shop, one stock location, currency GHS (configurable until the first transaction).
- The owner cannot administer a database server, so install, start, update and backup must be scripted.
- Deadline, budget and team size: TBD.

## 9. Open questions
- [ ] PLAN.md Q1–Q8.
- [ ] Product owner name and cut-over date.
- [ ] Ghana Data Protection Act (Act 843) obligations for customer data.

Related: [Architecture](ARCHITECTURE.md) · [Roadmap](ROADMAP.md)
