<?php

namespace App\Http\Controllers\Web\Tax;

use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VatRateController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);

        $teamId = (int) $team->id;

        return Inertia::render('Tax/VAT/Rates', [
            'tax_rates' => TaxRate::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'rate', 'rate_percent', 'is_default', 'is_exempt', 'is_active'])
                ->map(fn (TaxRate $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'code' => $r->code,
                    'rate' => $r->rate !== null ? (float) $r->rate : 0.0,
                    'rate_percent' => $r->rate_percent !== null ? (float) $r->rate_percent : 0.0,
                    'is_default' => (bool) $r->is_default,
                    'is_exempt' => (bool) $r->is_exempt,
                    'is_active' => (bool) $r->is_active,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);

        $teamId = (int) $team->id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', Rule::unique('tax_rates', 'code')->where('team_id', $teamId)],
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_exempt' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $isExempt = (bool) ($validated['is_exempt'] ?? false);
        $ratePercent = $isExempt ? 0.0 : (float) ($validated['rate_percent'] ?? 0);
        $rate = round($ratePercent / 100, 4);

        if ((bool) ($validated['is_default'] ?? false)) {
            TaxRate::queryWithoutTeamScope()->where('team_id', $teamId)->update(['is_default' => false]);
        }

        TaxRate::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            'name' => trim((string) $validated['name']),
            'code' => strtoupper(trim((string) $validated['code'])),
            'rate_percent' => $ratePercent,
            'rate' => $rate,
            'is_exempt' => $isExempt,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        return to_route('tax.vat-rates.index')->with('success', 'VAT rate added.');
    }

    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless((int) $taxRate->team_id === (int) $team->id, 404);

        $teamId = (int) $team->id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', Rule::unique('tax_rates', 'code')->where('team_id', $teamId)->ignore($taxRate->id)],
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_exempt' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $isExempt = (bool) ($validated['is_exempt'] ?? false);
        $ratePercent = $isExempt ? 0.0 : (float) ($validated['rate_percent'] ?? 0);
        $rate = round($ratePercent / 100, 4);
        $isDefault = (bool) ($validated['is_default'] ?? false);

        if ($isDefault) {
            TaxRate::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('id', '!=', $taxRate->id)
                ->update(['is_default' => false]);
        }

        $taxRate->update([
            'name' => trim((string) $validated['name']),
            'code' => strtoupper(trim((string) $validated['code'])),
            'rate_percent' => $ratePercent,
            'rate' => $rate,
            'is_exempt' => $isExempt,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'is_default' => $isDefault,
        ]);

        return to_route('tax.vat-rates.index')->with('success', 'VAT rate updated.');
    }

    public function destroy(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless((int) $taxRate->team_id === (int) $team->id, 404);

        $taxRateId = (int) $taxRate->id;
        $taxRate->delete();

        $settings = $team->mergedCompanySettings();
        if (($settings['default_tax_rate_id'] ?? null) === $taxRateId) {
            $team->company_settings = array_replace_recursive($settings, ['default_tax_rate_id' => null]);
            $team->save();
        }

        return to_route('tax.vat-rates.index')->with('success', 'VAT rate removed.');
    }
}

