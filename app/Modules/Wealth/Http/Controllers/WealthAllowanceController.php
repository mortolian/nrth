<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthContributionAllowance;
use App\Modules\Wealth\Services\ContributionAllowanceService;
use App\Modules\Wealth\Services\ResolveWealthPortfolio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WealthAllowanceController extends Controller
{
    public function index(
        Request $request,
        ResolveWealthPortfolio $resolver,
        ContributionAllowanceService $allowances,
    ): Response {
        $this->authorizeTeam('wealth.view', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);

        $requestedId = $request->integer('portfolio') ?: null;
        $portfolio = $resolver->resolve($team, $requestedId > 0 ? $requestedId : null);

        $assets = WealthAsset::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'owner_name']);

        return Inertia::render('Wealth/Allowances/Index', [
            'portfolio' => $resolver->present($portfolio),
            'portfolios' => $resolver->presentList($team),
            'allowances' => $allowances->listForPortfolio($portfolio),
            'assets' => $assets,
            'can_manage' => $request->user()->canOnTeam('wealth.manage', $team),
        ]);
    }

    public function store(Request $request, ResolveWealthPortfolio $resolver): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);

        $portfolioId = $request->integer('portfolio_id') ?: null;
        $portfolio = $resolver->resolve($team, $portfolioId > 0 ? $portfolioId : null);

        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'scheme_key' => ['nullable', 'string', 'max:64'],
            'financial_year_label' => ['required', 'string', 'max:32'],
            'year_starts_on' => ['required', 'date'],
            'year_ends_on' => ['required', 'date', 'after_or_equal:year_starts_on'],
            'limit_cents' => ['required', 'integer', 'min:0'],
            'asset_id' => [
                'nullable',
                'integer',
                Rule::exists('wealth_assets', 'id')->where(fn ($q) => $q->where('team_id', $team->id)->where('portfolio_id', $portfolio->id)),
            ],
            'notes' => ['nullable', 'string'],
        ]);

        WealthContributionAllowance::query()->create([
            'team_id' => $team->id,
            'portfolio_id' => $portfolio->id,
            'asset_id' => $validated['asset_id'] ?? null,
            'owner_name' => $validated['owner_name'],
            'label' => $validated['label'],
            'scheme_key' => $validated['scheme_key'] ?? null,
            'financial_year_label' => $validated['financial_year_label'],
            'year_starts_on' => $validated['year_starts_on'],
            'year_ends_on' => $validated['year_ends_on'],
            'limit_cents' => $validated['limit_cents'],
            'currency' => $portfolio->base_currency,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', __('Contribution allowance created.'));
    }

    public function destroy(Request $request, WealthContributionAllowance $allowance): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        abort_unless((int) $allowance->team_id === (int) $request->user()->current_team_id, 404);
        $allowance->delete();

        return back()->with('success', __('Contribution allowance removed.'));
    }
}
