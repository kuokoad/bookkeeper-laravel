# Filtering

Why filtering is built the way it is, and the mistake it exists to prevent.

## The problem

A bookkeeping page can mislead in a way an ordinary app cannot. If a photo gallery
shows the wrong twelve photos, you notice. If a sales page shows twenty-seven cash
sales under a revenue figure that includes the MoMo ones, the number looks
completely normal. Nobody goes looking for it. They take it to their accountant.

Every rule below exists because breaking it produced exactly that: a plausible
figure that was wrong.

## The four rules

### 1. Parse once, share the parser with the export

`app/Support/Filters/ListFilters.php` holds one parser per module
(`parseSalesFilters`, `parsePurchaseFilters` and so on). The page uses it, and so
does the CSV route.

An export that reads the query string its own way is how a downloaded file stops
matching the screen it was downloaded from — and the file is the thing that gets
emailed to the accountant, so it is the copy that matters most. Same parser, same
query, same rows.

### 2. Filter in SQL, before the limit

"Unpaid only" once fetched a page of sales and dropped the settled ones in code.
That answers a different question — *which of the most recent hundred sales are
unpaid* — and it gets quietly wronger the more the shop sells. A shop with two
hundred sales a month sees a credit list that silently stops.

Every filter is now a SQL condition, applied before `LIMIT`. Outstanding balances,
low stock and customer balances are all SQL expressions rather than values computed
after the fact, precisely so they can narrow rows rather than be dropped from them.

### 3. Totals come from the same clause as the rows

`SaleService::listSales`, `countSales` and `getFilteredSalesSummary` all build from one
`applyConditions` method. Narrow to cash sales in the first fortnight and the count,
the quantity, the revenue, the discount, the cost of goods and the gross profit all
narrow with it.

The alternative — a summary with its own WHERE clause — is how filtered rows end up
under unfiltered totals. It is the defect this whole design is organised against.

Two figures deliberately do **not** move with the filter, and say so on the page:

- **"You owe suppliers"** on Purchases, and **"Total owed to you"** on Customers.
  Narrowing to one month cannot reduce what the shop actually owes.
- **The opening balance** on an account statement. That is what the account held, not
  a subtotal of the rows a search box happens to match.

The rule is not "everything moves with the filter". It is "every figure says which it
is".

### 4. Junk narrows nothing

A hand-edited `?customer=abc` or `?page=-4` is not an error worth a 500 page. It is a
filter nobody meant. Ids, enums, dates, amounts and sort keys all go through
`app/Support/Filters/Filters.php` and become `null` when they do not parse.

Sort keys matter most: a sort key reaches an `ORDER BY`, so `Filters::parseSort` checks it
against a whitelist and can never be a string that came off the wire.

## Lines against receipts

The one genuinely hard call. A product is a property of a **line**; a tender, an
invoice discount and a tax charge are properties of a **receipt**.

A line keeps the price the customer saw, before any invoice discount and, under
tax-inclusive pricing, with the tax still in it. So every line figure in the reports is
that line's share of what its receipt earned (`total - tax`), split by value with
`SaleCalculator::lineEarnings`. The shares add up to the receipt exactly, so the by-product, by-category
and line tables tie to net sales on the Profit & Loss, pesewa for pesewa.

Filter the sales report to Coca-Cola and ask for revenue by day. Summing whole
receipts reports a day on which somebody bought one Coke and six thousand cedis of
rice as six thousand cedis of Coca-Cola.

So choosing a product or category switches every money figure on that report to line
money: that product's revenue net of tax and discounts, and that product's cost. The
by-day and by-customer tables get line-level variants with a **narrower shape**: no tax
column and no tax-inclusive total, only what the line earned and what it cost.

The payment-method table is not shown at all under such a filter. Money is handed
over for a whole receipt; there is no honest way to say how much of a cash payment
was for one product on it. Saying so beats showing a number that answers a different
question.

## Qualify every column in a subquery

Worth its own section, because it produces no error.

Inside a correlated subquery, an unqualified column binds to the **nearest** table that
has a column of that name. Write `id` where you meant the outer `sales.id`, and MySQL
reads it as the subquery's own `id`. The subquery stops correlating and returns the
same plausible number for every row. The old app learnt this the hard way, through its
query builder dropping the table name; the rule outlives the cause, because SQL itself
resolves names this way in MySQL too.

So the rule is: always qualify columns inside subqueries. `sales.id`, never `id`. Give
the inner table an alias, and name the outer table in full:

```php
'EXISTS (SELECT 1 FROM `sale_payments` `sp` WHERE `sp`.`sale_id` = `sales`.`id` AND ...)'
```

`SaleService::applyConditions` and `SaleService::outstandingSql` are written this way
throughout, as are the balance subqueries in `CustomerService` and `SupplierService`
and `TaxReturnService::standingAtEndSql`.

To check one: `$query->toSql()`, then look for an unqualified column name inside a
subquery. A test that asserts a per-row figure **differs between rows** catches it; one
that checks a single row does not.

## Reading is not writing

Filtering never changes stock, balances, receivables, payables or the accounts. It
never creates a transaction.

That is asserted rather than assumed. `tests/Services/SalesFiltersTest.php` snapshots
stock quantities and values, and counts sales, journal entries, journal lines and
stock ledger rows, runs a spread of filter combinations, and asserts nothing moved. A query that accidentally
wrote would look exactly like a query that did not, so it is checked rather than
trusted.

The stock ledger deserves a specific note: every row already carries the running
balance after that movement, as of when it was entered. Rows are listed newest
trading day first, and within a day in the order they were entered. A backdated
movement therefore sits on its trading date, but its balance is still the one
recorded when it was entered, not the balance on that date. Narrowing the view to one product
or one week shows fewer rows and **the same balances**. Nothing recomputes a position
from what is on screen.

## Related

- [Filters reference](../reference/filters.md) — every parameter.
- [Find anything](../how-to/find-anything.md) — using it.
- `AGENTS.md`: the same rules as instructions for anyone changing this code.
