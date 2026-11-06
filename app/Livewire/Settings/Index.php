<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\SettingsService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The example module page (docs/UI-CONVENTIONS.md): a stub of /settings that
 * reads through its service and holds no logic. The settings form itself
 * (old (app)/settings) is built by the settings module agent.
 */
#[Title('Settings')]
final class Index extends Component
{
    public function render(): View
    {
        return view('livewire.settings.index', ['settings' => SettingsService::get()]);
    }
}
