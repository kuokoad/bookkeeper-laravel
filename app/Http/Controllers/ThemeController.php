<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Light, dark, or the device's own choice: the old theme.actions.ts. A
 * cookie on this screen, not a setting of the person; no permission and no
 * audit. Anything unrecognised means "follow the device" (no cookie).
 */
final class ThemeController extends Controller
{
    public const COOKIE = 'bk_theme';

    public const THEMES = ['system', 'light', 'dark'];

    public function __invoke(Request $request): RedirectResponse
    {
        $theme = in_array($request->input('theme'), self::THEMES, true) ? $request->input('theme') : 'system';
        $cookie = $theme === 'system'
            ? Cookie::forget(self::COOKIE)
            : cookie(self::COOKIE, $theme, 60 * 24 * 365, '/', null, null, false, false, 'lax');

        return back()->withCookie($cookie);
    }

    /** What goes on <html>: 'system' sets nothing, so the media query decides. */
    public static function attribute(Request $request): ?string
    {
        $stored = $request->cookie(self::COOKIE);

        return $stored === 'light' || $stored === 'dark' ? $stored : null;
    }
}
