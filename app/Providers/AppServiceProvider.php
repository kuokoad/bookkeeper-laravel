<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Permissions;
use App\Auth\Principal;
use App\Enums\PermissionModule;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Money, quantities and dates in Blade come from the domain formatters, never by hand.
        Blade::directive('money', fn (string $e): string => "<?php echo e(\\App\\View\\Format::money({$e})); ?>");
        Blade::directive('qty', fn (string $e): string => "<?php echo e(\\App\\View\\Format::qty({$e})); ?>");
        Blade::directive('date', fn (string $e): string => "<?php echo e(\\App\\View\\Format::date({$e})); ?>");
        // resources/views/layouts/* as <x-layouts::app>, and as the Livewire page layout (config/livewire.php).
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');

        // One ability per module and action ("sales.create"): the old requirePermission.
        // Read from the database each time, never from the session.
        foreach (PermissionModule::cases() as $module) {
            foreach (Permissions::ACTIONS as $action) {
                Gate::define("{$module->value}.{$action}", function (User $user) use ($module, $action): bool {
                    // Somebody handed a starting password must choose their own before acting as that account.
                    if ((bool) DB::table('users')->where('users.id', $user->id)->value('must_change_password')) {
                        return false;
                    }
                    $principal = Principal::load((int) $user->id);

                    return $principal !== null && Permissions::can($principal, $module, $action);
                });
            }
        }
    }
}
