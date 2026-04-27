<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
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
            'tax_rates' => TaxRate::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'rate'])
                ->map(fn (TaxRate $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'rate' => $r->rate !== null ? (float) $r->rate : 0.0,
                ])
                ->all(),
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
            'invoice_prefix' => ['required', 'string', 'max:32'],
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
            'invoice_default_payment_terms_days', 'invoice_prefix',
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
        $team->company_settings = array_replace_recursive(
            $team->mergedCompanySettings(),
            $newSettings
        );
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
        if (! in_array($tab, ['profile', 'contact', 'invoice', 'tax', 'banking'], true)) {
            $tab = 'profile';
        }

        return to_route('settings.company', ['tab' => $tab])->with('success', 'Company settings saved.');
    }
}
