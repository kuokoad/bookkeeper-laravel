# API Reference

**Base URL:** `http://<shop-host>` on the shop LAN · **Style:** server-rendered pages (Blade + Livewire) plus a few GET endpoints for CSV, files and search · **Last updated:** 2026-11-05 · **Status:** planned

There is no public API. Mutations happen through forms and Livewire actions inside the session; each one re-checks the session and the permission on the server and then calls a service. This page lists the non-page HTTP endpoints carried over from the old app (`src/app/api/**`) and the permission each needs. Pages and their modules are in the old `docs/reference/routes-and-permissions.md`, which is ported to `resources/docs/reference/` in Phase 8.

## Authentication
Laravel session cookie (`HttpOnly`, `SameSite=Lax`). Sign in at `/login` with a password or a till PIN. CSRF tokens on every form. See [../SECURITY.md](../SECURITY.md).

## Conventions
- **CSV:** UTF-8, one header row; money written as decimal strings from integer minor units (never floats); dates as `YYYY-MM-DD`.
- **Errors:** pages redirect to `/no-access?area=<module>`; endpoints return `403`. Bad filter input narrows nothing rather than failing (filter rule 4).
- **Filters:** the same query parameters as the page the export comes from, parsed by the same parser (filter rule 1). Full list: old `docs/reference/filters.md`.
- **Dates:** business dates `YYYY-MM-DD`, inclusive ranges.

## Endpoints

#### `GET /exports/{list}`
Filtered CSV of a list. `list` ∈ sales, purchases, expenses, income, products, stock-movements, customers, suppliers, account. **Auth:** view on that list's module. At most 5,000 rows; throttled 30/min per user.

#### `GET /reports/{report}/csv`
Report CSV. `report` ∈ profit-and-loss, balance-sheet, cash-flow, sales, purchases, inventory, tax, expiry, receivables, payables, year-end. **Auth:** reports view. Throttled.

#### `GET /backup`
Streams a fresh, verified `mysqldump` (gzipped). Replaces the old SQLite file download. **Auth:** settings edit. Throttled 6 per 5 min. Writes an audit row (`entity_type = backup`, `CREATE`).

#### `GET /logo`
The shop logo from the database, with its sniffed MIME type, `X-Content-Type-Options: nosniff`, a sandbox CSP and `Last-Modified`. `404` if none. **Auth:** none (printed on receipts).

#### `GET /search?q=`
JSON results grouped by type (product, batch, customer, supplier, sale, purchase, journal), 8 per group, each group only if the user can view it. Needs at least 2 characters; wildcards escaped. **Auth:** signed in. Throttled 240/min.

#### `GET /update/status`
Status of a running self-update. **Auth:** owner (old: settings edit; PLAN keeps updates owner-only).

#### `GET /products/import/template`
CSV template for product import. **Auth:** products create.

## Webhooks / events
None.

## Error codes
| Code | HTTP | Meaning |
|---|---|---|
| unauthenticated | 302 → `/login` | No session |
| forbidden | 403 / redirect `/no-access` | Missing module right |
| throttled | 429 | Export, backup or search limit hit |
| not_found | 404 | Unknown list, report or record |

Entities: [DATA_MODEL.md](DATA_MODEL.md)
