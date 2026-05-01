<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);

        $teamId = (int) $team->id;
        $year = (int) now()->format('Y');
        $sequenceRow = InvoiceNumberSequence::query()
            ->where('team_id', $teamId)
            ->where('year', $year)
            ->first();

        $settings = $team->mergedCompanySettings();
        $nextSeq = $sequenceRow?->next_number ?? 1;

        return Inertia::render('Settings/Company', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'settings' => $settings,
            'logo_url' => $team->getFirstMediaUrl('logo') ?: null,
            'invoice_next_sequence' => $nextSeq,
            'tax_rates' => $this->taxRatesPayload($teamId),
            'industries' => [
                ['value' => 'professional_services', 'label' => 'Professional services'],
                ['value' => 'technology', 'label' => 'Technology / IT'],
                ['value' => 'construction', 'label' => 'Construction'],
                ['value' => 'retail', 'label' => 'Retail'],
                ['value' => 'hospitality', 'label' => 'Hospitality'],
                ['value' => 'agriculture', 'label' => 'Agriculture'],
                ['value' => 'manufacturing', 'label' => 'Manufacturing'],
                ['value' => 'healthcare', 'label' => 'Healthcare'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'financial_year_months' => collect(range(1, 12))->map(fn (int $m): array => [
                'value' => $m,
                'label' => now()->month($m)->format('F'),
            ])->all(),
            'vat_period_types' => [
                ['value' => 'bi_monthly', 'label' => 'Bi-monthly (SARS small vendor)'],
                ['value' => 'monthly', 'label' => 'Monthly'],
                ['value' => 'quarterly', 'label' => 'Quarterly'],
            ],
            'bank_account_types' => [
                ['value' => 'current', 'label' => 'Current'],
                ['value' => 'savings', 'label' => 'Savings'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);

        $teamId = (int) $team->id;

        if ($request->filled('vat_number')) {
            $request->merge([
                'vat_number' => preg_replace('/\D+/', '', (string) $request->input('vat_number')),
            ]);
        }

        if ($request->input('default_tax_rate_id') === '' || $request->input('default_tax_rate_id') === null) {
            $request->merge(['default_tax_rate_id' => null]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'vat_number' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! preg_match('/^4\d{9}$/', (string) $value)) {
                        $fail('The VAT number must be a valid South African VAT number (10 digits starting with 4).');
                    }
                },
            ],
            'tax_reference' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:64'],
            'financial_year_end_month' => ['required', 'integer', Rule::in([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])],
            'physical_street' => ['nullable', 'string', 'max:255'],
            'physical_city' => ['nullable', 'string', 'max:255'],
            'physical_province' => ['nullable', 'string', 'max:255'],
            'physical_postal_code' => ['nullable', 'string', 'max:32'],
            'physical_country' => ['nullable', 'string', 'max:255'],
            'postal_same_as_physical' => ['required', 'boolean'],
            'postal_street' => ['nullable', 'string', 'max:255'],
            'postal_city' => ['nullable', 'string', 'max:255'],
            'postal_province' => ['nullable', 'string', 'max:255'],
            'postal_postal_code' => ['nullable', 'string', 'max:32'],
            'postal_country' => ['nullable', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:64'],
            'company_website' => ['nullable', 'string', 'max:255'],
            'invoice_default_payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'invoice_default_currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'invoice_prefix' => ['required', 'string', 'max:32'],
            'invoice_number_include_month' => ['required', 'boolean'],
            'invoice_number_use_random_suffix' => ['required', 'boolean'],
            'estimate_prefix' => ['required', 'string', 'max:32'],
            'estimate_number_include_month' => ['required', 'boolean'],
            'estimate_number_use_random_suffix' => ['required', 'boolean'],
            'estimate_default_notes' => ['nullable', 'string'],
            'estimate_default_terms' => ['nullable', 'string'],
            'invoice_show_street_address' => ['required', 'boolean'],
            'estimate_show_street_address' => ['required', 'boolean'],
            'invoice_next_sequence' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'invoice_default_notes' => ['nullable', 'string'],
            'invoice_default_footer' => ['nullable', 'string'],
            'invoice_email_subject_template' => ['nullable', 'string', 'max:255'],
            'invoice_email_body_template' => ['nullable', 'string'],
            'vat_registered' => ['required', 'boolean'],
            'vat_period_type' => ['required', Rule::in(['bi_monthly', 'monthly', 'quarterly'])],
            'default_tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('team_id', $teamId)],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_branch_code' => ['nullable', 'string', 'max:32'],
            'bank_account_type' => ['required', Rule::in(['current', 'savings'])],
            'logo' => ['nullable', 'image', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        if (! $validated['vat_registered']) {
            $validated['vat_number'] = null;
        }

        $settingsKeys = [
            'trading_name', 'registration_number', 'vat_number', 'tax_reference', 'industry',
            'financial_year_end_month', 'physical_street', 'physical_city', 'physical_province',
            'physical_postal_code', 'physical_country', 'postal_same_as_physical',
            'postal_street', 'postal_city', 'postal_province', 'postal_postal_code', 'postal_country',
            'company_email', 'company_phone', 'company_website',
            'invoice_default_payment_terms_days', 'invoice_default_currency', 'invoice_prefix',
            'invoice_number_include_month', 'invoice_number_use_random_suffix',
            'estimate_prefix', 'estimate_number_include_month', 'estimate_number_use_random_suffix',
            'estimate_default_notes', 'estimate_default_terms',
            'invoice_show_street_address', 'estimate_show_street_address',
            'invoice_default_notes', 'invoice_default_footer',
            'invoice_email_subject_template', 'invoice_email_body_template',
            'vat_registered', 'vat_period_type', 'default_tax_rate_id',
            'bank_name', 'bank_account_holder', 'bank_account_number', 'bank_branch_code', 'bank_account_type',
        ];

        $newSettings = [];
        foreach ($settingsKeys as $key) {
            if (array_key_exists($key, $validated)) {
                $newSettings[$key] = $validated[$key];
            }
        }

        $team->name = $validated['name'];
        $mergedSettings = array_replace_recursive(
            $team->mergedCompanySettings(),
            $newSettings
        );
        foreach (['quote_prefix', 'quote_number_include_month', 'quote_number_use_random_suffix'] as $legacyKey) {
            unset($mergedSettings[$legacyKey]);
        }
        $team->company_settings = $mergedSettings;
        $team->save();

        if ($request->boolean('remove_logo')) {
            $team->clearMediaCollection('logo');
        }

        if ($request->hasFile('logo')) {
            $team->clearMediaCollection('logo');
            $team->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        if ($request->filled('invoice_next_sequence') && $validated['invoice_next_sequence'] !== null) {
            InvoiceNumberSequence::query()->updateOrCreate(
                [
                    'team_id' => $teamId,
                    'year' => (int) now()->format('Y'),
                ],
                [
                    'next_number' => (int) $validated['invoice_next_sequence'],
                ]
            );
        }

        $tab = (string) $request->input('tab', 'profile');
        if (! in_array($tab, ['profile', 'contact', 'invoice', 'estimate', 'tax', 'banking'], true)) {
            $tab = 'profile';
        }

        return to_route('settings.company', ['tab' => $tab])->with('success', 'Company settings saved.');
    }

    public function storeTaxRate(Request $request): RedirectResponse
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

        return to_route('settings.company', ['tab' => 'tax'])->with('success', 'VAT rate added.');
    }

    public function updateTaxRate(Request $request, TaxRate $taxRate): RedirectResponse
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

        return to_route('settings.company', ['tab' => 'tax'])->with('success', 'VAT rate updated.');
    }

    public function destroyTaxRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless((int) $taxRate->team_id === (int) $team->id, 404);

        $taxRateId = (int) $taxRate->id;
        $taxRate->delete();

        $team->company_settings = array_replace_recursive(
            $team->mergedCompanySettings(),
            ['default_tax_rate_id' => $team->mergedCompanySettings()['default_tax_rate_id'] === $taxRateId ? null : $team->mergedCompanySettings()['default_tax_rate_id']]
        );
        $team->save();

        return to_route('settings.company', ['tab' => 'tax'])->with('success', 'VAT rate removed.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taxRatesPayload(int $teamId): array
    {
        return TaxRate::queryWithoutTeamScope()
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
            ->all();
    }
}
