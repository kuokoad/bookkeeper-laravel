<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Services\SearchService;
use App\Support\HttpThrottle;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Search results as JSON for search-as-you-type: the old api/search route.
 * The SAME SearchService::search the /search page runs, so the dropdown and
 * the page never disagree about what exists or who may see it. Signed in is
 * the only requirement; the service queries only the record types the
 * person may view.
 */
final class SuggestController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $principal = Principal::load((int) auth()->id());
        if ($principal === null) {
            return response()->json(['error' => 'Not signed in.'], 401);
        }
        if (($throttled = HttpThrottle::check("search:{$principal->id}", HttpThrottle::SEARCH)) !== null) {
            return $throttled;
        }

        $q = $request->query('q');

        // Two people at one counter are two sets of permissions: nothing may cache the answer.
        return response()->json(SearchService::search(is_string($q) ? $q : '', $principal), 200, ['Cache-Control' => 'no-store, private']);
    }
}
