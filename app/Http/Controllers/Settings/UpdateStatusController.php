<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;

/**
 * Where an update has got to, for the Health page to poll while the app goes
 * down and comes back: the old GET api/update. A route rather than a
 * Livewire call, so the page that started the update can still ask the new
 * version once it is back.
 */
final class UpdateStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(UpdateService::readUpdateStatus(), 200, ['Cache-Control' => 'no-store']);
    }
}
