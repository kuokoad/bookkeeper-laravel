<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Auth\Principal;
use App\Domain\Errors\ValidationError;
use App\Http\Controllers\Controller;
use App\Services\Actor;
use App\Services\SettingsService;
use App\Support\Clock;
use App\Support\LogoImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop's logo: served for receipts (the old api/logo route) and
 * uploaded or removed from Settings (the old uploadLogoAction and
 * removeLogoAction). The type served is the one read from the bytes at
 * upload, never the one the browser announced.
 */
final class LogoController extends Controller
{
    public function show(): Response
    {
        $logo = SettingsService::logo();
        if ($logo === null) {
            return response('No logo has been set.', 404, ['Content-Type' => 'text/plain', 'X-Content-Type-Options' => 'nosniff']);
        }

        return response($logo['data'], 200, array_filter([
            'Content-Type' => $logo['mime'],
            'Content-Length' => (string) strlen($logo['data']),
            'X-Content-Type-Options' => 'nosniff',
            // Never rendered as a page in its own right.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            // Revalidate rather than cache hard: a changed logo must show up at once.
            'Cache-Control' => 'no-cache, must-revalidate',
            'Last-Modified' => $logo['updatedAt'] === null ? null : Clock::fromStamp($logo['updatedAt'])->toRfc7231String(),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('settings.edit');
        $file = $request->file('logo');
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() === 0) {
            return back()->withErrors(['logo' => 'Choose an image to upload.']);
        }
        // Checked before reading the whole thing into memory.
        if ($file->getSize() > LogoImage::MAX_BYTES) {
            return back()->withErrors(['logo' => LogoImage::tooLarge((int) $file->getSize())]);
        }

        $data = (string) file_get_contents($file->getRealPath());
        try {
            $image = LogoImage::inspect($data);
            SettingsService::setLogo($data, $image['mime'], $image['width'], $image['height'], $this->actor());
        } catch (ValidationError $e) {
            return back()->withErrors(['logo' => $e->userMessage]);
        }

        return back()->with('logo-status', 'Logo updated. It will appear on your receipts.');
    }

    public function destroy(): RedirectResponse
    {
        Gate::authorize('settings.edit');
        SettingsService::clearLogo($this->actor());

        return back();
    }

    private function actor(): Actor
    {
        return Principal::load((int) auth()->id())->actor();
    }
}
