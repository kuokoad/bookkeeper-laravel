<?php

declare(strict_types=1);

/* The reconciliation screen: the old (app)/reconciliation page, count form and reconciliation.actions. */

use App\Livewire\Reconciliation\Index;
use App\Models\User;
use App\Services\Actor;
use App\Services\PaymentAccountService;
use App\Services\ReconciliationService;
use App\Support\Clock;
use App\View\Format;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

beforeEach(function () {
    $this->ownerId = owner();
    $this->actor = new Actor($this->ownerId, 'kwame');
    $this->today = Clock::today();
    $this->cash = Shop::paymentAccount('CASH');
});

function countAs(int $userId): mixed
{
    return Livewire::actingAs(User::query()->findOrFail($userId))->test(Index::class);
}

it('renders over the demo shop with the overview figures', function () {
    (new DemoSeeder)->seedDemo();
    $overview = ReconciliationService::getReconciliationOverview();
    signedIn($this->ownerId)->get('/reconciliation')->assertOk()
        ->assertSee('Check what the books say against what is actually there.')
        ->assertSee($overview[0]['accountName'])
        ->assertSee(Format::money($overview[0]['currentBalance'], bare: true));
});

it('refuses somebody without the right', function () {
    signedIn(staff(['accounts' => viewOnly()]))->get('/reconciliation')->assertRedirect('/no-access?area=reconciliation');
    $viewer = staff(['reconciliation' => viewOnly()], 'kofi');
    signedIn($viewer)->get('/reconciliation')->assertOk()->assertSee('You do not have permission to record a count.');
    countAs($viewer)->call('reconcile')->assertForbidden();
    countAs($viewer)->call('void', 1)->assertForbidden();
});

it('previews the difference and saves the service figure', function () {
    Shop::capital($this->actor, $this->cash, 10_000, $this->today);
    $page = countAs($this->ownerId)->set('paymentAccountId', (string) $this->cash)
        ->assertSee('GHS 100.00')
        ->set('actual', '95')->assertSee('Less than the books say')->assertSee('GHS -5.00')->assertSee('Save count and difference');

    $page->call('reconcile')->assertHasErrors('form')
        ->assertSee('There is a difference between the books and the count. Explain what you think happened before saving.');
    $page->set('actual', '95')->set('explanation', 'Wrong change')->call('reconcile')->assertHasNoErrors()
        ->assertSee('was posted to Cash Over / Short, so the books now match what you counted.');
    expect(DB::table('reconciliations')->value('difference_minor'))->toBe(-500)
        ->and(PaymentAccountService::getPaymentAccountBalance($this->cash))->toBe(9_500);
});

it('asks for an amount and refuses a negative count', function () {
    countAs($this->ownerId)->call('reconcile')->assertHasErrors(['actual' => 'Enter the amount you counted.'])
        ->set('actual', '-5')->call('reconcile')->assertHasErrors('actual');
    expect(DB::table('reconciliations')->count())->toBe(0);
});

it('voids a count with a reason', function () {
    $r = ReconciliationService::createReconciliation(['paymentAccountId' => $this->cash, 'businessDate' => $this->today, 'actual' => 0, 'adjust' => false], $this->actor);
    countAs($this->ownerId)->call('void', $r['reconciliationId'])->assertHasErrors(['reason.'.$r['reconciliationId'] => 'Give a reason for voiding this count.'])
        ->set('reasons.'.$r['reconciliationId'], 'Counted the wrong till')->call('void', $r['reconciliationId'])->assertRedirect('/reconciliation?voided=1');
    expect(DB::table('reconciliations')->value('status'))->toBe('VOIDED');
});
