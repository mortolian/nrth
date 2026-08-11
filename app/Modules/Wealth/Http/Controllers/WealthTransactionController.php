<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetTransaction;
use App\Modules\Wealth\Services\AssetTransactionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WealthTransactionController extends Controller
{
    public function store(Request $request, WealthAsset $asset, AssetTransactionService $transactions): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        abort_unless((int) $asset->team_id === (int) $request->user()->current_team_id, 404);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(WealthTransactionType::class)],
            'occurred_on' => ['required', 'date'],
            'amount_cents' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        $type = WealthTransactionType::from($validated['type']);
        if ($type !== WealthTransactionType::Adjustment && (int) $validated['amount_cents'] < 0) {
            return back()->with('error', __('Amount must be zero or positive for this type.'));
        }

        $transactions->record(
            $asset,
            $type,
            Carbon::parse($validated['occurred_on']),
            (int) $validated['amount_cents'],
            $validated['notes'] ?? null,
        );

        return back()->with('success', __('Transaction recorded.'));
    }

    public function destroy(Request $request, WealthAsset $asset, WealthAssetTransaction $transaction): RedirectResponse
    {
        $this->authorizeTeam('wealth.manage', $request);
        abort_unless((int) $asset->team_id === (int) $request->user()->current_team_id, 404);
        abort_unless((int) $transaction->asset_id === (int) $asset->id, 404);

        $transaction->delete();

        return back()->with('success', __('Transaction removed.'));
    }
}
