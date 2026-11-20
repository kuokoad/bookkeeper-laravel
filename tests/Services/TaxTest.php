<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/tax-components-crud.test.ts and
| tax-setup.test.ts. (The seed's carry-forward cases are in CoreSeedTest.)
*/

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\ValidationError;
use App\Domain\Tax\TaxLine;
use App\Enums\TaxBasis;
use App\Services\SettingsService;
use App\Services\TaxService;
use Database\Seeders\CoreSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;
use Tests\Support\ValidRows;

beforeEach(function () {
    $this->actor = Shop::owner();
});

function chargeTax(): void
{
    DB::table('business_settings')->where('id', 1)->update(['tax_enabled' => true]);
}

function taxId(string $code): int
{
    return (int) DB::table('tax_components')->where('code', $code)->value('id');
}

function auditFor(int $id): array
{
    return DB::table('audit_logs')->where('entity_type', 'tax_component')->where('entity_id', (string) $id)->orderBy('id')->pluck('summary')->all();
}

function levy(array $overrides = []): array
{
    return array_merge([
        'code' => 'COVID', 'name' => 'COVID-19 Health Recovery Levy', 'rateBp' => 100, 'basis' => TaxBasis::Net,
        'isRecoverable' => false, 'glAccountId' => Shop::accountId(ChartOfAccounts::NHIL_PAYABLE), 'sortOrder' => 15,
    ], $overrides);
}

function vat(int $rateBp = 1_600, TaxBasis $basis = TaxBasis::Net): array
{
    return ['code' => 'VAT', 'name' => 'VAT', 'rateBp' => $rateBp, 'basis' => $basis, 'isRecoverable' => true, 'glAccountId' => Shop::accountId(ChartOfAccounts::TAX_PAYABLE), 'sortOrder' => 30];
}

function codesAndAmountsOf(array $lines): array
{
    return array_map(fn (TaxLine $l): array => [$l->component->code, $l->amount], $lines);
}

describe('a shop setting up for the first time', function () {
    it('gets the three Ghanaian taxes, charging nothing until it says it is registered', function () {
        expect(TaxService::profile()['components'])->toBe([])
            ->and(TaxService::apply(TaxService::profile(), 10_000)->total)->toBe(0);
    });

    it('charges all three once switched on, each in its own account, all reclaimable', function () {
        chargeTax();
        $profile = TaxService::profile();
        expect(codesAndAmountsOf(TaxService::apply($profile, 10_000)->lines))->toBe([['NHIL', 250], ['GETFUND', 250], ['VAT', 1_500]])
            ->and(array_unique(array_values($profile['accountIdByCode'])))->toHaveCount(3)
            ->and(array_map(fn ($c) => $c->isRecoverable, $profile['components']))->toBe([true, true, true]);
    });

    it('changes nothing when the seed runs again, so an upgrade cannot reset adjusted rates', function () {
        TaxService::update(taxId('VAT'), vat(1_600), $this->actor);
        (new CoreSeeder)->run();
        expect(DB::table('tax_components')->where('code', 'VAT')->value('rate_bp'))->toBe(1_600);
    });
});

describe('the budget adds a levy', function () {
    it('takes effect on the next sale, without a new version of the software', function () {
        chargeTax();
        expect(TaxService::profile()['totalRateBp'])->toBe(2_000);
        TaxService::create(levy(), $this->actor);

        $profile = TaxService::profile();
        expect($profile['totalRateBp'])->toBe(2_100)
            ->and(array_map(fn ($c) => $c->code, $profile['components']))->toBe(['NHIL', 'COVID', 'GETFUND', 'VAT'])
            ->and(codesAndAmountsOf(TaxService::apply($profile, 10_000)->lines))->toBe([['NHIL', 250], ['COVID', 100], ['GETFUND', 250], ['VAT', 1_500]]);
    });

    it('says in the audit log what was added and at what rate', function () {
        $id = TaxService::create(levy(), $this->actor);
        expect(auditFor($id))->toBe(['Added COVID-19 Health Recovery Levy at 1%']);
    });
});

describe('the budget moves a rate', function () {
    it('applies from now on', function () {
        chargeTax();
        TaxService::update(taxId('VAT'), vat(1_600), $this->actor);
        expect(TaxService::profile()['totalRateBp'])->toBe(2_100)
            ->and(TaxService::apply(TaxService::profile(), 10_000)->total)->toBe(2_100);
    });

    it('records the move itself, not just that something was saved', function () {
        TaxService::update(taxId('VAT'), vat(1_600, TaxBasis::NetPlusLevies), $this->actor);
        expect(auditFor(taxId('VAT')))->toBe(['VAT: rate 15% to 16%, now charged on the value plus the levies before it']);
    });

    it('raises the all-in rate to 20.75% under the GRA computation', function () {
        chargeTax();
        TaxService::update(taxId('VAT'), vat(1_500, TaxBasis::NetPlusLevies), $this->actor);
        expect(TaxService::profile()['totalRateBp'])->toBe(2_075)
            ->and(TaxService::apply(TaxService::profile(), 10_000)->amounts())->toBe([250, 250, 1_575]);
    });
});

describe('edits that would make the books unfileable', function () {
    it('refuses to hold tax anywhere but a liability account', function () {
        TaxService::create(levy(['glAccountId' => Shop::accountId(ChartOfAccounts::SALES_REVENUE)]), $this->actor);
    })->throws(ValidationError::class, 'liability account');

    it('refuses to hold tax in Accounts Payable, which the balance sheet reads as owed to suppliers', function () {
        TaxService::create(levy(['glAccountId' => Shop::accountId(ChartOfAccounts::ACCOUNTS_PAYABLE)]), $this->actor);
    })->throws(ValidationError::class, 'owed to suppliers');

    it('refuses a duplicate code, including one typed in lowercase, or renamed onto another', function () {
        expect(fn () => TaxService::create(levy(['code' => 'VAT']), $this->actor))->toThrow(ConflictError::class, 'already exists')
            ->and(fn () => TaxService::create(levy(['code' => 'vat']), $this->actor))->toThrow(ConflictError::class, 'already exists')
            ->and(fn () => TaxService::update(taxId('NHIL'), ['code' => 'VAT', 'name' => 'NHIL', 'rateBp' => 250, 'basis' => TaxBasis::Net, 'isRecoverable' => false, 'glAccountId' => Shop::accountId(ChartOfAccounts::NHIL_PAYABLE), 'sortOrder' => 10], $this->actor))
            ->toThrow(ConflictError::class, 'already exists');
    });

    it('refuses a rate nobody could have meant, a code that is not a code, and a nameless tax', function () {
        expect(fn () => TaxService::create(levy(['rateBp' => -1]), $this->actor))->toThrow(ValidationError::class, 'between 0% and 1000%')
            ->and(fn () => TaxService::create(levy(['rateBp' => 100_001]), $this->actor))->toThrow(ValidationError::class, 'between 0% and 1000%')
            ->and(fn () => TaxService::create(levy(['code' => 'a levy!']), $this->actor))->toThrow(ValidationError::class, 'letters, digits or underscores')
            ->and(fn () => TaxService::create(levy(['code' => '   ']), $this->actor))->toThrow(ValidationError::class, 'letters, digits or underscores')
            ->and(fn () => TaxService::create(levy(['name' => '  ']), $this->actor))->toThrow(ValidationError::class, 'Enter a name');
    });
});

describe('switching a tax off', function () {
    it('stops it appearing on new sales, and leaves the row where it is', function () {
        chargeTax();
        TaxService::setActive(taxId('GETFUND'), false, $this->actor);
        expect(array_map(fn ($c) => $c->code, TaxService::profile()['components']))->toBe(['NHIL', 'VAT'])
            ->and(TaxService::profile()['totalRateBp'])->toBe(1_750)
            ->and(TaxService::list()->pluck('code')->all())->toContain('GETFUND');
    });

    it('is recorded, and says nothing when nothing changed', function () {
        TaxService::setActive(taxId('GETFUND'), true, $this->actor);
        expect(auditFor(taxId('GETFUND')))->toBe([]);
        TaxService::setActive(taxId('GETFUND'), false, $this->actor);
        expect(auditFor(taxId('GETFUND')))->toBe(['GETFund switched off']);
    });
});

it('has charged nothing before any sale has used it', function () {
    expect(TaxService::usage(taxId('VAT')))->toBe(0);
});

describe('the rate the till still prices from', function () {
    it('is set at install, and follows a rate change, a switch-off and compounding', function () {
        expect(SettingsService::get()->tax_rate_bp)->toBe(2_000);
        TaxService::update(taxId('VAT'), vat(1_600), $this->actor);
        expect(SettingsService::get()->tax_rate_bp)->toBe(2_100);
        TaxService::update(taxId('VAT'), vat(1_500, TaxBasis::NetPlusLevies), $this->actor);
        expect(SettingsService::get()->tax_rate_bp)->toBe(2_075);
        TaxService::setActive(taxId('GETFUND'), false, $this->actor);
        expect(SettingsService::get()->tax_rate_bp)->toBe(1_788); // 2.5% NHIL, then 15% VAT on 102.5: 17.875% → 1788
    });

    it('names the tax on the receipt when there is only one', function () {
        expect(SettingsService::get()->tax_label)->toBe('Tax');
        TaxService::setActive(taxId('NHIL'), false, $this->actor);
        TaxService::setActive(taxId('GETFUND'), false, $this->actor);
        expect(SettingsService::get()->tax_label)->toBe('VAT');
    });
});

describe('what the settings screen shows', function () {
    it('reports the all-in rate even while tax is switched off', function () {
        expect(TaxService::profile()['totalRateBp'])->toBe(0)
            ->and(TaxService::allInRateBp())->toBe(2_000);
    });

    it('offers only accounts a tax may actually be held in', function () {
        $codes = TaxService::holdingAccounts()->pluck('code')->all();
        expect($codes)->toContain(ChartOfAccounts::TAX_PAYABLE, ChartOfAccounts::NHIL_PAYABLE, ChartOfAccounts::GETFUND_PAYABLE)
            ->and($codes)->not->toContain(ChartOfAccounts::SALES_REVENUE)
            ->and($codes)->not->toContain(ChartOfAccounts::ACCOUNTS_PAYABLE)
            ->and($codes)->not->toContain(ChartOfAccounts::CASH)
            ->and($codes)->not->toContain(ChartOfAccounts::INVENTORY);
    });
});

it('snapshots a sale and a purchase tax line as charged, skipping zero lines', function () {
    chargeTax();
    $profile = TaxService::profile();
    $lines = TaxService::apply($profile, 101)->lines; // NHIL 3, GETFUND 3, VAT 15
    $zero = [new TaxLine($lines[0]->component, 0)];
    $at = now();

    $rows = ValidRows::create();
    DB::table('sale_taxes')->delete();
    DB::table('purchase_taxes')->delete();
    TaxService::writeSaleTaxes($rows->ids['sales'], [...$lines, ...$zero], $profile['componentIdByCode'], $at);
    TaxService::writePurchaseTaxes($rows->ids['purchases'], $lines, $profile['componentIdByCode'], $at);

    expect(TaxService::readSaleTaxes($rows->ids['sales'])->where('code', '<>', 'VAT')->pluck('amount_minor', 'code')->all())->toBe(['NHIL' => 3, 'GETFUND' => 3])
        ->and(TaxService::readPurchaseTaxes($rows->ids['purchases'])->pluck('is_recoverable')->unique()->all())->toBe([1])
        ->and(TaxService::recoverableTotal($lines))->toBe(21)
        ->and(TaxService::nonRecoverableTotal($lines))->toBe(0)
        ->and(TaxService::accountFor(null, 'GETFUND'))->toBe(Shop::accountId(ChartOfAccounts::GETFUND_PAYABLE))
        ->and(TaxService::accountFor(null, 'GONE'))->toBe(Shop::accountId(ChartOfAccounts::TAX_PAYABLE));
});
