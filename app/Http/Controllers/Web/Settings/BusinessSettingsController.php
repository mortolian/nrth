<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Ai\AiCatalog;
use App\Domain\Instance\Services\InstanceTimezoneSettings;
use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamBankAccount;
use App\Support\CalendarMonths;
use App\Support\Iso4217Currencies;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

class BusinessSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorizeTeam('settings.business', $request);
        $team = $request->user()->currentTeam;
        $teamId = (int) $team->id;
        $year = (int) now()->format('Y');
        $sequenceRow = InvoiceNumberSequence::query()
            ->where('team_id', $teamId)
            ->where('year', $year)
            ->first();

        $settings = $team->mergedBusinessSettings();
        $nextSeq = $sequenceRow?->next_number ?? 1;

        $expenseAccounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', AccountType::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'label' => trim($account->code.' — '.$account->name),
            ])
            ->values()
            ->all();

        $incomeAccounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'label' => trim($account->code.' — '.$account->name),
            ])
            ->values()
            ->all();

        return Inertia::render('Settings/Business', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'settings' => $settings,
            'expense_accounts' => $expenseAccounts,
            'income_accounts' => $incomeAccounts,
            'default_fx_loss_account_id' => $team->fxLossAccount()?->id,
            'default_fx_gain_account_id' => $team->fxGainAccount()?->id,
            'bank_accounts' => $team->bankAccounts()->get()->map(fn (TeamBankAccount $b) => [
                'title' => (string) ($b->title ?? ''),
                'bank_name' => (string) ($b->bank_name ?? ''),
                'bank_account_holder' => (string) ($b->bank_account_holder ?? ''),
                'bank_account_number' => (string) ($b->bank_account_number ?? ''),
                'swift_code' => (string) ($b->swift_code ?? ''),
                'bic' => (string) ($b->bic ?? ''),
                'iban' => (string) ($b->iban ?? ''),
                'routing_sort_code' => (string) ($b->routing_sort_code ?? ''),
                'bank_address' => (string) ($b->bank_address ?? ''),
                'bank_branch_code' => (string) ($b->bank_branch_code ?? ''),
                'bank_account_type' => (string) ($b->bank_account_type ?? 'current'),
                'show_on_invoice' => (bool) $b->show_on_invoice,
            ])->values()->all(),
            'logo_url' => $team->getFirstMedia('logo')?->getUrl() ?: null,
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
            'financial_year_months' => CalendarMonths::options(),
            'timezone_options' => Timezones::selectOptions(
                is_string($settings['timezone'] ?? null) ? (string) $settings['timezone'] : null
            ),
            'instance_timezone' => app(InstanceTimezoneSettings::class)->resolved(),
            'vat_period_types' => [
                ['value' => 'bi_monthly', 'label' => 'Bi-monthly'],
                ['value' => 'monthly', 'label' => 'Monthly'],
                ['value' => 'quarterly', 'label' => 'Quarterly'],
            ],
            'bank_account_types' => [
                ['value' => 'current', 'label' => 'Current'],
                ['value' => 'savings', 'label' => 'Savings'],
            ],
            'ai_providers' => AiCatalog::providerOptions(),
            'ai_models_by_provider' => collect(AiCatalog::modelsByProvider())
                ->map(fn (array $models): array => collect($models)->map(fn (string $model): array => [
                    'value' => $model,
                    'label' => $model,
                ])->all())
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        $team = $request->user()->currentTeam;
        $teamId = (int) $team->id;

        if ($request->filled('vat_number')) {
            $request->merge([
                'vat_number' => preg_replace('/\D+/', '', (string) $request->input('vat_number')),
            ]);
        }

        if ($request->input('default_vat_rate') === '' || $request->input('default_vat_rate') === null) {
            $request->merge(['default_vat_rate' => 0]);
        }

        if ($request->input('timezone') === '') {
            $request->merge(['timezone' => null]);
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
            'timezone' => ['nullable', 'timezone:all'],
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
            'business_email' => ['nullable', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:64', (new Phone)->international()],
            'business_website' => ['nullable', 'string', 'max:255'],
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
            'fx_loss_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(function ($query) use ($teamId): void {
                    $query->where('team_id', $teamId)
                        ->where('type', AccountType::Expense->value)
                        ->where('is_active', true);
                }),
            ],
            'fx_gain_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(function ($query) use ($teamId): void {
                    $query->where('team_id', $teamId)
                        ->where('type', AccountType::Income->value)
                        ->where('is_active', true);
                }),
            ],
            'vat_registered' => ['required', 'boolean'],
            'vat_period_type' => ['required', Rule::in(['bi_monthly', 'monthly', 'quarterly'])],
            'default_vat_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'payment_pages_enabled' => ['required', 'boolean'],
            'session_idle_timeout_minutes' => [
                'sometimes',
                'integer',
                'min:0',
                'max:'.(int) config('session.lifetime'),
            ],
            'ai' => ['required', 'array'],
            'ai.enabled' => ['required', 'boolean'],
            'ai.provider' => ['required', 'string', Rule::in(AiCatalog::providers())],
            'ai.api_key' => [
                Rule::requiredIf(fn () => $request->boolean('ai.enabled')
                    && ! AiCatalog::apiKeyOptional((string) $request->input('ai.provider', ''))),
                'nullable',
                'string',
                'max:255',
            ],
            'ai.base_url' => ['nullable', 'string', 'max:255'],
            'ai.model' => [
                'required',
                'string',
                'max:128',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $provider = (string) $request->input('ai.provider', '');
                    if (! is_string($value) || ! AiCatalog::isValidModel($provider, $value)) {
                        $fail('The selected AI model is invalid for the chosen provider.');
                    }
                },
            ],
            'payment_gateways' => ['required', 'array'],
            'payment_gateways.payfast' => ['required', 'array'],
            'payment_gateways.payfast.enabled' => ['required', 'boolean'],
            'payment_gateways.payfast.merchant_id' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.payfast.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.payfast.merchant_key' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.payfast.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.payfast.passphrase' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.payfast.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.stripe' => ['required', 'array'],
            'payment_gateways.stripe.enabled' => ['required', 'boolean'],
            'payment_gateways.stripe.publishable_key' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.stripe.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.stripe.secret_key' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.stripe.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.stripe.webhook_secret' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.stripe.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.paypal' => ['required', 'array'],
            'payment_gateways.paypal.enabled' => ['required', 'boolean'],
            'payment_gateways.paypal.client_id' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.paypal.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.paypal.client_secret' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.paypal.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.paypal.environment' => ['required', Rule::in(['sandbox', 'live'])],
            'payment_gateways.netcash' => ['required', 'array'],
            'payment_gateways.netcash.enabled' => ['required', 'boolean'],
            'payment_gateways.netcash.account_id' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.netcash.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.netcash.service_key' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.netcash.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.snapscan' => ['required', 'array'],
            'payment_gateways.snapscan.enabled' => ['required', 'boolean'],
            'payment_gateways.snapscan.merchant_id' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.snapscan.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.snapscan.api_key' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.snapscan.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.zapper' => ['required', 'array'],
            'payment_gateways.zapper.enabled' => ['required', 'boolean'],
            'payment_gateways.zapper.merchant_id' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.zapper.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'payment_gateways.zapper.api_key' => [
                Rule::requiredIf(fn () => $request->boolean('payment_gateways.zapper.enabled')),
                'nullable',
                'string',
                'max:255',
            ],
            'bank_accounts' => ['required', 'array', 'max:50'],
            'bank_accounts.*.bank_name' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.bank_account_holder' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_accounts.*.swift_code' => ['nullable', 'string', 'max:32'],
            'bank_accounts.*.bic' => ['nullable', 'string', 'max:32'],
            'bank_accounts.*.iban' => ['nullable', 'string', 'max:64'],
            'bank_accounts.*.routing_sort_code' => ['nullable', 'string', 'max:64'],
            'bank_accounts.*.bank_address' => ['nullable', 'string', 'max:1000'],
            'bank_accounts.*.bank_branch_code' => ['nullable', 'string', 'max:32'],
            'bank_accounts.*.bank_account_type' => ['nullable', Rule::in(['current', 'savings'])],
            'bank_accounts.*.title' => ['nullable', 'string', 'max:128'],
            'bank_accounts.*.show_on_invoice' => ['required', 'boolean'],
            'item_units' => ['sometimes', 'array', 'max:50'],
            'item_units.*' => ['nullable', 'string', 'max:32'],
            'logo' => ['nullable', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        if (! $validated['vat_registered']) {
            $validated['vat_number'] = null;
            $validated['default_vat_rate'] = 0.0;
        } else {
            $validated['default_vat_rate'] = round((float) $validated['default_vat_rate'], 4);
        }

        // Free-form default replaces the legacy tax-rate-id pointer.
        $validated['default_tax_rate_id'] = null;

        foreach (['fx_loss_account_id', 'fx_gain_account_id'] as $fxKey) {
            if (! array_key_exists($fxKey, $validated) || $validated[$fxKey] === '' || (int) $validated[$fxKey] === 0) {
                $validated[$fxKey] = null;
            } else {
                $validated[$fxKey] = (int) $validated[$fxKey];
            }
        }

        $validated['payment_pages_enabled'] = (bool) $validated['payment_pages_enabled'];
        foreach (['payfast', 'stripe', 'paypal', 'netcash', 'snapscan', 'zapper'] as $gateway) {
            if (! isset($validated['payment_gateways'][$gateway]) || ! is_array($validated['payment_gateways'][$gateway])) {
                continue;
            }
            $validated['payment_gateways'][$gateway]['enabled'] = (bool) ($validated['payment_gateways'][$gateway]['enabled'] ?? false);
        }

        if (! empty($validated['business_phone'])) {
            $validated['business_phone'] = (new PhoneNumber((string) $validated['business_phone']))->formatE164();
        } else {
            $validated['business_phone'] = null;
        }

        $settingsKeys = [
            'trading_name', 'registration_number', 'vat_number', 'tax_reference', 'industry',
            'financial_year_end_month', 'timezone', 'physical_street', 'physical_city', 'physical_province',
            'physical_postal_code', 'physical_country', 'postal_same_as_physical',
            'postal_street', 'postal_city', 'postal_province', 'postal_postal_code', 'postal_country',
            'business_email', 'business_phone', 'business_website',
            'invoice_default_payment_terms_days', 'invoice_default_currency', 'invoice_prefix',
            'invoice_number_include_month', 'invoice_number_use_random_suffix',
            'estimate_prefix', 'estimate_number_include_month', 'estimate_number_use_random_suffix',
            'estimate_default_notes', 'estimate_default_terms',
            'invoice_show_street_address', 'estimate_show_street_address',
            'invoice_default_notes', 'invoice_default_footer',
            'invoice_email_subject_template', 'invoice_email_body_template',
            'fx_loss_account_id', 'fx_gain_account_id',
            'vat_registered', 'vat_period_type', 'default_vat_rate', 'default_tax_rate_id',
            'payment_pages_enabled',
            'session_idle_timeout_minutes',
            'ai',
            'payment_gateways',
        ];

        $newSettings = [];
        foreach ($settingsKeys as $key) {
            if (array_key_exists($key, $validated)) {
                $newSettings[$key] = $validated[$key];
            }
        }

        if (array_key_exists('item_units', $validated)) {
            $units = Team::normalizeItemUnits($validated['item_units']);
            $newSettings['item_units'] = $units !== [] ? $units : Team::defaultItemUnits();
        }

        if (isset($newSettings['ai']['api_key'])) {
            $key = trim((string) $newSettings['ai']['api_key']);
            $newSettings['ai']['api_key'] = $key !== '' ? $key : null;
        }

        $newSettings['ai']['enabled'] = (bool) ($newSettings['ai']['enabled'] ?? false);

        $aiProvider = (string) ($newSettings['ai']['provider'] ?? '');
        $baseUrl = trim((string) ($newSettings['ai']['base_url'] ?? ''));
        if ($baseUrl === '' && AiCatalog::defaultBaseUrl($aiProvider)) {
            $baseUrl = (string) AiCatalog::defaultBaseUrl($aiProvider);
        }
        if (
            $newSettings['ai']['enabled']
            && $aiProvider === AiCatalog::PROVIDER_OPENAI_COMPATIBLE
            && $baseUrl === ''
        ) {
            throw ValidationException::withMessages([
                'ai.base_url' => 'A base URL is required for OpenAI-compatible providers.',
            ]);
        }
        if (! AiCatalog::showsBaseUrl($aiProvider)) {
            $baseUrl = '';
        }
        $newSettings['ai']['base_url'] = $baseUrl !== '' ? rtrim($baseUrl, '/') : null;

        $newSettings = array_merge($newSettings, [
            'bank_name' => null,
            'bank_account_holder' => null,
            'bank_account_number' => null,
            'bank_branch_code' => null,
            'bank_account_type' => null,
        ]);

        $this->syncTeamBankAccounts($team, $validated['bank_accounts']);

        $team->name = $validated['name'];
        $mergedSettings = array_replace_recursive(
            $team->mergedBusinessSettings(),
            $newSettings
        );
        // Replace list wholesale after recursive merge.
        if (array_key_exists('item_units', $newSettings)) {
            $mergedSettings['item_units'] = $newSettings['item_units'];
        }
        foreach (['quote_prefix', 'quote_number_include_month', 'quote_number_use_random_suffix', 'receipt_scan'] as $legacyKey) {
            unset($mergedSettings[$legacyKey]);
        }
        $team->business_settings = $mergedSettings;
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
        if (! in_array($tab, ['profile', 'contact', 'invoice', 'estimate', 'tax', 'banking', 'items', 'payment_pages', 'ai'], true)) {
            $tab = 'profile';
        }

        return to_route('settings.business', ['tab' => $tab])->with('success', 'Business settings saved.');
    }

    public function storeTaxRate(Request $request): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        $team = $request->user()->currentTeam;
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

        return to_route('settings.business', ['tab' => 'tax'])->with('success', 'VAT rate added.');
    }

    public function updateTaxRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        $team = $request->user()->currentTeam;
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

        return to_route('settings.business', ['tab' => 'tax'])->with('success', 'VAT rate updated.');
    }

    public function destroyTaxRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        $team = $request->user()->currentTeam;
        abort_unless((int) $taxRate->team_id === (int) $team->id, 404);

        $taxRate->delete();

        return to_route('settings.business', ['tab' => 'tax'])->with('success', 'VAT rate removed.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncTeamBankAccounts(Team $team, array $rows): void
    {
        $teamId = (int) $team->id;
        TeamBankAccount::query()->where('team_id', $teamId)->delete();

        $sortOrder = 0;
        foreach ($rows as $row) {
            $hasDetail = filled($row['bank_name'] ?? null)
                || filled($row['bank_account_holder'] ?? null)
                || filled($row['bank_account_number'] ?? null)
                || filled($row['swift_code'] ?? null)
                || filled($row['bic'] ?? null)
                || filled($row['iban'] ?? null)
                || filled($row['routing_sort_code'] ?? null)
                || filled($row['bank_address'] ?? null)
                || filled($row['bank_branch_code'] ?? null)
                || filled($row['title'] ?? null);
            if (! $hasDetail) {
                continue;
            }

            $type = $row['bank_account_type'] ?? 'current';
            if (! in_array($type, ['current', 'savings'], true)) {
                $type = 'current';
            }

            TeamBankAccount::query()->create([
                'team_id' => $teamId,
                'sort_order' => $sortOrder++,
                'title' => filled($row['title'] ?? null) ? (string) $row['title'] : null,
                'bank_name' => filled($row['bank_name'] ?? null) ? (string) $row['bank_name'] : null,
                'bank_account_holder' => filled($row['bank_account_holder'] ?? null) ? (string) $row['bank_account_holder'] : null,
                'bank_account_number' => filled($row['bank_account_number'] ?? null) ? (string) $row['bank_account_number'] : null,
                'swift_code' => filled($row['swift_code'] ?? null) ? (string) $row['swift_code'] : null,
                'bic' => filled($row['bic'] ?? null) ? (string) $row['bic'] : null,
                'iban' => filled($row['iban'] ?? null) ? (string) $row['iban'] : null,
                'routing_sort_code' => filled($row['routing_sort_code'] ?? null) ? (string) $row['routing_sort_code'] : null,
                'bank_address' => filled($row['bank_address'] ?? null) ? (string) $row['bank_address'] : null,
                'bank_branch_code' => filled($row['bank_branch_code'] ?? null) ? (string) $row['bank_branch_code'] : null,
                'bank_account_type' => $type,
                'show_on_invoice' => filter_var($row['show_on_invoice'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
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
