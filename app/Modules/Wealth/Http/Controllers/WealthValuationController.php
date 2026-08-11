<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetValuation;
use App\Modules\Wealth\Services\AssetValuationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WealthValuationController extends Controller
{
    public function store(Request $request, WealthAsset $asset, AssetValuationService $valuations): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        abort_unless((int) $asset->team_id === (int) $request->user()->current_team_id, 404);

        $validated = $request->validate([
            'valued_on' => ['required', 'date'],
            'value_cents' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $valuations->record(
            $asset,
            Carbon::parse($validated['valued_on']),
            (int) $validated['value_cents'],
            $validated['notes'] ?? null,
        );

        return back()->with('success', __('Valuation saved.'));
    }

    public function destroy(Request $request, WealthAsset $asset, WealthAssetValuation $valuation): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        abort_unless((int) $asset->team_id === (int) $request->user()->current_team_id, 404);
        abort_unless((int) $valuation->asset_id === (int) $asset->id, 404);

        $valuation->delete();

        return back()->with('success', __('Valuation removed.'));
    }
}
