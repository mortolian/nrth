<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\Reports\Services\ReportPeriod;
use App\Domain\Reports\Services\StatementFilterReportPdfService;
use App\Domain\Reports\Services\StatementFilterReportService;
use App\Http\Controllers\Controller;
use App\Support\StickyFilters;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class StatementFilterReportController extends Controller
{
    public function __construct(
        private readonly StatementFilterReportService $statementFilterReportService,
        private readonly StatementFilterReportPdfService $statementFilterReportPdfService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);

        $reports = StatementFilterReport::query()
            ->withCount('rules')
            ->orderBy('name')
            ->get()
            ->map(fn (StatementFilterReport $report): array => [
                'id' => $report->id,
                'name' => $report->name,
                'rules_count' => (int) $report->rules_count,
                'updated_at' => $report->updated_at?->toDateString(),
            ])
            ->all();

        return Inertia::render('Reports/StatementFilters/Index', [
            'reports' => $reports,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('reports.manage', $request);

        return Inertia::render('Reports/StatementFilters/Form', [
            'report' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('reports.manage', $request);
        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $data = $this->validatedReport($request);
        $report = StatementFilterReport::query()->create([
            'team_id' => $team->id,
            'name' => trim($data['name']),
        ]);
        $report->replaceRules($data['rules']);

        return redirect()
            ->route('reports.statement-filters.show', $report)
            ->with('success', __('Statement filter report saved.'));
    }

    public function show(Request $request, StatementFilterReport $statementFilterReport): Response|RedirectResponse
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.statement-filters', [
            'from' => null,
            'to' => null,
            'view' => null,
            'breakdown' => null,
        ]);

        try {
            [$from, $to] = $this->period($request);
        } catch (ValidationException $e) {
            $teamId = (int) ($request->user()?->current_team_id ?? 0);
            $request->session()->forget('sticky_filters.reports.statement-filters.'.$teamId);

            return redirect()
                ->route('reports.statement-filters.show', $statementFilterReport)
                ->with('error', collect($e->errors())->flatten()->first() ?: __('Choose a period of five years or less.'));
        }
        [$view, $breakdown] = $this->presentation($request);
        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        return Inertia::render('Reports/StatementFilters/Show', [
            'report' => [
                'id' => $statementFilterReport->id,
                'name' => $statementFilterReport->name,
            ],
            'result' => $this->statementFilterReportService->run($statementFilterReport, $from, $to, $breakdown, $view),
            'currency' => ReportPeriod::currency($team),
        ]);
    }

    public function edit(Request $request, StatementFilterReport $statementFilterReport): Response
    {
        $this->authorizeTeam('reports.manage', $request);

        return Inertia::render('Reports/StatementFilters/Form', [
            'report' => $this->formPayload($statementFilterReport),
        ]);
    }

    public function update(Request $request, StatementFilterReport $statementFilterReport): RedirectResponse
    {
        $this->authorizeTeam('reports.manage', $request);

        $data = $this->validatedReport($request);
        $statementFilterReport->update(['name' => trim($data['name'])]);
        $statementFilterReport->replaceRules($data['rules']);

        return redirect()
            ->route('reports.statement-filters.show', $statementFilterReport)
            ->with('success', __('Statement filter report updated.'));
    }

    public function destroy(Request $request, StatementFilterReport $statementFilterReport): RedirectResponse
    {
        $this->authorizeTeam('reports.manage', $request);
        $statementFilterReport->delete();

        return redirect()
            ->route('reports.statement-filters.index')
            ->with('success', __('Statement filter report deleted.'));
    }

    public function pdf(Request $request, StatementFilterReport $statementFilterReport): SymfonyResponse|RedirectResponse
    {
        $this->authorizeTeam('reports.export', $request);
        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        try {
            [$from, $to] = $this->period($request);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: __('Could not export PDF.'));
        }

        [$view, $breakdown] = $this->presentation($request);

        return $this->statementFilterReportPdfService->download(
            $team,
            $statementFilterReport,
            $from,
            $to,
            $breakdown,
            $view,
        );
    }

    /**
     * @return array{id: int, name: string, rules: list<array{alias: string, match: string}>}
     */
    private function formPayload(StatementFilterReport $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'rules' => $report->rules()->get()->map(fn ($rule): array => [
                'alias' => $rule->alias,
                'match' => $rule->match,
            ])->all(),
        ];
    }

    /**
     * @return array{name: string, rules: list<array{alias: string, match: string}>}
     */
    private function validatedReport(Request $request): array
    {
        /** @var array{name: string, rules: list<array{alias: string, match: string}>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rules' => ['required', 'array', 'min:1', 'max:20'],
            'rules.*.alias' => ['required', 'string', 'max:80'],
            'rules.*.match' => ['required', 'string', 'max:120'],
        ]);

        return $data;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $from = $this->dateOrNull($request->input('from')) ?? now()->startOfMonth();
        $to = $this->dateOrNull($request->input('to')) ?? now()->endOfMonth()->startOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        if (abs($from->diffInDays($to)) > 1826) {
            throw ValidationException::withMessages([
                'to' => __('Choose a period of five years or less.'),
            ]);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function presentation(Request $request): array
    {
        $view = $request->string('view')->toString() === 'detailed' ? 'detailed' : 'summary';
        $breakdown = $request->string('breakdown')->toString() === 'monthly' ? 'monthly' : 'period';

        return [$view, $breakdown];
    }
}
