<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domain\Reports\Services\IncomeReportPdfService;
use App\Domain\Reports\Services\IncomeReportService;
use App\Http\Controllers\Controller;
use App\Support\PageSize;
use App\Support\StickyFilters;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class IncomeReportController extends Controller
{
    public function __construct(
        private readonly IncomeReportService $incomeReportService,
        private readonly IncomeReportPdfService $incomeReportPdfService,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.income', [
            'fy' => null,
        ]);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $window = $this->incomeReportService->window($team, $this->requestedStartYear($request));
        $from = Carbon::parse($window['from']);
        $to = Carbon::parse($window['to']);
        $teamId = (int) $team->id;

        return Inertia::render('Reports/Income', [
            'report' => $this->incomeReportService->summary($teamId, $from, $to),
            'transactions' => $this->incomeReportService->paginate(
                $teamId,
                $from,
                $to,
                PageSize::resolve($request, 25),
            ),
            'financial_year' => $window,
            'year_options' => $this->incomeReportService->yearOptions($team),
            'currency' => $this->incomeReportService->currency($team),
        ]);
    }

    public function pdf(Request $request): SymfonyResponse|RedirectResponse
    {
        $this->authorizeTeam('reports.export', $request);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $window = $this->incomeReportService->window($team, $this->requestedStartYear($request));

        try {
            return $this->incomeReportPdfService->download($team, $window);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: __('Could not export PDF.'));
        }
    }

    private function requestedStartYear(Request $request): ?int
    {
        if (! $request->filled('fy')) {
            return null;
        }

        return (int) $request->input('fy');
    }
}
