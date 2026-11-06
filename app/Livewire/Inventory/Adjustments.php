<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Services\StockAdjustmentService;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old inventory/adjustments/page.tsx: the latest 100 adjustments. Reads only. */
#[Title('Stock adjustments')]
final class Adjustments extends Component
{
    public function render(): View
    {
        return view('livewire.inventory.adjustments', ['adjustments' => StockAdjustmentService::listStockAdjustments(100)]);
    }
}
