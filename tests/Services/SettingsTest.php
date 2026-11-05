<?php

declare(strict_types=1);

/*
| Ported from the old tests/services/settings.test.ts.
*/

use App\Domain\Errors\ValidationError;
use App\Enums\BusinessType;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
});

function save(array $changes): void
{
    SettingsService::update(Shop::settingsInput($changes), test()->actor);
}

describe('the shop details', function () {
    it('saves what was entered', function () {
        save(['businessName' => 'Adom Provisions', 'phone' => '024 000 0000']);
        expect(SettingsService::get()->business_name)->toBe('Adom Provisions')
            ->and(SettingsService::get()->phone)->toBe('024 000 0000');
    });

    it('updates the tagline, or removes it entirely', function () {
        save(['tagline' => 'Provisions since 2016']);
        expect(SettingsService::get()->tagline)->toBe('Provisions since 2016');
        save(['tagline' => null]);
        expect(SettingsService::get()->tagline)->toBeNull();
    });

    it('records a tagline change in the audit log', function () {
        save(['tagline' => 'Provisions since 2016']);
        expect(Shop::settingsAuditSummaries()[0])->toMatch('/Tagline: .* → Provisions since 2016/');
    });

    it('stores a cleared field as nothing, not as an empty string', function () {
        save(['address' => 'Madina Market']);
        save(['address' => null]);
        expect(SettingsService::get()->address)->toBeNull();
    });
});

describe('changing the currency', function () {
    it('is allowed before any trading has happened', function () {
        expect(SettingsService::hasPostedTransactions())->toBeFalse();
        save(['currencyCode' => 'NGN', 'currencySymbol' => '₦']);
        expect(SettingsService::get()->currency_code)->toBe('NGN')
            ->and(SettingsService::get()->currency_symbol)->toBe('₦');
    });

    it('is REFUSED once there are transactions, naming the currency the books are in', function () {
        Shop::postOpeningCapital();
        expect(fn () => save(['currencyCode' => 'USD']))->toThrow(ValidationError::class, 'cannot be changed from GHS');
        expect(SettingsService::get()->currency_code)->toBe('GHS');
    });

    it('rolls back the WHOLE change, so nothing is half-saved, and records nothing', function () {
        Shop::postOpeningCapital();
        expect(fn () => save(['businessName' => 'Renamed Shop', 'currencyCode' => 'USD']))->toThrow(ValidationError::class);
        expect(SettingsService::get()->business_name)->not->toBe('Renamed Shop')
            ->and(Shop::settingsAuditSummaries())->toBe([]);
    });

    it('still allows the SYMBOL to change after trading, and never blocks a save that leaves the currency alone', function () {
        Shop::postOpeningCapital();
        save(['currencySymbol' => 'GH₵', 'businessName' => 'Still Fine']);
        expect(SettingsService::get()->currency_symbol)->toBe('GH₵')
            ->and(SettingsService::get()->currency_code)->toBe('GHS')
            ->and(SettingsService::get()->business_name)->toBe('Still Fine');
    });
});

describe('tax', function () {
    it('switches tax on and off without touching what is charged', function () {
        $before = SettingsService::get()->tax_rate_bp;
        save(['taxEnabled' => true]);
        expect(SettingsService::get()->tax_rate_bp)->toBe($before);
        save(['taxEnabled' => false]);
        expect(SettingsService::get()->tax_enabled)->toBe(0)
            ->and(SettingsService::get()->tax_rate_bp)->toBe($before);
    });

    it('records whether prices include tax', function () {
        save(['taxInclusive' => true]);
        expect(SettingsService::get()->tax_inclusive)->toBe(1);
    });
});

describe('the audit trail', function () {
    it('records what changed, from what to what, and who', function () {
        save(['businessName' => 'Adom Provisions']);
        $entries = DB::table('audit_logs')->where('action', 'SETTINGS_CHANGE')->get();
        expect($entries)->toHaveCount(1)
            ->and($entries[0]->summary)->toBe('Shop name: My Shop → Adom Provisions')
            ->and($entries[0]->username)->toBe('kwame');
    });

    it('spells out a policy change in words, and names the month', function () {
        save(['allowNegativeStock' => true]);
        save(['financialYearStartMonth' => 4]);
        save(['lowStockThresholdMilli' => 2_500]);
        expect(Shop::settingsAuditSummaries())->toBe([
            'Low stock level: 5 → 2.5',
            'Financial year starts: January → April',
            'Allow negative stock: off → on',
        ]);
    });

    it('writes NOTHING when a save changes nothing', function () {
        save([]);
        expect(Shop::settingsAuditSummaries())->toBe([]);
    });
});

