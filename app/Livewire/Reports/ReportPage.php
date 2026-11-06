<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use Illuminate\Http\Request;
use Livewire\Component;

/**
 * A report screen: reads only, so all it keeps is the query string it was
 * opened with (a /livewire/update request carries none).
 */
abstract class ReportPage extends Component
{
    public array $params = [];

    public function mount(Request $request): void
    {
        $this->params = $request->query();
    }
}
