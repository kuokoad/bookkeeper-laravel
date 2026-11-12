<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Errors\NotFoundError;
use App\Reports\Csv\ListExport;
use App\Reports\Csv\ReportExport;
use App\Support\HttpThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every CSV download: the old api/exports/[list] and api/reports/[report]
 * routes. The file comes from ListExport / ReportExport, which use the SAME
 * filter parser and service read as the page, so the file is exactly the rows
 * on screen. These routes answer code, not a person: 401, 403 and 404 in
 * plain text, never a redirect, as the old routes did, so the permission
 * check is here (per list: ListExport::MODULES) rather than on the route.
 */
final class ExportController extends Controller
{
    /**
     * Reports whose screens need more than reports view: the file must not give
     * what the screen refuses (owner decision 2026-11-07; the old app checked reports only).
     */
    private const REPORT_MODULES = ['receivables' => 'accounts', 'payables' => 'accounts', 'year-end' => 'accounts'];

    public function list(Request $request, string $list): Response
    {
        // An unknown list is 404 before anything else, as the old route.
        $module = ListExport::MODULES[$list] ?? null;
        if ($module === null) {
            return self::text("Unknown export \"{$list}\".", 404);
        }
        $refused = self::refuse("{$module}.view", 'You do not have permission to export this.');
        if ($refused !== null) {
            return $refused;
        }

        try {
            $file = ListExport::download($list, $request->query());
        } catch (NotFoundError) {
            return self::text('Not found.', 404);
        }

        return $file === null ? self::text("Unknown export \"{$list}\".", 404) : self::csv($file);
    }

    public function report(Request $request, string $report): Response
    {
        $refused = self::refuse('reports.view', 'You do not have permission to export reports.');
        if ($refused !== null) {
            return $refused;
        }
        $module = self::REPORT_MODULES[$report] ?? null;
        if ($module !== null && Gate::forUser(Auth::guard('web')->user())->denies("{$module}.view")) {
            return self::text('You do not have permission to export this.', 403);
        }
        $file = ReportExport::download($report, $request->query());

        return $file === null ? self::text("Unknown report \"{$report}\".", 404) : self::csv($file);
    }

    /** Signed in, allowed, and under the per-person ceiling, or the response that says which not. */
    private static function refuse(string $ability, string $forbidden): ?Response
    {
        $user = Auth::guard('web')->user();
        if ($user === null) {
            return self::text('Please sign in.', 401);
        }
        if (Gate::forUser($user)->denies($ability)) {
            return self::text($forbidden, 403);
        }

        // Keyed on the person: a loop of exports scans the ledger and slows every till.
        return HttpThrottle::check("export:{$user->getAuthIdentifier()}", HttpThrottle::EXPORT);
    }

    /** @param  array{filename: string, body: string}  $file */
    private static function csv(array $file): Response
    {
        // Only safe characters reach the header, so nothing typed into the URL can break out of the quotes.
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['filename']);

        return new Response($file['body'], 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            // Live figures: never let a proxy or the browser cache them.
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function text(string $body, int $status): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
