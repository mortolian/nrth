<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domain\Reports\Services\ClientStatementPdfService;
use App\Domain\Reports\Services\ClientStatementService;
use App\Domain\Reports\Services\ReportPeriod;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\StickyFilters;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ClientStatementController extends Controller
{
    public function __construct(
        private readonly ClientStatementService $clientStatementService,
        private readonly ClientStatementPdfService $clientStatementPdfService,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.client_statement', [
            'client_id' => null,
            'from' => null,
            'to' => null,
        ]);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);
        [$from, $to] = $this->period($request, $team);
        $statement = null;

        if ($request->filled('client_id')) {
            $client = $this->clientStatementService->findClient((int) $team->id, (int) $request->input('client_id'));
            abort_if($client === null, 404);
            $statement = $this->clientStatementService->statement((int) $team->id, $client, $from, $to);
        }

        return Inertia::render('Reports/ClientStatement', [
            'clients' => $this->clientStatementService->clients((int) $team->id),
            'statement' => $statement,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'currency' => $this->clientStatementService->currency($team),
        ]);
    }

    public function pdf(Request $request): SymfonyResponse|RedirectResponse
    {
        $this->authorizeTeam('reports.export', $request);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
        ]);

        $client = $this->clientStatementService->findClient((int) $team->id, (int) $validated['client_id']);
        abort_if($client === null, 404);

        [$from, $to] = $this->period($request, $team);

        try {
            return $this->clientStatementPdfService->download($team, $client, $from, $to);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: __('Could not export PDF.'));
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request, Team $team): array
    {
        $window = ReportPeriod::window($team, null);
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : Carbon::parse($window['from'])->startOfDay();
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->startOfDay()
            : Carbon::parse($window['to'])->startOfDay();

        if ($from->greaterThan($to)) {
            return [$to, $from];
        }

        return [$from, $to];
    }
}
