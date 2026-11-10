# Inventory costing

How the shop knows what its stock is worth, and what a sale actually cost.

## The problem

A shop buys the same product at different prices. Twenty crates of Coke at GHS 12,
then thirty more at GHS 14 when the supplier puts the price up. A customer buys one.
What did it cost?

Get this wrong and every profit figure in the app is wrong, in a way that looks
entirely reasonable. Price the sale at today's cost and last month's profit changes
every time a supplier moves a price.

## Weighted average, snapshotted

Stock is valued at **weighted average cost**. Each product carries a running
`(quantity, value)` pair. Goods coming in add their real cost to both; goods going
out leave at the average of what is there.

```
20 crates at 12.00  →  qty 20   value 240.00   average 12.00
30 crates at 14.00  →  qty 50   value 660.00   average 13.20
sell 1              →  qty 49   value 646.80   COGS 13.20
```

The critical part: that 13.20 is **written onto the sale** as `cogs_minor` at the
moment it happens. Profit is `total − tax − cogs`, read from the document
(`SaleCalculator::saleGrossProfit`): the tax collected is owed to the state, not
earned. Editing the
product's cost price tomorrow cannot change what last month earned.

Everything lives in `app/Domain/Inventory/Costing.php`: `stockIn`, `stockOut`,
`stockOutAtCost`, `averageUnitCost` and `replay`, pure functions over a `StockState`.
No database, no dates. `tests/Domain/CostingTest.php` tests them, including a check
that runs 200 seeded random movement sequences through them.

`stockOutAtCost` is the exception that proves the rule. Returning goods to a
supplier must leave at the price *that supplier charged*, not at a blended average
that includes other deliveries.

## One gateway

Every stock change in the app goes through `InventoryService::recordStockMovement` in
`app/Services/InventoryService.php`. Sales, purchases, returns, adjustments, voids — all
of them.

There is deliberately **no screen or service that sets a product's quantity**. A
product form that could type over the stock figure would make the ledger unprovable:
you could no longer trace any figure back to an event that caused it.

## The ledger is the truth

Every movement appends a row to `stock_ledger`, carrying what moved, why, its cost,
and — this is the important part — the running `balance_qty_milli` and
`balance_value_minor` **after** that movement.

That makes the position as it stood after any movement a single row lookup, and it
makes the product's cached quantity provable: replay the chain from the first
movement and you must arrive at the same figure. It is the position as **entered**,
not as at a trading date: a backdated movement keeps the balance recorded when it was
entered, so the position as at a date needs a sum of the movements up to that date.

`php artisan nuna:preflight` does exactly that replay, among its other readiness
checks (the books balance, migrations, owners, demo data and more), and names each
product where the two parted company. The Inventory page runs the cheaper version of
the check — comparing
the cache against the ledger's *last recorded balance* — on every visit, because a
full replay reads the whole history of every product and gets slower for ever.

Nothing updates or deletes a ledger row. A mistake is corrected by appending a
reversing movement.

## Batches carry no money

Batches exist for **dates**, so a shop can sell the milk that expires first. A batch
row holds a quantity, an expiry date, a supplier and a reference. It holds **no cost
and no value**.

That is a deliberate constraint, and it is enforced by the schema having no column
for it. Value is weighted-average and pooled per product; splitting it across batches
would create a second, disagreeing answer to "what is this stock worth". So an
allocation in `app/Domain/Inventory/Batches.php` is a batch and a quantity only, and
nothing there can be multiplied by a price.

The honest consequence: **"the value of stock expiring within 7
days" is a figure this application cannot produce.** The expiry report shows
quantities, not money, and that is the correct answer rather than a limitation.

## FEFO, quietly

Stock is picked **first-expiry-first-out**. Expired batches are skipped in silence
whenever good stock covers the quantity — whoever is at the till, without a warning.

That silence is the design. A warning that fires when it need not is a warning people
learn to click past, and a till people route around is worse than no till at all. One
old crate at the back of the shelf must not interrupt a shop that still has fresh
stock.

Expired stock is only reported, and only taken with explicit permission, when there is
nothing else left. The `expiredFirst` option of `Batches::allocateFefo` inverts the order entirely and belongs to writing
expired goods off, which is a different act.

## What ties back to what

The invariant the test suite asserts: **stock value equals the Inventory account.**

Every movement that changes stock value posts a matching journal entry. The Inventory
page and the inventory report both compare the two and raise a red alert if they
disagree, because at that point one of them is lying and the shop needs to know which
before recording anything else.

## Related

- [Money and quantity](../reference/money-and-quantity.md) — `mulDiv` and `allocate`,
  which do the rounding here.
- [Fix a mistake](../how-to/fix-a-mistake.md) — stock adjustments in practice.
- `AGENTS.md`, Conventions: the double-entry rules these postings follow.
