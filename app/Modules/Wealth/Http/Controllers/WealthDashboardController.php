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

        $requestedId = $request->integer('portfolio') ?: null;
        $portfolio = $resolver->resolve($team, $requestedId > 0 ? $requestedId : null);
        $overview = $performance->overview($portfolio);

        return Inertia::render('Wealth/Index', [
            'portfolio' => $resolver->present($portfolio),
            'portfolios' => $resolver->presentList($team),
            'overview' => $overview,
            'monthly_history' => array_slice($valuations->monthlySeries($portfolio), -12),
            'can_manage' => TeamAccess::allows($request->user(), $team, 'wealth.manage'),
        ]);
    }
}
