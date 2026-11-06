# UI conventions (Phase 9)

How module screens are built on the shell. Read [AGENTS.md](../AGENTS.md) first; the bar there applies to every screen.

## 1. Where things go

| What | Where |
|---|---|
| Full-page screens | `app/Livewire/<Module>/<Page>.php` (e.g. `App\Livewire\Sales\Index`) |
| Their views | `resources/views/livewire/<module>/<page>.blade.php` (one root element) |
| Routes | `routes/modules/<module>.php`, one file per module. `routes/web.php` requires every file there **inside** the signed-in group (`auth`, `session.limits`, `password.chosen`). Never edit `web.php`. |
| Print/receipt views, file downloads | `app/Http/Controllers/<Module>/...` + `resources/views/<module>/...` |
| Tests | `tests/Feature/<Module>/...Test.php` |

The example is `routes/modules/settings.php` + `App\Livewire\Settings\Index`.

## 2. Routes and permissions

Every route carries `permission:<module>,<action>` (`PermissionModule` value; `view|create|edit|void`):

```php
Route::get('/sales', Sales\Index::class)->middleware('permission:sales,view');
Route::get('/sales/new', Sales\Create::class)->middleware('permission:sales,create');
```

`tests/Feature/RouteGuardTest.php` fails for any route without `auth`, `session.limits`, `password.chosen` and a `permission:`; the open list in that file is for the shell only. A refused GET goes to `/no-access?area=<module>`; anything else gets 403.

Inside a screen, ask the same Gate before showing a button or reading a panel: `@can('sales.void')`, `Gate::allows('sales.void')`. Livewire actions are POSTs to `/livewire/update`, which the route middleware does NOT cover, so **every write action re-checks its own permission** first:

```php
public function void(): void
{
    $this->authorize('sales.void');   // 403 otherwise
    ...
}
```

The signed-in person as the services want them: `Principal::load(auth()->id())->actor()`. Pass `ClientAddress::fromRequest(request())` and `request()->userAgent()` where a service records them.

## 3. Pages hold no business logic

A page parses input, calls a service, formats the result. No money arithmetic, no totals, no SQL, no `DB::` in `app/Livewire` or views. If a figure is missing, add it to the service (with a test), not the page. Copy the old app's labels, headings, hints and messages word for word.

**Formatting** always goes through the domain:

- Blade: `@money($minor)`, `@qty($milli, $unit)`, `@date('2026-08-05')`
- Expressions/attributes: `App\View\Format::money($v)`, `::money($v, bare: true)`, `::moneyAccounting($v)`, `::qty()`, `::date()`, `::dateTime($utc)`, `::plural()`
- Table cells: `<x-money-cell :value="$row['total']" />` (red when negative).

Never `number_format` or divide by 100 in a view. Form inputs parse back with `Money::parse()` / `Quantity::parse()` in the service call, not by hand.

## 4. Components (`resources/views/components`)

