<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\BusinessDate;
use App\Domain\Errors\NotFoundError;
use App\Domain\Sales\SaleCalculator;
use App\Enums\PaymentAccountKind;
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

    /**
     * The receipt's payment lines. Change comes off the cash, which is applied
     * last, so the last cash payment plus the change is what the customer
     * handed over: "Cash received 100.00" then "Change 30.00", not a "Paid by
     * Cash 70.00" the customer never counted out.
     *
     * @return list<array{label: string, amount: int}>
     */
    private static function paidLines(array $sale): array
    {
        $change = (int) $sale['changeMinor'];
        $lastCash = null;
        foreach ($sale['tenders'] as $index => $tender) {
            if ($tender['accountKind'] === PaymentAccountKind::Cash->value) {
                $lastCash = $index;
            }
        }

        return array_map(fn (int $index, array $tender): array => $index === $lastCash && $change > 0
            ? ['label' => "{$tender['accountName']} received", 'amount' => (int) $tender['amountMinor'] + $change]
            : ['label' => "Paid by {$tender['accountName']}", 'amount' => (int) $tender['amountMinor']],
            array_keys($sale['tenders']), $sale['tenders']);
    }

    public function receipt(Request $request, int $id): View
    {
        $sale = self::sale($id);

        return view('sales.receipt', [
            'sale' => $sale,
            'settings' => SettingsService::get(),
            'totals' => self::totals($sale),
            'change' => (int) $sale['changeMinor'],
            'paid' => self::paidLines($sale),
            // `?print=1` comes from "Print receipt" at the till.
            'autoPrint' => $request->query('print') === '1',
        ]);
    }

    public function invoice(int $id): View
    {
        $sale = self::sale($id);
        $payments = SaleService::documentPayments($sale);

        return view('sales.invoice', [
            'sale' => $sale,
            'settings' => SettingsService::get(),
            'totals' => self::totals($sale),
            'overdueBy' => $sale['dueDate'] ? BusinessDate::daysOverdue($sale['dueDate'], Clock::today()) : 0,
            'settled' => $payments['settled'],
            'alreadyPaid' => $payments['alreadyPaid'],
            'amountDue' => $payments['amountDue'],
            'inCredit' => $payments['inCredit'],
        ]);
    }
}
