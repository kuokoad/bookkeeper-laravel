<?php

declare(strict_types=1);

/*
| Audit finding 16: nuna:restore refused only while Apache ran as a Windows
| service, so under Laragon or `php artisan serve` a till could record a sale
| mid-restore. Now the app is in maintenance mode for the whole restore.
*/

use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

afterEach(fn () => app()->maintenanceMode()->deactivate());

it('closes the shop for the restore and opens it again, even when the restore fails', function () {
    Event::fake([MaintenanceModeEnabled::class, MaintenanceModeDisabled::class]);

    expect(Artisan::call('nuna:restore', ['file' => storage_path('no-such-backup.sql.gz'), '--force' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('There is no backup at');
    Event::assertDispatched(MaintenanceModeEnabled::class);
    Event::assertDispatched(MaintenanceModeDisabled::class);
    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('leaves a shop that was already closed closed', function () {
    Artisan::call('down');
    Event::fake([MaintenanceModeEnabled::class, MaintenanceModeDisabled::class]);

    Artisan::call('nuna:restore', ['file' => storage_path('no-such-backup.sql.gz'), '--force' => true]);
    Event::assertNotDispatched(MaintenanceModeDisabled::class);
    expect(app()->isDownForMaintenance())->toBeTrue()
        ->and(file_exists(storage_path('framework/down')))->toBeFalse();
});