| Component | Use |
|---|---|
| `<x-layouts::app title="...">` | The shell, for plain (controller) views. Livewire pages get it automatically (`config/livewire.php`); set the tab title with `#[Title('Sales')]`. Also `layouts::auth`, `layouts::account`. |
| `<x-page-header title description>` + `<x-slot:actions>` | Page title and its buttons. |
| `<x-button variant=primary/secondary/ghost/danger size=sm/md/lg href?>` | Buttons; `href` makes a link. |
| `<x-card title?>`, `<x-stat label value hint tone icon size>` | Cards and single figures (`value` already formatted). |
| `<x-table>` + `<x-slot:head>` + `<x-slot:foot>` | Tables; rows as `<tr class="border-b border-line last:border-0">` with `<x-td numeric?>`, `<x-money-cell>`, `<x-empty-row colspan>`. |
| `<x-th sort="amount" :sorts="SaleService::SALE_SORTS" :list="$list" numeric>` | Sortable heading. `sorts` is the module's whitelist (the constant ListFilters passes to `Filters::parseSort`); a key outside it throws. |
| `<x-filter-bar :list :dates :fields>` | GET filter form. Each field passes its **resolved** `value` (required); the period comes from `$list['range']`/`['preset']`. Carries sort and other keys; drops `page`. Field kinds: `search`, `select`, `amount-range`, `date`. `:quick` adds the quick-filter chips; a removable "Showing:" chip appears for each field the URL set (`'chip' => false` to opt out, `:active` for extras). |
| `<x-pagination :list :total noun>` | Paging over the same filters. |
| `<x-csv-button list="sales" :query="$list['carried']" />` / `report="profit-and-loss"` | Download through `GET /export/list/{list}` or `/export/report/{report}` (ExportController: per-list permission, per-person throttle, 401/403/404). |
| `<x-field label for name? hint required>` + `<x-input :invalid amount?>` / `<x-date-input>` | Form fields; the error shown is `$errors->first(name)`. |
| `<x-confirm-dialog title description trigger heading confirm wire:submit="void">` | Two-step confirm (void, delete) with fields in the slot. Reopens only for its own errors (`:keys`, default `reason` and `form`). |
| `<x-alert tone>`, `<x-badge tone>`, `<x-empty-state title description>` + `action` slot, `<x-icon name>` | As the old ui components. |
| `<x-chart.trend>`, `<x-chart.bars>` | Server SVG charts; final figures in, pixels out. |
| `<x-flash />` | Already in the shell. Redirect with `->with('status', 'Saved.')` (or `'error'`); from Livewire without a redirect, `$this->dispatch('toast', message: 'Saved.')`. |

Styling uses the token classes from `resources/css/app.css` (`bg-surface-raised`, `text-content-muted`, `border-line`, `text-accent`, `text-danger`, `tabular`, `no-print`...), never raw colours, so all three looks and dark mode work. `npm run build` after adding classes in new files.

## 5. List-page pattern

One parser, shared by page and CSV (filter rules: parse once, filter in SQL before the limit, totals with the same conditions, validate everything, controls show the resolved value, reads never write):

```php
#[Title('Sales')]
final class Index extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();   // kept: /livewire/update requests carry no page query
    }

    public function render(): View
    {
        $list = ListFilters::parseSalesFilters($this->params);
        $page = ['limit' => $list['pageSize'], 'offset' => ($list['page'] - 1) * $list['pageSize']];

        return view('livewire.sales.index', [
            'list' => $list,
            'rows' => SaleService::listSales([...$list['filters'], ...$page]),
            'total' => SaleService::countSales($list['filters']),
            'summary' => SaleService::getFilteredSalesSummary($list['filters']),
        ]);
    }
}
```

View: `<x-filter-bar>` → `<x-table>` with `<x-th sort>` → `<x-pagination>`; `<x-csv-button list="sales" :query="$list['carried']">` in the header actions. Filters are plain GET links/forms (relative `?...` hrefs), so every view is a shareable URL and the CSV gets the same query. If a module has no `ListFilters::parseX` yet, add one there (with a test), not in the page.

## 6. Form-page pattern

Validate in the service. The component collects input, calls the service, and turns a `DomainError` into messages:

```php
public function save(): void
{
    $this->authorize('customers.create');
    try {
        $id = CustomerService::createCustomer([...], $this->actor());
    } catch (ValidationError $e) {
        // Field-specific when the service names the field in details, else a form-level message.
        $field = array_key_first(array_intersect_key($e->details, array_flip(['name', 'phone']))) ?? 'form';
        $this->addError($field, $e->userMessage);
        return;
    } catch (DomainError $e) {
        $this->addError('form', $e->userMessage);
        return;
    }
    session()->flash('status', 'Customer added.');
    $this->redirect("/customers/{$id}");
}
```

Show `@error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror` at the top and `<x-field name="...">` per field. Livewire's own `$this->validate()` is fine for shape checks the old zod schema made (required, max length) with the old messages; money rules stay in the service. Disable the submit while it runs (`wire:loading.attr="disabled"`) and keep the sale idempotency key (`client_ref`) generated once per form.

## 7. Receipt and print views

