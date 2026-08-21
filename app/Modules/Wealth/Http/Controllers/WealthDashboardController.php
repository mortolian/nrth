<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Services\PortfolioPerformanceService;
use App\Modules\Wealth\Services\PortfolioValuationService;
use App\Modules\Wealth\Services\ResolveWealthPortfolio;
use App\Support\TeamAccess\TeamAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WealthDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        ResolveWealthPortfolio $resolver,
        PortfolioPerformanceService $performance,
        PortfolioValuationService $valuations,
    ): Response {
        $this->authorizeTeam('wealth.view', $request);

        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);

        $showArchived = $request->boolean('show_archived');
        $requestedId = $request->integer('portfolio') ?: null;
        $portfolio = $resolver->resolve($team, $requestedId > 0 ? $requestedId : null, $showArchived);
        $overview = $performance->overview($portfolio, includeArchived: $showArchived);

        return Inertia::render('Wealth/Index', [
            'portfolio' => $resolver->present($portfolio),
            'portfolios' => $resolver->presentList($team, $showArchived),
            'overview' => $overview,
            'show_archived' => $showArchived,
            // Up to 10Y so the overview chart can filter 1M / 1Y / 2Y / 5Y / 10Y client-side.
            'monthly_history' => $valuations->monthlySeries(
                $portfolio,
                now()->copy()->subYears(10)->startOfMonth(),
            ),
            'historical_growth' => $valuations->historicalGrowth($portfolio),
            'can_manage' => TeamAccess::allows($request->user(), $team, 'wealth.manage'),
        ]);
    }
}
