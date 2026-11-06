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
use Livewire\Features\SupportRedirects\SupportRedirects;

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

        /*
         * Livewire swaps its own Redirector into the container while a
         * component runs and puts Laravel's back when the component
         * dehydrates. A mount that aborts (a 404) never dehydrates, so after
         * Livewire::test() of such a page the next request's redirect() was
         * Livewire's, whose guest()/to() return the Redirector rather than a
         * response (EnforceSessionLimits failed with a TypeError). Livewire
         * flushes its state after each test render: put Laravel's back then.
         */
        \Livewire\on('flush-state', function (): void {
            if (SupportRedirects::$redirectorCacheStack !== []) {
                $this->app->instance('redirect', SupportRedirects::$redirectorCacheStack[0]);
                SupportRedirects::$redirectorCacheStack = [];
            }
        });

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