Receipts, invoices, statements and quotation prints are plain controller routes rendering a Blade view inside `<x-layouts::app>` (or a bare document for the 80 mm receipt), behind the same `permission:` middleware. Mark screen-only chrome `no-print`; the print rules in `app.css` (A4, zero margin with body padding, repeated table headers) apply automatically. A "Print" button is `<x-button x-on:click="window.print()">Print</x-button>`.

## 8. Feature tests

Helpers in `tests/Pest.php`: `owner()`, `staff([...permissions])`, `viewOnly()`, `signedIn($userId)` (a real session that passes `session.limits`). Data via `Tests\Support\Shop`.

```php
it('lists sales for somebody with the right and refuses everyone else', function () {
    $this->get('/sales')->assertRedirect('/login');
    signedIn(staff(['products' => viewOnly()]))->get('/sales')->assertRedirect('/no-access?area=sales');
    signedIn(staff(['sales' => viewOnly()]))->get('/sales')->assertOk()->assertSee('Sales');
});

it('voids a sale with a reason', function () {
    $id = owner();
    Livewire::actingAs(App\Models\User::find($id))
        ->withQueryParams(['period' => 'all'])
        ->test(Sales\Show::class, ['sale' => $saleId])
        ->set('reason', 'Rung up twice')
        ->call('void')
        ->assertHasNoErrors();
    expect(...)->toBe(...);   // the figure a shop owner would check, in minor units
});
```

Also test: the action refused without its right (`->call('void')->assertForbidden()`), each filter narrowing rows and totals alike, and that a list and its CSV agree. Run `vendor/bin/pest --parallel --exclude-testsuite=Processes` and `vendor/bin/pint --test`.

## 9. Old pages → routes, split into four groups

Groups own disjoint files (`app/Livewire/<Module>`, `resources/views/livewire/<module>`, `routes/modules/<file>.php`, `tests/Feature/<Module>`). Done already (shell): `/`, `/login`, `/setup`, `/account/password`, `/dashboard`, `/no-access`, the two export routes.

### Group A: sales, POS, customers, quotations (15 pages) — `routes/modules/sales.php`, `customers.php`, `quotations.php`

| Old page | Route | Permission |
|---|---|---|
| sales/page.tsx | GET /sales | sales,view |
| sales/new (pos.tsx) | GET /sales/new | sales,create |
| sales/[id] (void, returns) | GET /sales/{id} | sales,view (void: sales,void in the action) |
| sales/[id]/receipt | GET /sales/{id}/receipt | sales,view |
| sales/[id]/invoice | GET /sales/{id}/invoice | sales,view |
| customers/page.tsx | GET /customers | customers,view |
| customers/new | GET /customers/new | customers,create |
| customers/[id] (receive payment) | GET /customers/{id} | customers,view |
| customers/[id]/edit | GET /customers/{id}/edit | customers,edit |
| customers/[id]/statement | GET /customers/{id}/statement | customers,view |
| quotations/page.tsx | GET /quotations | quotations,view |
| quotations/new | GET /quotations/new | quotations,create |
| quotations/[id] (convert) | GET /quotations/{id} | quotations,view |
| quotations/[id]/edit | GET /quotations/{id}/edit | quotations,edit |
| quotations/[id]/print | GET /quotations/{id}/print | quotations,view |

### Group B: inventory, catalog, purchases, suppliers (20 pages) — `products.php`, `inventory.php`, `purchases.php`, `suppliers.php`

| Old page | Route | Permission |
|---|---|---|
| products/page.tsx | GET /products | products,view |
| products/new | GET /products/new | products,create |
| products/[id]/edit (remove-product) | GET /products/{id}/edit | products,edit |
| products/[id]/replace | GET /products/{id}/replace | products,edit |
| products/categories | GET /products/categories | products,view |
| products/units | GET /products/units | products,view |
| products/import | GET /products/import | products,create |
| products/import/template (route.ts) | GET /products/import/template | products,create |
| inventory/page.tsx | GET /inventory | inventory,view |
| inventory/adjustments | GET /inventory/adjustments | inventory,view |
| inventory/adjustments/new | GET /inventory/adjustments/new | inventory,create |
| inventory/adjustments/[id] (void) | GET /inventory/adjustments/{id} | inventory,view |
| inventory/batches/[id] (expiry) | GET /inventory/batches/{id} | inventory,view |
| purchases/page.tsx | GET /purchases | purchases,view |
| purchases/new | GET /purchases/new | purchases,create |
| purchases/[id] | GET /purchases/{id} | purchases,view |
| suppliers/page.tsx | GET /suppliers | suppliers,view |
| suppliers/new | GET /suppliers/new | suppliers,create |
| suppliers/[id] (pay supplier) | GET /suppliers/{id} | suppliers,view |
| suppliers/[id]/edit | GET /suppliers/{id}/edit | suppliers,edit |

