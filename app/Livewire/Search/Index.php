<?php

declare(strict_types=1);

namespace App\Livewire\Search;

use App\Auth\Principal;
use App\Services\SearchService;
use App\Support\HttpThrottle;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Search results: the old (app)/search page, with the old top-bar box's
 * search-as-you-type folded in (results follow the box as you type, the URL
 * keeps ?q= so a search is still a link). Any signed-in person: the
 * permission work is per record type inside SearchService.
 */
#[Title('Search')]
final class Index extends Component
{
    #[Url(except: '')]
    public string $q = '';

    public bool $throttled = false;

    /** A keystroke, not a report: live updates share the suggest route's ceiling. */
    public function updatedQ(): void
    {
        $this->throttled = HttpThrottle::check('search:'.auth()->id(), HttpThrottle::SEARCH) !== null;
    }

    public function render(): View
    {
        $results = $this->throttled
            ? ['query' => trim($this->q), 'groups' => [], 'total' => 0, 'truncated' => false]
            : SearchService::search($this->q, Principal::load((int) auth()->id()));

        return view('livewire.search.index', [
            'results' => $results,
            'tooShort' => mb_strlen(trim($this->q)) === 1,
        ]);
    }
}
