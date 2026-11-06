<?php

declare(strict_types=1);

/*
| The settings screen, logo and taxes: the old settings page, settings and
| tax actions. Figures are what a shop owner would check on the row.
*/

use App\Auth\Permissions;
use App\Livewire\Settings\Index;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TaxService;
use App\Support\LogoImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Shop;

function settingsAs(int $userId): mixed
{
    return Livewire::actingAs(User::query()->findOrFail($userId))->test(Index::class);
}

/** A PNG header that says it is $w x $h: all LogoImage reads. */
function pngBytes(int $w = 120, int $h = 60): string
{
    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('N', $w).pack('N', $h)."\x08\x06\x00\x00\x00".str_repeat("\x00", 16);
}

it('opens for somebody with the settings right and refuses everyone else', function () {
    $this->get('/settings')->assertRedirect('/login');
    signedIn(staff(['sales' => viewOnly()]))->get('/settings')->assertRedirect('/no-access?area=settings');
    signedIn(staff(['settings' => viewOnly()], 'kofi'))->get('/settings')->assertOk()
        ->assertSee('How your shop records money and stock.')
        ->assertSee('You can see these settings but not change them.')
        ->assertDontSee('Save settings');
    signedIn(owner())->get('/settings')->assertOk()->assertSee('Save settings')->assertSee('What you charge');
});

it('refuses a save without the edit right', function () {
    settingsAs(staff(['settings' => viewOnly()]))->set('form.businessName', 'Changed')->call('save')->assertForbidden();
    expect(SettingsService::get()->business_name)->not->toBe('Changed');
});

it('saves the settings through the service and records the change', function () {
    settingsAs(owner())
        ->set('form.businessName', 'Ama Provisions')
        ->set('form.lowStock', '2.5')
        ->set('form.defaultTermsDays', '14')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect('/settings');

    $s = SettingsService::get();
    expect($s->business_name)->toBe('Ama Provisions')
        ->and((int) $s->low_stock_threshold_milli)->toBe(2_500)
        ->and((int) $s->default_terms_days)->toBe(14)
        ->and(DB::table('audit_logs')->where('entity_type', 'business_settings')->value('summary'))->toContain('Shop name:');
});

it('shows the old messages for what the form cannot accept', function () {
    settingsAs(owner())
        ->set('form.businessName', 'A')
        ->set('form.email', 'not an email')
        ->set('form.currencyCode', 'GH')
        ->set('form.defaultTermsDays', '400')
        ->set('form.lowStock', '-1')
        ->call('save')
        ->assertHasErrors([
            'form.businessName' => 'Enter your shop name.',
            'form.email' => 'Enter a valid email address, or leave it blank.',
            'form.currencyCode' => 'Use the three-letter code, like GHS or NGN.',
            'form.defaultTermsDays' => 'Payment terms longer than a year are not supported.',
        ]);
    expect(SettingsService::get()->business_name)->not->toBe('A');
});

it('shows the service refusing a currency change once the books have entries', function () {
    $ownerId = owner();
    Shop::postOpeningCapital();
    settingsAs($ownerId)->set('form.currencyCode', 'NGN')->call('save')
        ->assertHasErrors(['form' => 'The currency cannot be changed from GHS once there are transactions — every amount already recorded is in that currency, and relabelling them would misstate your history. Start a new set of books to trade in a different currency.']);
    expect(SettingsService::get()->currency_code)->toBe('GHS');
});

it('resets the feature switches to the new type\'s defaults when the type changes', function () {
    settingsAs(owner())
        ->set('form.businessType', 'building_materials')
        ->assertSet('form.features.expiry_batches', false)
        ->assertSet('form.features.quotations', true);
});

