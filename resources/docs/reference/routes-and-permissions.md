# Routes and permissions

Every page and download route, and what it requires.

## How permission works

Fourteen modules: `sales`, `purchases`, `inventory`, `products`, `customers`,
`suppliers`, `expenses`, `income`, `accounts`, `reports`, `reconciliation`,
`quotations`, `users`, `settings`.

Four actions per module: `view`, `create`, `edit`, `void`.

An owner holds everything. Staff hold whatever the owner granted, per module and per
action. Creating, editing or voiding also needs `view` on the same module.
`App\Auth\Permissions` makes the decision, and the Gate abilities (`sales.view`,
`sales.create` and so on) ask it.

Every page route carries `permission:<module>,<action>` middleware. Refused on a
page, a person is sent to the no-access page, which explains; anything that is not a
page gets a plain 403. The difference matters: a bare error is right for a caller
that is code, and wrong for someone who typed an address.

## Pages

### Daily

| Route | Module |
|---|---|
| `/dashboard` | none (any signed-in user); each panel asks its own permission |
| `/sales` | `sales` view |
| `/sales/new` | `sales` create |
| `/sales/{id}` | `sales` view |
| `/sales/{id}/receipt` | `sales` view |
| `/sales/{id}/invoice` | `sales` view |
| `/quotations` | `quotations` view |
| `/quotations/new` | `quotations` create |
| `/quotations/{id}` | `quotations` view |
| `/quotations/{id}/edit` | `quotations` edit |
| `/quotations/{id}/print` | `quotations` view |
| `/purchases` | `purchases` view |
| `/purchases/new` | `purchases` create |
| `/purchases/{id}` | `purchases` view |
| `/expenses` | `expenses` view |
| `/income` | `income` view |

### Stock

| Route | Module |
|---|---|
| `/products` | `products` view |
| `/products/new` | `products` create |
| `/products/import` | `products` create |
| `/products/import/template` | `products` create (the CSV heading row) |
| `/products/{id}/edit` | `products` edit |
| `/products/{id}/replace` | `products` edit **and** `products` create **and** `inventory` create: it creates a product and moves stock |
| `/products/categories` | `products` view |
| `/products/units` | `products` view |
| `/inventory` | `inventory` view (the stock ledger) |
| `/inventory/adjustments` | `inventory` view |
| `/inventory/adjustments/new` | `inventory` create |
| `/inventory/adjustments/{id}` | `inventory` view |
| `/inventory/batches/{id}` | `products` view, as the old page asked; changing the expiry needs `inventory` edit |

### People

| Route | Module |
|---|---|
| `/customers`, `/customers/{id}` | `customers` view |
| `/customers/new` | `customers` create |
| `/customers/{id}/edit` | `customers` edit |
| `/customers/{id}/statement` | `customers` view |
| `/suppliers`, `/suppliers/{id}` | `suppliers` view |
| `/suppliers/new` | `suppliers` create |
| `/suppliers/{id}/edit` | `suppliers` edit |

### Money

