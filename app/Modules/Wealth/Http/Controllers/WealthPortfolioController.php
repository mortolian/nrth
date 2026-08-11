<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Modules\Wealth\Services\ResolveWealthPortfolio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WealthPortfolioController extends Controller
{
    public function store(Request $request, ResolveWealthPortfolio $resolver): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('wealth_portfolios', 'name')->where(fn ($q) => $q->where('team_id', $team->id)->whereNull('deleted_at')),
            ],
            'financial_year_start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string'],
        ]);

        $portfolio = $resolver->createForTeam(
            $team,
            $validated['name'],
            isset($validated['financial_year_start_month']) ? (int) $validated['financial_year_start_month'] : null,
            $validated['notes'] ?? null,
        );

        return redirect()
            ->route('wealth.index', ['portfolio' => $portfolio->id])
            ->with('success', __('Portfolio created.'));
    }

    public function update(Request $request, WealthPortfolio $portfolio): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);
        abort_unless((int) $portfolio->team_id === (int) $team->id, 404);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('wealth_portfolios', 'name')
                    ->where(fn ($q) => $q->where('team_id', $team->id)->whereNull('deleted_at'))
                    ->ignore($portfolio->id),
            ],
            'financial_year_start_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['is_default'])) {
            DB::transaction(function () use ($team, $portfolio, $validated): void {
                WealthPortfolio::query()
                    ->where('team_id', $team->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);

                $portfolio->fill([
                    ...collect($validated)->except('is_default')->all(),
                    'is_default' => true,
                ])->save();
            });
        } else {
            $portfolio->fill(collect($validated)->except('is_default')->all())->save();
        }

        return back()->with('success', __('Portfolio updated.'));
    }

    public function select(Request $request, WealthPortfolio $portfolio, ResolveWealthPortfolio $resolver): RedirectResponse
    {
        $this->authorizeTeam('wealth.view', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);
        abort_unless((int) $portfolio->team_id === (int) $team->id, 404);

        $resolver->remember($team, $portfolio);

        $redirectTo = $request->input('redirect', route('wealth.index', ['portfolio' => $portfolio->id]));

        return redirect()->to($redirectTo)->with('success', __('Switched portfolio.'));
    }

    public function destroy(Request $request, WealthPortfolio $portfolio, ResolveWealthPortfolio $resolver): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);
        abort_unless((int) $portfolio->team_id === (int) $team->id, 404);

        $count = WealthPortfolio::query()->where('team_id', $team->id)->count();
        if ($count <= 1) {
            return back()->with('error', __('You need at least one portfolio.'));
        }

        $wasDefault = (bool) $portfolio->is_default;
        $portfolio->delete();

        if ($wasDefault) {
            $next = WealthPortfolio::query()->where('team_id', $team->id)->orderBy('name')->first();
            if ($next !== null) {
                $next->forceFill(['is_default' => true])->save();
            }
        }

        $current = $resolver->resolve($team);

        return redirect()
            ->route('wealth.index', ['portfolio' => $current->id])
            ->with('success', __('Portfolio archived.'));
    }
}
