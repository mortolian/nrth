<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domain\Reports\Services\AgedReceivablesPdfService;
use App\Domain\Reports\Services\AgedReceivablesService;
use App\Http\Controllers\Controller;
use App\Support\StickyFilters;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AgedReceivablesController extends Controller
{
    public function __construct(
        private readonly AgedReceivablesService $agedReceivablesService,
        private readonly AgedReceivablesPdfService $agedReceivablesPdfService,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.aged_receivables', [
            'as_of' => null,
        ]);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);
        $asOf = $this->asOf($request);

        return Inertia::render('Reports/AgedReceivables', [
            'report' => $this->agedReceivablesService->asOf((int) $team->id, $asOf),
            'as_of' => $asOf->toDateString(),
            'currency' => $this->agedReceivablesService->currency($team),
        ]);
    }

    public function pdf(Request $request): SymfonyResponse|RedirectResponse
    {
        $this->authorizeTeam('reports.export', $request);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        try {
            return $this->agedReceivablesPdfService->download($team, $this->asOf($request));
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: __('Could not export PDF.'));
        }
    }

    private function asOf(Request $request): Carbon
    {
        if ($request->filled('as_of')) {
            try {
                return Carbon::parse($request->string('as_of')->toString())->startOfDay();
            } catch (\Throwable) {
                // Fall through to today when the query date is not usable.
            }
        }

        return now()->startOfDay();
    }
}
