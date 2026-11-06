<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Errors\NotFoundError;
use App\Http\Controllers\Controller;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\SettingsService;
use App\Support\Clock;
use Illuminate\View\View;

/**
 * Everything one customer still owes, as at today: the old
 * (app)/customers/[id]/statement page. The total is getCustomerBalance, the
 * ledger's figure, not a sum of the rows; if the two disagree the statement
 * says so rather than showing the friendlier number.
 */
final class StatementController extends Controller
{
    public function __invoke(int $id): View
    {
        try {
            $customer = CustomerService::getCustomer($id);
        } catch (NotFoundError) {
            abort(404);
        }
        $today = Clock::today();

        return view('customers.statement', [
            'customer' => $customer,
            'settings' => SettingsService::get(),
            'today' => $today,
            ...CustomerPaymentService::getCustomerStatement($id, $today),
        ]);
    }
}
