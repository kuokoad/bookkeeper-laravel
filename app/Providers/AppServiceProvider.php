<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Permissions;
use App\Auth\Principal;
use App\Enums\PermissionModule;
use App\Models\User;
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