describe('the shop logo', function () {
    beforeEach(function () {
        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    });

    it('stores the image, reports what it is, and gives back exactly the bytes', function () {
        SettingsService::setLogo($this->png, 'image/png', 1, 1, $this->actor);
        $summary = SettingsService::logoSummary();
        expect([$summary['hasLogo'], $summary['mime'], $summary['bytes']])->toBe([true, 'image/png', strlen($this->png)])
            ->and($summary['updatedAt'])->not->toBeNull()
            ->and(SettingsService::logo()['data'])->toBe($this->png);
    });

    it('lives in the database, so a backup carries it', function () {
        SettingsService::setLogo($this->png, 'image/png', 1, 1, $this->actor);
        expect(DB::selectOne('SELECT logo_data FROM business_settings WHERE id = 1')->logo_data)->toBe($this->png);
    });

    it('reports no logo before one is set', function () {
        expect(SettingsService::logoSummary()['hasLogo'])->toBeFalse()
            ->and(SettingsService::logo())->toBeNull();
    });

    it('removes it, leaving nothing behind, and records both in the audit log', function () {
        SettingsService::setLogo($this->png, 'image/png', 1, 1, $this->actor);
        SettingsService::clearLogo($this->actor);
        expect(SettingsService::logo())->toBeNull()
            ->and(SettingsService::logoSummary())->toMatchArray(['hasLogo' => false, 'mime' => null, 'bytes' => 0])
            ->and(Shop::settingsAuditSummaries())->toBe(['Logo removed', 'Logo updated (image/png, 1x1, 70 bytes)']);
    });

    it('writes nothing when removing a logo that was never there', function () {
        SettingsService::clearLogo($this->actor);
        expect(Shop::settingsAuditSummaries())->toBe([]);
    });

    it('keeps only the newest when replaced', function () {
        SettingsService::setLogo($this->png, 'image/png', 1, 1, $this->actor);
        SettingsService::setLogo($this->png."\0\0\0", 'image/webp', 2, 2, $this->actor);
        expect(SettingsService::logoSummary())->toMatchArray(['mime' => 'image/webp', 'bytes' => strlen($this->png) + 3]);
    });

    it('holds a logo near the 1 MB cap', function () {
        $big = random_bytes(1_000_000);
        SettingsService::setLogo($big, 'image/jpeg', 2000, 2000, $this->actor);
        expect(SettingsService::logo()['data'])->toBe($big);
    });
});

describe('the expiry settings', function () {
    it('round-trip through the form input, leaving the other stock settings alone', function () {
        save(['expiryWarningDays' => 7, 'expiryBlocksSales' => false]);
        $s = SettingsService::get();
        expect([$s->expiry_warning_days, $s->expiry_blocks_sales, $s->low_stock_threshold_milli, $s->allow_negative_stock])->toBe([7, 0, 5_000, 0]);
    });

    it('refuses a warning period that is not a period', function () {
        save(['expiryWarningDays' => -1]);
    })->throws(ValidationError::class);
});

describe('what kind of business this is', function () {
    it('starts as general retail, with expiry on and quotations off', function () {
        $s = SettingsService::get();
        expect($s->business_type)->toBe('general_retail')
            ->and(SettingsService::features($s))->toBe(['expiry_batches' => true, 'quotations' => false]);
    });

    it('stamps the new type onto the switches, whatever the form submitted', function () {
        save(['businessType' => BusinessType::BuildingMaterials, 'features' => ['expiry_batches' => true, 'quotations' => true]]);
        expect(SettingsService::get()->feature_expiry_batches)->toBe(0);
    });

    it('turns everything back on for "something else"', function () {
        save(['businessType' => BusinessType::BuildingMaterials]);
        save(['businessType' => BusinessType::Other]);
        expect(SettingsService::get()->feature_expiry_batches)->toBe(1);
    });

    it('leaves a switch where the owner put it while the type is unchanged', function () {
        save(['businessType' => BusinessType::BuildingMaterials]);
        save(['businessType' => BusinessType::BuildingMaterials, 'features' => ['expiry_batches' => true, 'quotations' => true]]);
        expect(SettingsService::get()->feature_expiry_batches)->toBe(1);
    });

    it('records the change by name, in one row, and nothing when the type did not change', function () {
        save(['businessType' => BusinessType::GeneralRetail]);
        expect(Shop::settingsAuditSummaries())->toBe([]);

        save(['businessType' => BusinessType::BuildingMaterials]);
        $summaries = Shop::settingsAuditSummaries();
        expect($summaries)->toHaveCount(1)
            ->and($summaries[0])->toContain('Business type: General retail → Building materials')
            ->and($summaries[0])->toContain('Expiry dates and batches: on → off')
            ->and($summaries[0])->toContain('Quotations: off → on')
            ->and($summaries[0])->not->toContain('building_materials');
    });

    it('is refused by the database itself, not merely by the form', function () {
        DB::update("UPDATE business_settings SET business_type = 'pharmacy' WHERE id = 1");
    })->throws(QueryException::class, 'ck_business_settings_business_type');
});
