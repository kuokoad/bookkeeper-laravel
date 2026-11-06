<?php

declare(strict_types=1);

namespace App\Livewire\Help;

use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The list of help pages: the old (app)/help page. For everyone signed in. */
#[Title('Help')]
final class Index extends Component
{
    public function render(): View
    {
        return view('livewire.help.index', ['pages' => Pages::PAGES]);
    }
}