### Group C: cashbook, accounts, reports, year-end (23 pages, many read-only reports) — `cashbook.php`, `accounts.php`, `accounting.php`, `reconciliation.php`, `reports.php`

| Old page | Route | Permission |
|---|---|---|
| expenses/page.tsx | GET /expenses | expenses,view |
| income/page.tsx | GET /income | income,view |
| accounts/page.tsx (new/edit account, owner money) | GET /accounts | accounts,view |
| accounts/[id] (statement; `cash`/`momo`/`bank` slugs) | GET /accounts/{id} | accounts,view |
| accounts/transfers | GET /accounts/transfers | accounts,view |
| accounting/page.tsx (books lock, year-end close) | GET /accounting | accounts,view |
| accounting/chart | GET /accounting/chart | accounts,view |
| accounting/journal | GET /accounting/journal | accounts,view |
| accounting/journal/[id] | GET /accounting/journal/{id} | accounts,view |
| accounting/ledger/[id] | GET /accounting/ledger/{id} | accounts,view |
| accounting/trial-balance | GET /accounting/trial-balance | accounts,view |
| accounting/receivables | GET /accounting/receivables | accounts,view |
| accounting/payables | GET /accounting/payables | accounts,view |
| reconciliation/page.tsx | GET /reconciliation | reconciliation,view |
| reports/page.tsx | GET /reports | reports,view |
| reports/profit-and-loss | GET /reports/profit-and-loss | reports,view |
| reports/balance-sheet | GET /reports/balance-sheet | reports,view |
| reports/cash-flow | GET /reports/cash-flow | reports,view |
| reports/sales | GET /reports/sales | reports,view |
| reports/purchases | GET /reports/purchases | reports,view |
| reports/inventory | GET /reports/inventory | reports,view |
| reports/tax | GET /reports/tax | reports,view |
| reports/year-end | GET /reports/year-end | reports,view |

Check each old page's own `requirePageAccess` call when building it; where it differs from this table, the old page wins.

### Group D: settings, users, audit, backup, help, search (13 pages/routes) — `settings.php` (exists: replace the stub), `users.php`, `help.php`, `search.php`, `system.php`

| Old page | Route | Permission |
|---|---|---|
| settings/page.tsx (settings form, logo, tax components, looks) | GET /settings | settings,view |
| settings/health (update panel, backups) | GET /settings/health | settings,view |
| api/logo (route.ts) | GET /logo, POST /settings/logo | settings,view / settings,edit |
| api/backup (route.ts) | POST /settings/backup | settings,edit (HttpThrottle::BACKUP) |
| api/update (route.ts) | POST /settings/update | settings,edit |
| users/page.tsx | GET /users | users,view |
| users/new | GET /users/new | users,create |
| users/[id] (admin forms, PIN, reset, unlock) | GET /users/{id} | users,view |
| users/audit | GET /users/audit | users,view |
| help/page.tsx | GET /help | any signed-in person: add `/help` and `/help/{slug}` to `OPEN_ROUTES` in RouteGuardTest with the reason |
| help/[slug] | GET /help/{slug} | as above |
| search/page.tsx | GET /search | any signed-in person (results filtered by `SearchService` per right): add to `OPEN_ROUTES` |
| api/search (route.ts) | GET /search/suggest | as above, HttpThrottle::SEARCH |

Not ported as pages: `layout.tsx`, `loading.tsx`, `error.tsx`, `global-error.tsx` (the shell, and Laravel's error views: `resources/views/errors/404.blade.php` exists; add 500 if wanted).
