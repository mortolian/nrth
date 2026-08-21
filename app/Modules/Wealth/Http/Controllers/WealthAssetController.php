<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Modules\Wealth\Services\AssetValuationService;
use App\Modules\Wealth\Services\PortfolioPerformanceService;
use App\Modules\Wealth\Services\ResolveWealthPortfolio;
use App\Support\TeamAccess\TeamAccess;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WealthAssetController extends Controller
{
    public function create(Request $request, ResolveWealthPortfolio $resolver): Response
    {
        $this->authorizeTeam('wealth.manage', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);
        $requestedId = $request->integer('portfolio') ?: null;
        $portfolio = $resolver->resolve($team, $requestedId > 0 ? $requestedId : null);

        return Inertia::render('Wealth/Assets/Form', [
            'asset' => null,
            'portfolio' => $resolver->present($portfolio),
            'portfolios' => $resolver->presentList($team),
            'asset_types' => WealthAssetType::options(),
            'liquidity_options' => WealthLiquidity::options(),
        ]);
    }

    public function store(Request $request, ResolveWealthPortfolio $resolver, AssetValuationService $valuations): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);

        $portfolioId = $request->integer('portfolio_id') ?: null;
        $portfolio = $resolver->resolve($team, $portfolioId > 0 ? $portfolioId : null);

        $validated = $this->validateAsset($request, $portfolio);

        $asset = WealthAsset::query()->create([
            'team_id' => $team->id,
            'portfolio_id' => $portfolio->id,
            'name' => $validated['name'],
            'owner_name' => $validated['owner_name'],
            'asset_type' => $validated['asset_type'],
            'institution' => $validated['institution'] ?? null,
            'currency' => $portfolio->base_currency,
            'liquidity' => $validated['liquidity'],
            'interest_rate_bps' => $validated['interest_rate_bps'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => true,
        ]);

        if (isset($validated['opening_value_cents']) && $validated['opening_value_cents'] !== null) {
            $valuations->record(
                $asset,
                Carbon::parse($validated['opening_valued_on'] ?? now()->toDateString()),
                (int) $validated['opening_value_cents'],
            );
        }

        $resolver->remember($team, $portfolio);

        return redirect()
            ->route('wealth.assets.show', $asset)
            ->with('success', __('Asset created.'));
    }

    public function show(Request $request, WealthAsset $asset, PortfolioPerformanceService $performance, ResolveWealthPortfolio $resolver): Response
    {
        $this->authorizeTeam('wealth.view', $request);
        $this->assertTeamAsset($request, $asset);

        $team = $request->user()->currentTeam;
        if ($team !== null && $asset->portfolio) {
            $resolver->remember($team, $asset->portfolio);
        }

        return Inertia::render('Wealth/Assets/Show', [
            'detail' => $performance->assetDetail($asset),
            'asset_types' => WealthAssetType::options(),
            'liquidity_options' => WealthLiquidity::options(),
            'transaction_types' => WealthTransactionType::options(),
            'can_manage' => TeamAccess::allows($request->user(), $team, 'wealth.manage'),
        ]);
    }

    public function edit(Request $request, WealthAsset $asset, ResolveWealthPortfolio $resolver): Response
    {
        $this->authorizeTeam('wealth.manage', $request);
        $this->assertTeamAsset($request, $asset);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 403);
        $portfolio = $asset->portfolio ?? $resolver->resolve($team);

        $earliestValuation = $asset->valuations()
            ->reorder()
            ->orderBy('valued_on')
            ->orderBy('id')
            ->first();

        return Inertia::render('Wealth/Assets/Form', [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'owner_name' => $asset->owner_name,
                'asset_type' => $asset->asset_type->value,
                'institution' => $asset->institution,
                'liquidity' => $asset->liquidity->value,
                'interest_rate_bps' => $asset->interest_rate_bps,
                'notes' => $asset->notes,
                'is_archived' => $asset->trashed(),
                'archived_at' => $asset->deleted_at?->toDateString(),
                'opening_valuation' => $earliestValuation === null ? null : [
                    'id' => $earliestValuation->id,
                    'valued_on' => $earliestValuation->valued_on->toDateString(),
                    'value_cents' => (int) $earliestValuation->value_cents,
                ],
            ],
            'portfolio' => $resolver->present($portfolio),
            'portfolios' => $resolver->presentList($team),
            'asset_types' => WealthAssetType::options(),
            'liquidity_options' => WealthLiquidity::options(),
        ]);
    }

    public function update(
        Request $request,
        WealthAsset $asset,
        AssetValuationService $valuations,
    ): RedirectResponse {
        $this->authorizeTeam('wealth.manage', $request);
        $this->assertTeamAsset($request, $asset);
        $portfolio = $asset->portfolio;
        abort_unless($portfolio !== null, 404);

        $validated = $this->validateAsset($request, $portfolio, forUpdate: true);

        $asset->fill([
            'name' => $validated['name'],
            'owner_name' => $validated['owner_name'],
            'asset_type' => $validated['asset_type'],
            'institution' => $validated['institution'] ?? null,
            'liquidity' => $validated['liquidity'],
            'interest_rate_bps' => $validated['interest_rate_bps'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ])->save();

        if (array_key_exists('opening_value_cents', $validated) && $validated['opening_value_cents'] !== null) {
            $this->syncOpeningValuation(
                $asset,
                $valuations,
                (int) $validated['opening_value_cents'],
                Carbon::parse($validated['opening_valued_on'] ?? now()->toDateString()),
            );
        }

        return redirect()
            ->route('wealth.assets.show', $asset)
            ->with('success', __('Asset updated.'));
    }

    public function destroy(Request $request, WealthAsset $asset): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $this->assertTeamAsset($request, $asset);
        abort_if($asset->trashed(), 404);

        $portfolioId = $asset->portfolio_id;
        $asset->delete();

        return redirect()
            ->route('wealth.index', ['portfolio' => $portfolioId])
            ->with('success', __('Asset archived.'));
    }

    public function restore(Request $request, WealthAsset $asset): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $this->assertTeamAsset($request, $asset);
        abort_unless($asset->trashed(), 404);

        $asset->restore();

        return redirect()
            ->route('wealth.assets.show', $asset)
            ->with('success', __('Asset restored.'));
    }

    public function forceDestroy(Request $request, WealthAsset $asset): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        $this->assertTeamAsset($request, $asset);

        $portfolioId = $asset->portfolio_id;
        $asset->forceDelete();

        return redirect()
            ->route('wealth.index', ['portfolio' => $portfolioId])
            ->with('success', __('Asset deleted permanently.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAsset(Request $request, WealthPortfolio $portfolio, bool $forUpdate = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'asset_type' => ['required', Rule::enum(WealthAssetType::class)],
            'institution' => ['nullable', 'string', 'max:255'],
            'liquidity' => ['required', Rule::enum(WealthLiquidity::class)],
            'interest_rate_bps' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string'],
            'opening_value_cents' => ['nullable', 'integer', 'min:0'],
            'opening_valued_on' => ['nullable', 'date'],
        ];

        if (! $forUpdate) {
            $rules['portfolio_id'] = ['nullable', 'integer'];
        }

        return $request->validate($rules);
    }

    private function syncOpeningValuation(
        WealthAsset $asset,
        AssetValuationService $valuations,
        int $valueCents,
        Carbon $valuedOn,
    ): void {
        $earliest = $asset->valuations()
            ->reorder()
            ->orderBy('valued_on')
            ->orderBy('id')
            ->first();

        if ($earliest === null) {
            $valuations->record($asset, $valuedOn, $valueCents);

            return;
        }

        $newDate = $valuedOn->toDateString();
        if ($earliest->valued_on->toDateString() !== $newDate) {
            $conflict = $asset->valuations()
                ->whereDate('valued_on', $newDate)
                ->whereKeyNot($earliest->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'opening_valued_on' => __('Another valuation already exists on that date. Choose a different opening date, or edit that valuation on the asset page.'),
                ]);
            }
        }

        $valuations->update($earliest, $valuedOn, $valueCents, $earliest->notes);
    }

    private function assertTeamAsset(Request $request, WealthAsset $asset): void
    {
        abort_unless(
            (int) $asset->team_id === (int) $request->user()->current_team_id,
            404
        );
    }
}