| Route | Module |
|---|---|
| `/accounts` | `accounts` view; recording a transfer or owner money from it needs `accounts` create |
| `/accounts/{id}` | `accounts` view (one account's statement) |
| `/accounts/transfers` | `accounts` view; voiding one needs `accounts` void |
| `/accounting` | `accounts` view |
| `/accounting/chart` | `accounts` view |
| `/accounting/journal`, `/accounting/journal/{id}` | `accounts` view |
| `/accounting/ledger/{id}` | `accounts` view |
| `/accounting/trial-balance` | `accounts` view |
| `/accounting/receivables` | `accounts` view |
| `/accounting/payables` | `accounts` view |
| `/reconciliation` | `reconciliation` view |

### Reports

`/reports` and every page under it require `reports` view:
`balance-sheet`, `cash-flow`, `inventory`, `profit-and-loss`, `purchases`, `sales`,
`tax`, `year-end`.

### Admin and account

| Route | Module |
|---|---|
| `/users`, `/users/{id}` | `users` view |
| `/users/new` | `users` create |
| `/users/audit` | `users` view |
| `/settings` | `settings` view |
| `/settings/health` | `settings` view |
| `/account/password` | signed in. Deliberately **not** gated on a module, or somebody forced to change their password could never do it |
| `/help`, `/help/{slug}` | signed in. The staff member who most needs to read how the till works holds the fewest rights |

### Signed in, no module

| Route | Notes |
|---|---|
| `/no-access` | shown when a signed-in user lacks the module |
| `/search` | searches only the record types the person may view, so it refuses nobody |

### Unauthenticated

| Route | Notes |
|---|---|
| `/` | sends you to setup, sign-in or the dashboard |
| `/login` | password; `/login?mode=pin` opens the till PIN tab |
| `/setup` | closes permanently once any account exists. Only from the shop PC itself unless `NUNA_SETUP_FROM_LAN` is set |

## Downloads and other routes

| Route | Requires | Notes |
|---|---|---|
| `/export/list/{list}` | view on **that list's own module** | Filtered CSV. Throttled per person (30 a minute). Answers 401, 403 or 404 in plain text, never a redirect. |
| `/export/report/{report}` | `reports` view; `receivables` and `payables` also need `accounts` view | Report CSV. Throttled per person, as above. |
| `POST /settings/backup` | `settings` **edit**, and the owner | Downloads a verified backup. Staff are refused even with full Settings rights: the file holds every customer and every figure. |
| `/settings/update` | `settings` edit | The update status on the Health screen. |
| `POST /settings/logo`, `POST /settings/logo/remove` | `settings` edit | Sets or removes the shop logo. |
| `/logo` | signed in | Serves the shop logo, which till staff print on receipts without the settings right. Sends `X-Content-Type-Options: nosniff`. |
| `/search/suggest` | signed in | The search box's suggestions, filtered by permission like `/search`. |

`/export/list/{list}` takes `sales`, `purchases`, `expenses`, `income`, `products`,
`stock-movements`, `customers`, `suppliers`, `account`, `quotations`.
`stock-movements` needs `inventory` view and `account` needs `accounts` view.

`/export/report/{report}` takes `profit-and-loss`, `balance-sheet`, `cash-flow`,
`sales`, `purchases`, `inventory`, `tax`, `expiry`, `receivables`, `payables`,
`year-end`.

List exports are checked **per module**: someone who may see sales but not expenses
cannot export expenses by guessing a URL. Report exports need `reports` view, and
`receivables` and `payables` also need `accounts` view, as their screens do
(`ExportController::REPORT_MODULES`). The old app let `reports` view alone download
them.

The exports sit outside the signed-in group (`session.limits` only). The Gate
abilities themselves refuse anyone who has not yet chosen their own password
(`AppServiceProvider`), and that, with the controller's 401 for no session, is what
guards `/export/*`. Do not loosen the Gate on the strength of `password.chosen`.

## The business type is not a permission

No route is gated by the shop's business type, and none ever should be.

A permission answers "may this person see it" and is enforced by the route's own
`permission:` middleware. A business type answers "does this shop want to be offered
it", is enforced by the menu, and protects nothing: type the address of a
feature the shop has put away and the page opens normally.

That is deliberate, and it is what makes the setting safe. A shop changes type
after months of trading, and every quotation, delivery or dated batch it
recorded before the change must still open from a link, a search result, or a
reference on a printed receipt. Guarding a route on a feature would break all
three, silently, for records that are still perfectly valid.

`App\Enums\Feature` holds the switches and each business type's defaults.
`App\View\Navigation::sectionsFor()` hides a menu entry whose feature is off.
`tests/Feature/LayoutTest.php` checks that the menu offers Quotations only to a shop
that switched it on, and never to someone without the right.

**Quotations is the worked example.** Its menu entry declares both
`'module' => 'quotations'` and `'feature' => 'quotations'`, and the two do different
jobs. The module is a permission and the routes enforce it. The feature is the
shop saying it does not quote, and the routes ignore it entirely: turn quoting
off in a shop that has written quotes and every one of them still opens from a
link, a search result, or the number printed on a customer's copy.

## Things that are not routes

- Livewire actions are POSTs to `/livewire/update`, which route middleware does not
  cover. Every write action calls `$this->authorize('<module>.<action>')` before
  anything else. They are entry points too, and are guarded as such.
- Every module's routes live in `routes/modules/<module>.php` and are required inside
  the signed-in group in `routes/web.php` (`auth`, `session.limits`,
  `password.chosen`), so an unauthenticated request never reaches a route's own
  permission check. `session.limits` ends a session past 30 days from sign-in, or
  once the account is switched off or its password, role or permissions change.
  `password.chosen` sends somebody whose password was set by someone else to choose
  their own first. `/dashboard`, `/search` and `/help` rely on that group alone:
  they gate on being signed in, not on a module.
- `tests/Feature/RouteGuardTest.php` fails any route outside its short open list that
  lacks sign-in, session limits, `password.chosen` and a `permission:` middleware.

## Related

- **docs/ARCHITECTURE.md §4.1**: routes and gates in the Laravel app.
- **SECURITY.md, "Authentication and authorization"**: the permission model and session handling.
- [Filters](filters.md): the query parameters these pages accept.
