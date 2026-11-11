<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quotations;

use App\Domain\Errors\NotFoundError;
use App\Domain\Sales\SaleCalculator;
use App\Http\Controllers\Controller;
use App\Services\QuotationService;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\View\View;

/**
 * The paper a contractor walks out with: the old (app)/quotations/[id]/print
 * page. It says what the price is, when it stops being promised, and that
 * nothing has been sold yet.
 */
final class PrintController extends Controller
{
    public function __invoke(int $id): View
    {
        try {
            $quote = QuotationService::getQuotation($id);
        } catch (NotFoundError) {
            abort(404);
        }

        return view('quotations.print', [
            'quote' => $quote,
            // As a receipt prints them: with tax-inclusive prices, the subtotal is the lines as printed and
            // the discount as typed, not the net-of-tax figures stored, which would not add up on paper.
            'totals' => SaleCalculator::documentTotals(
                (int) $quote['subtotalMinor'], (int) $quote['discountMinor'], (int) $quote['taxMinor'], (int) $quote['totalMinor'],
                (bool) $quote['taxInclusive'], array_map(fn (array $item): int => (int) $item['lineTotalMinor'], $quote['items']),
            ),
            'settings' => SettingsService::get(),
            'expired' => QuotationService::isExpired($quote, Clock::today()),
        ]);
    }
}
