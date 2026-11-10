# Money and quantity

The two number types. Read this before writing anything that touches a figure.

Money is a PHP `int` counting **pesewas** (1/100 of a cedi). Quantity is an `int`
counting **milli-units** (1/1000 of a unit). Rates are an `int` of **basis points**
(1/100 of a percent). None of them is ever a float, because 0.1 + 0.2 is not 0.3 and
a shop's books cannot be approximately right. In the database they are `BIGINT`
columns, never `DECIMAL`, `FLOAT` or `DOUBLE`.

PHP has no branded types, so the guard is different: every PHP file declares
`declare(strict_types=1);`. Without it PHP would quietly truncate a float passed to an
`int` parameter, so `Money::multiply(100, 1.5)` would return 100. With it, the call
fails loudly.

```php
use App\Domain\Money;
use App\Domain\Quantity;

$price = 1_250;                     // GHS 12.50
$count = Quantity::fromUnits(3);    // 3000, that is 3.000 units
```

| | Money | Quantity | Rate |
|---|---|---|---|
| Type | `int` | `int` | `int` |
| Scale | `Money::SCALE = 100` | `Quantity::SCALE = 1000` | `Rate::SCALE = 10_000` |
| Class | `App\Domain\Money` | `App\Domain\Quantity` | `App\Domain\Rate` |
| `1` means | one pesewa | one thousandth of a unit | one hundredth of a percent |

Every money result is kept inside ±(2^53 − 1), the old app's safe range, so both apps
refuse exactly the same amounts. Going past it throws `MoneyOverflowError`.

## Making one

| Method | Takes | Gives |
|---|---|---|
| `Money::of($n)` | a whole number of pesewas | pesewas, **throws** on a fraction or overflow |
| `Money::fromMajor($n)` | a count of cedis, as a number (may be a float) | pesewas, rounded through its two-decimal text, so `1.005` (stored as 1.00499…) gives 100, not 101. Never throws: typed input goes through `parse` |
| `Money::parse($s)` | user text: `"1250"`, `"1,250.50"`, `"GHS 20"`, `"(50.00)"` for negative | pesewas, **throws** on junk or more than 2 decimals. Any leading two or three letters are dropped as a currency code, so `"USD 20"` also reads as 20.00 |
| `Quantity::of($n)` | a whole number of milli-units | milli-units |
| `Quantity::fromUnits($n)` | a count of units, as a number (may be a float) | milli-units, rounded like `fromMajor` |
| `Quantity::parse($s)` | user text | milli-units, throws on junk or more than 3 decimals |
| `Quantity::parsePositive($s, $label)` | user text | milli-units, throws if not above zero |
| `Rate::parsePercent($s)` | `"12.5"`, no % sign | basis points, throws above 100% or past 2 decimals |

`Money::parse` **throws rather than guessing**. `"(-50)"` is a typo with two negatives,
not minus fifty, and guessing would put a wrong figure into the books. Filter boxes
are the exception and use the lenient `Filters::parseAmount` in
`app/Support/Filters/Filters.php`, which returns `null` on junk: a filter is a
question, not a posting.

## Arithmetic

Plain `+` and `-` on two ints cannot lose a pesewa, but they can pass the safe range
without a word. The helpers check the range, and anything that divides rounds
correctly.

| Money | Quantity | Does |
|---|---|---|
| `Money::add($a, $b)` | `Quantity::add($a, $b)` | sum of two |
| `Money::subtract($a, $b)` | `Quantity::subtract($a, $b)` | difference |
| `Money::negate($a)` | `Quantity::negate($a)` | sign flip |
| `Money::absolute($a)` | `Quantity::absolute($a)` | magnitude |
| `Money::sum($values)` | `Quantity::sum($values)` | total of a list |
| `Money::multiply($v, $factor)` | `Quantity::scale($v, $num, $den)` | scaling (an `int` factor only) |
| `Money::mulDiv($v, $num, $den)` | (`Quantity::scale`) | `v × num ÷ den`, rounded half away from zero, computed with `brick/math` `BigInteger` so it cannot overflow |
| `Money::percentOf($v, $basisPoints)` | none | a percentage, where `Money::BASIS_POINTS = 10_000` so 1250 is 12.5% |
| `Money::allocate($total, $weights)` | none | splits a total so the parts add back to it exactly |
| `Money::max` / `min` / `atLeastZero` | `Quantity::max` / `min` | bounds |

Arithmetic rounds **half away from zero** (2.5 → 3, −2.5 → −3), through
`Money::divRoundHalf`, matching the old app to the pesewa. `fromMajor` and
`fromUnits` round a float as described above, and `allocate` uses largest
remainders (below). The one other exception is
`Money::average`, which rounds halves towards +∞ (−2.5 → −2) because that is what the
old cashbook summary did.

`Money::allocate` is the one to reach for when splitting a discount or a tax across
lines. Dividing and rounding each part independently loses or invents a pesewa;
`allocate` gives the leftover pesewas to the largest remainders so the parts always
sum to the whole.

Where the old code relied on a JavaScript behaviour (`toFixed` ties, `Math.round`,
`trim` of Unicode spaces), use `App\Domain\JsCompat`, not PHP's built-ins.
`tests/Domain/JsParityTest.php` lists every known case.

## Comparison

The values are plain ints, so compare them with `===`, `<`, `>` and so on. There are
no comparison helpers to learn.

## Crossing the two

```php
Quantity::extendPrice(int $unitPrice, int $quantity): int      // qty × price
Quantity::derivedUnitPrice(int $lineTotal, int $quantity): int
```

`extendPrice` is how a line total is computed. It handles the scale difference (100
against 1000) so callers never do that arithmetic themselves.

## Showing one

Presentation lives in `app/View/Format.php`, not in the domain. Blade reaches it
through the `@money`, `@qty` and `@date` directives.

| Method | Gives |
|---|---|
| `Format::money($v)` | `"GHS 12.50"` |
| `Format::money($v, bare: true)` | `"12.50"`, for a column already headed GHS |
| `Format::moneyAccounting($v)` | `"GHS (12.50)"` for negatives |
| `Format::qty($v, $unit)` | `"3 pcs"` |
| `Money::format($v, $code)` | the domain-level formatter |
| `Money::toDecimalString($v)` | `"12.50"` |
| `Rate::format($bp)` | `"12.5"` |

**Nothing in a formatter calculates.** A total that first appears in a formatter is a
total nobody has tested.

## Dates

Business dates are `'YYYY-MM-DD'` strings, not `Carbon` objects, and are stored as
`DATE` in `Africa/Accra`. That is what makes a date range inclusive at both ends
without any timezone reasoning. `Clock::today()` and `Clock::dateOf()` in
`app/Support/Clock.php` give the shop's trading day, `Filters::isValidBusinessDate()`
checks one, and `App\Domain\BusinessDate` does day arithmetic. Timestamps are
`DATETIME(3)` in UTC.

Never filter trading on a timestamp. A range of 1 to 15 August must include a sale rung
up at 23:47 on the 15th.

## Related

- `AGENTS.md`, Conventions: the precision rules as instructions for anyone changing
  this code.
- [Inventory costing](../explanation/inventory-costing.md): where `mulDiv` and
  `allocate` earn their keep.
