<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domain\Reports\Services\IncomeReportPdfService;
use App\Domain\Reports\Services\IncomeReportService;
use App\Domain\Reports\Services\ReportPeriod;
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
            'view' => null,
            'breakdown' => null,
        ]);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $window = $this->incomeReportService->window($team, $this->requestedStartYear($request));
        $presentation = $this->presentation($request);
        $from = Carbon::parse($window['from']);
        $to = Carbon::parse($window['to']);
        $teamId = (int) $team->id;

        return Inertia::render('Reports/Income', [
            'report' => $this->incomeReportService->summary($teamId, $from, $to),
            'transaction_count' => $this->incomeReportService->count($teamId, $from, $to),
            'transactions' => $presentation['view'] === 'detailed'
                ? $this->incomeReportService->paginate($teamId, $from, $to, PageSize::resolve($request, 25))
                : null,
            'months' => $presentation['breakdown'] === 'monthly'
                ? $this->incomeReportService->monthly($teamId, $from, $to)
                : [],
            'presentation' => $presentation,
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
            return $this->incomeReportPdfService->download($team, $window, $this->presentation($request));
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

    /**
     * @return array{view: string, breakdown: string}
     */
    private function presentation(Request $request): array
    {
        return ReportPeriod::presentation(
            $request->filled('view') ? (string) $request->input('view') : null,
            $request->filled('breakdown') ? (string) $request->input('breakdown') : null,
        );
    }
}