it('adds a tax through TaxService and refuses a bad rate with its message', function () {
    $account = (string) TaxService::holdingAccounts()->first()->id;
    $c = settingsAs(owner())->call('openNewTax')
        ->set('newTax.name', 'Tourism levy')->set('newTax.code', 'TOUR')->set('newTax.rate', 'abc')->set('newTax.glAccountId', $account)
        ->call('addTax')
        ->assertHasErrors(['newTax.rate' => '"abc" is not a percentage. Enter it like 12.5, without the % sign.']);

    $c->set('newTax.rate', '1')->call('addTax')->assertHasNoErrors()->assertSet('taxStatus', 'Tourism levy added.');
    expect((int) DB::table('tax_components')->where('code', 'TOUR')->value('rate_bp'))->toBe(100);
});

it('switches a tax off and on, and refuses it without the right', function () {
    $id = (int) TaxService::list()->first()->id;
    settingsAs(staff(['settings' => viewOnly()]))->call('toggleTax', $id)->assertForbidden();

    $before = (bool) TaxService::list()->firstWhere('id', $id)->is_active;
    settingsAs(owner())->call('toggleTax', $id)->assertHasNoErrors();
    expect((bool) TaxService::list()->firstWhere('id', $id)->is_active)->toBe(! $before);
});

it('stores a logo read from its bytes and serves it with the type it was read as', function () {
    $ownerId = owner();
    signedIn($ownerId)->get('/logo')->assertNotFound();

    signedIn($ownerId)->from('/settings')
        ->post('/settings/logo', ['logo' => UploadedFile::fake()->createWithContent('anything.txt', pngBytes())])
        ->assertRedirect('/settings')->assertSessionHas('logo-status', 'Logo updated. It will appear on your receipts.');

    $logo = SettingsService::logoSummary();
    expect($logo['mime'])->toBe('image/png')->and($logo['width'])->toBe(120)->and($logo['height'])->toBe(60);
    signedIn($ownerId)->get('/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    signedIn($ownerId)->from('/settings')->post('/settings/logo/remove')->assertRedirect('/settings');
    expect(SettingsService::logoSummary()['hasLogo'])->toBeFalse();
});

it('refuses an SVG, a non-image and an oversized picture with the old messages', function () {
    $ownerId = owner();
    $post = fn (string $content) => signedIn($ownerId)->from('/settings')->post('/settings/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $content)]);

    $post('<?xml version="1.0"?><svg></svg>')->assertSessionHasErrors(['logo' => 'SVG images are not accepted, because they can carry hidden instructions that run in the browser. Please use a PNG, JPEG or WebP.']);
    $post('hello')->assertSessionHasErrors(['logo' => 'That does not look like a PNG, JPEG or WebP image.']);
    $post(pngBytes(5000, 10))->assertSessionHasErrors(['logo' => 'That image is 5000x10 pixels. Please use one no larger than 4000 pixels on a side.']);
    expect(SettingsService::logoSummary()['hasLogo'])->toBeFalse();
});

it('refuses a logo upload without the edit right', function () {
    signedIn(staff(['settings' => viewOnly()]))->post('/settings/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', pngBytes())])->assertForbidden();
    expect(SettingsService::logoSummary()['hasLogo'])->toBeFalse();
});

it('reads JPEG and WebP sizes from their own headers', function () {
    $jpeg = "\xFF\xD8\xFF\xE0".pack('n', 16).str_repeat("\x00", 14)."\xFF\xC0".pack('n', 17)."\x08".pack('n', 30).pack('n', 40).str_repeat("\x00", 12);
    expect(LogoImage::inspect($jpeg))->toMatchArray(['mime' => 'image/jpeg', 'width' => 40, 'height' => 30]);

    $webp = 'RIFF'.pack('V', 30).'WEBP'.'VP8X'.pack('V', 10)."\x00\x00\x00\x00".pack('V', 99)."\x00".pack('V', 49).str_repeat("\x00", 4);
    $webp = substr_replace($webp, "\x63\x00\x00\x31\x00\x00", 24, 6);
    expect(LogoImage::inspect($webp))->toMatchArray(['mime' => 'image/webp', 'width' => 100, 'height' => 50]);
});

it('lets a staff member holding every settings right edit them', function () {
    $staff = staff(['settings' => Permissions::FULL_ACCESS]);
    signedIn($staff)->get('/settings')->assertOk()->assertSee('Save settings');
});
