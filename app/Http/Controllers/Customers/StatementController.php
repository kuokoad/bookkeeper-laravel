<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\BusinessDate;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Http\Controllers\Controller;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everything one customer still owes, as at today or a chosen earlier day
 * (?asAt=, D33): the old (app)/customers/[id]/statement page, which printed
 * only as at today. A date that is not a real day, or is after today, falls
 * back to today, and the picker shows the date actually used. The total is
 * getCustomerBalance, the ledger's figure, not a sum of the rows; if the two
 * disagree the statement says so rather than showing the friendlier number.
 */
final class StatementController extends Controller
{
    public function __invoke(Request $request, int $id): View
    {
        try {
            $customer = CustomerService::getCustomer($id);
        } catch (NotFoundError) {
            abort(404);
        }
        $today = Clock::today();
        $asAt = self::asAt($request->query('asAt'), $today);

        return view('customers.statement', [
            'customer' => $customer,
            'settings' => SettingsService::get(),
            'today' => $today,
            'asAt' => $asAt,
            // Today keeps the statement exactly as it was before D33.
            ...CustomerPaymentService::getCustomerStatement($id, $asAt === $today ? null : $asAt),
        ]);
    }

    private static function asAt(mixed $raw, string $today): string
    {
        if (! is_string($raw) || $raw === '') {
            return $today;
        }
        try {
            BusinessDate::assert($raw);
        } catch (ValidationError) {
            return $today;
        }

        return $raw > $today ? $today : $raw;
    }
}
