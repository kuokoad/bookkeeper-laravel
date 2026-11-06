<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quotations;

use App\Domain\Errors\NotFoundError;
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
            'settings' => SettingsService::get(),
            'expired' => QuotationService::isExpired($quote, Clock::today()),
        ]);
    }
}
