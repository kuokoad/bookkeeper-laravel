<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\BusinessDate;
use App\Domain\Errors\NotFoundError;
use App\Domain\Money;
use App\Domain\Sales\SaleCalculator;
use App\Http\Controllers\Controller;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The papers a sale prints: the old (app)/sales/[id]/receipt and invoice
 * pages. Same sale, same figures; nothing here recalculates anything beyond
 * presenting the stored sale as it was transacted (SaleCalculator::documentTotals).
 */
final class SaleDocumentController extends Controller
{
    private static function sale(int $id): array
    {
        try {
            return SaleService::getSale($id);
        } catch (NotFoundError) {
            abort(404);
        }
    }

    private static function totals(array $sale): array
    {
        return SaleCalculator::documentTotals(
            (int) $sale['subtotalMinor'], (int) $sale['discountMinor'], (int) $sale['taxMinor'], (int) $sale['totalMinor'],
            (bool) $sale['taxInclusive'], array_map(fn (array $item): int => (int) $item['lineTotalMinor'], $sale['items']),
        );
    }

    public function receipt(Request $request, int $id): View
    {
        $sale = self::sale($id);
        $tendered = Money::sum(array_map(fn (array $t): int => (int) $t['amountMinor'], $sale['tenders']));

        return view('sales.receipt', [
            'sale' => $sale,
            'settings' => SettingsService::get(),
            'totals' => self::totals($sale),
            'change' => Money::atLeastZero(Money::subtract($tendered, (int) $sale['totalMinor'])),
            // `?print=1` comes from "Print receipt" at the till.
            'autoPrint' => $request->query('print') === '1',
        ]);
    }

    public function invoice(int $id): View
    {
        $sale = self::sale($id);
        $today = Clock::today();
        $outstanding = (int) $sale['outstandingMinor'];

        return view('sales.invoice', [
            'sale' => $sale,
            'settings' => SettingsService::get(),
            'totals' => self::totals($sale),
            'overdueBy' => $sale['dueDate'] ? BusinessDate::daysOverdue($sale['dueDate'], $today) : 0,
            'settled' => $outstanding <= 0,
            // Returns credited to the account are not money received.
            'alreadyPaid' => Money::subtract(Money::subtract((int) $sale['totalMinor'], (int) $sale['creditedMinor']), $outstanding),
            'amountDue' => Money::atLeastZero($outstanding),
            'inCredit' => Money::atLeastZero(Money::negate($outstanding)),
        ]);
    }
}
