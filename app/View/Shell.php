<?php

declare(strict_types=1);

namespace App\View;

use App\Auth\Principal;
use App\Enums\Look;
use App\Http\Controllers\ThemeController;
use App\Models\User;
use App\Services\NotificationsService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * What the layouts read: the old root layout's theme and look, and the
 * (app)/layout.tsx shell's shop name, menu and notices. The shop name and the
 * menu are two readings of ONE settings row, never of two.
 */
final class Shell
{
    /** @return array{theme: ?string, look: ?string} the <html> data-theme / data-look; null sets nothing */
    public static function root(): array
    {
        try {
            $look = Look::tryFrom((string) SettingsService::get()->look) ?? Look::Default;
        } catch (Throwable) {
            // A brand new install, or a database that will not open, still renders the sign-in screen.
            $look = Look::Default;
        }

        return ['theme' => ThemeController::attribute(request()), 'look' => $look === Look::Default ? null : $look->value];
    }

    /** @return array{user: User, shopName: string, sections: array, primary: array, notices: array} */
    public static function app(): array
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $settings = SettingsService::get();
        $sections = Navigation::sectionsFor($user, SettingsService::features($settings));
        $principal = Principal::load((int) $user->id);

        return [
            'user' => $user,
            'shopName' => $settings->business_name ?: 'NunaBooks',
            'sections' => $sections,
            'primary' => Navigation::primaryItems($sections),
            'notices' => $principal === null ? [] : NotificationsService::getNotices($principal),
        ];
    }
}
