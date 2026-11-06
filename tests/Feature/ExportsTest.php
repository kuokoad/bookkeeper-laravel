<?php

declare(strict_types=1);

/*
| The CSV download routes (the old api/exports/[list] and api/reports/[report]):
| who may download what, the answers when not, the per-person throttle, and
| that the file is the filtered rows. The file contents are tested in
| tests/Services/CsvExportsTest.
*/

use App\Services\Actor;
use App\Support\HttpThrottle;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $actor = new Actor($this->ownerId, 'kwame');
    $cash = Shop::paymentAccount('CASH');
    $rice = Shop::product($actor, 'Rice 5kg', 4_000, 6_000);
    Shop::openingStock($actor, $rice, 10_000, 40_000, '2026-08-01');
    Shop::sell($actor, [[$rice, 2_000]], [['paymentAccountId' => $cash, 'amount' => 12_000]], ['businessDate' => '2026-08-10']);
    Shop::sell($actor, [[$rice, 1_000]], [['paymentAccountId' => $cash, 'amount' => 6_000]], ['businessDate' => '2026-08-20']);
});

describe('list exports', function () {
    it('names an unknown list before asking who is asking', function () {
        $this->get('/export/list/payroll')->assertNotFound()->assertSeeText('Unknown export "payroll".');
    });

    it('asks somebody not signed in to sign in, with a 401 rather than a redirect', function () {
        $this->get('/export/list/sales')->assertUnauthorized()->assertSeeText('Please sign in.');
    });

    it('refuses a list from a module the person cannot see', function () {
        signedIn(staff(['products' => viewOnly()]))->get('/export/list/sales')->assertForbidden()->assertSeeText('You do not have permission to export this.');
    });

    it('sends exactly the filtered rows as a CSV that is never cached', function () {
        $response = signedIn(staff(['sales' => viewOnly()]))->get('/export/list/sales?period=custom&from=2026-08-15&to=2026-08-31');
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="bookkeeper_sales_2026-08-15_2026-08-31.csv"');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        $lines = array_values(array_filter(explode("\r\n", $response->getContent())));
        expect($lines)->toHaveCount(2)
            ->and($lines[1])->toContain('2026-08-20')
            ->and($response->getContent())->not->toContain('2026-08-10');
    });

    it('answers 404 for an account that does not exist', function () {
        signedIn($this->ownerId)->get('/export/list/account?id=99999')->assertNotFound()->assertSeeText('Not found.');
    });
});

describe('report exports', function () {
    it('asks for sign-in, then the reports right, then a known report', function () {
        $this->get('/export/report/profit-and-loss')->assertUnauthorized()->assertSeeText('Please sign in.');
        signedIn(staff(['sales' => viewOnly()]))->get('/export/report/profit-and-loss')->assertForbidden()->assertSeeText('You do not have permission to export reports.');
        signedIn($this->ownerId)->get('/export/report/horoscope')->assertNotFound()->assertSeeText('Unknown report "horoscope".');
    });

    it('sends the report for the period asked', function () {
        signedIn(staff(['reports' => viewOnly()], 'kofi'))->get('/export/report/profit-and-loss?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="bookkeeper_profit-and-loss_2026-08-01_2026-08-31.csv"');
    });
});

it('throttles one person after 30 downloads a minute, lists and reports counted together', function () {
    $session = signedIn($this->ownerId);
    foreach (range(1, HttpThrottle::EXPORT['limit'] / 2) as $_) {
        $session->get('/export/list/sales')->assertOk();
        $session->get('/export/report/sales')->assertOk();
    }
    $refused = $session->get('/export/list/sales');
    $refused->assertStatus(429)->assertHeader('Retry-After');
    expect($refused->getContent())->toStartWith('That is more requests than this can serve at once.');

    // Somebody else at another till is not slowed by it.
    signedIn(staff(['sales' => viewOnly()]))->get('/export/list/sales')->assertOk();
});
