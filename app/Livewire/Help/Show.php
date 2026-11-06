<?php

declare(strict_types=1);

namespace App\Livewire\Help;

use Illuminate\View\View;
use Livewire\Component;

/** One help page: the old (app)/help/[slug]. Only slugs in Pages::PAGES exist. */
final class Show extends Component
{
    public string $slug;

    public function mount(string $slug): void
    {
        abort_unless(array_key_exists($slug, Pages::PAGES), 404);
        $this->slug = $slug;
    }

    public function render(): View
    {
        [$title] = Pages::PAGES[$this->slug];

        return view('livewire.help.show', ['title' => $title, 'html' => Pages::render($this->slug)])
            ->title("{$title} — Help");
    }
}
