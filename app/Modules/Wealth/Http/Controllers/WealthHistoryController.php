<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Services\PortfolioValuationService;
use App\Modules\Wealth\Services\ResolveWealthPortfolio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WealthHistoryController extends Controller
{
    public function __invoke(
        Request $request,
        ResolveWealthPortfolio $resolver,
        PortfolioValuationService $valuations,
    ): Response {
        $this->authorizeTeam('wealth.view', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);

        $requestedId = $request->integer('portfolio') ?: null;
        $portfolio = $resolver->resolve($team, $requestedId > 0 ? $requestedId : null);

        return Inertia::render('Wealth/History', [
            'portfolio' => $resolver->present($portfolio),
            'portfolios' => $resolver->presentList($team),
            'monthly' => $valuations->monthlySeries($portfolio),
            'annual' => $valuations->annualSeries($portfolio),
        ]);
    }
}
