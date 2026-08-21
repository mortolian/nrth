<?php

namespace App\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Ai\AiCatalog;
use App\Domain\Instance\Services\InstanceTimezoneSettings;
use App\Domain\Tax\Models\TaxRate;
use App\Support\Modules\ModuleCatalog;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\Timezones;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Team extends JetstreamTeam implements HasMedia
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'business_settings',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'logo_url',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    protected static function booted(): void
    {
        static::created(function (Team $team): void {
            EnsureTeamSystemRoles::ensureFor($team);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'business_settings' => 'array',
        ];
    }

    /**
     * @return HasMany<TeamRole, $this>
     */
    public function teamRoles(): HasMany
    {
        return $this->hasMany(TeamRole::class)->orderBy('is_system', 'desc')->orderBy('name');
    }

    /**
     * @return HasMany<TeamModule, $this>
     */
    public function teamModules(): HasMany
    {
        return $this->hasMany(TeamModule::class);
    }

    public function moduleEnabled(string $name): bool
    {
        if (! ModuleCatalog::isValid($name)) {
            return false;
        }

        if (! Schema::hasTable('team_modules')) {
            return ModuleCatalog::defaultEnabled($name);
        }

        $row = $this->teamModules()->where('name', $name)->first();

        if ($row === null) {
            return ModuleCatalog::defaultEnabled($name);
        }

        return (bool) $row->enabled;
    }

    /**
     * @return list<string>
     */
    public function enabledModules(): array
    {
        $enabled = [];

        foreach (ModuleCatalog::keys() as $name) {
            if ($this->moduleEnabled($name)) {
                $enabled[] = $name;
            }
        }

        return $enabled;
    }

    public function setModuleEnabled(string $name, bool $enabled): void
    {
        if (! ModuleCatalog::isValid($name)) {
            return;
        }

        if (! Schema::hasTable('team_modules')) {
            throw new \RuntimeException(
                'The team_modules table is missing. Run `php artisan migrate` (or `./scripts/compose.sh exec -T app php artisan migrate`) to apply pending migrations.'
            );
        }

        $this->teamModules()->updateOrCreate(
            ['name' => $name],
            ['enabled' => $enabled],
        );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->useDisk('public')->singleFile();
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->getFirstMedia('logo')?->getUrl() ?: null;
    }

    /**
     * @return HasMany<TeamBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(TeamBankAccount::class)->orderBy('sort_order');
    }

    /**
     * Banking rows to print on invoice PDFs (show_on_invoice + any detail present).
     *
     * @return list<array{title: string|null, name: string|null, holder: string|null, account: string|null, swift_code: string|null, bic: string|null, iban: string|null, routing_sort_code: string|null, address: string|null, branch: string|null, type: string|null}>
     */
    public function bankAccountsForInvoicePdf(): array
    {
        $rows = $this->bankAccounts()->get();
        if ($rows->isNotEmpty()) {
            return $rows
                ->filter(fn (TeamBankAccount $b) => $b->show_on_invoice)
                ->map(function (TeamBankAccount $b): array {
                    return [
                        'title' => $b->title,
                        'name' => $b->bank_name,
                        'holder' => $b->bank_account_holder,
                        'account' => $b->bank_account_number,
                        'swift_code' => $b->swift_code,
                        'bic' => $b->bic,
                        'iban' => $b->iban,
                        'routing_sort_code' => $b->routing_sort_code,
                        'address' => $b->bank_address,
                        'branch' => $b->bank_branch_code,
                        'type' => $b->bank_account_type,
                    ];
                })
                ->filter(function (array $row): bool {
                    return collect($row)->filter(function (mixed $v): bool {
                        if ($v === null || $v === '') {
                            return false;
                        }

                        return true;
                    })->isNotEmpty();
                })
                ->values()
                ->all();
        }

        $settings = $this->mergedBusinessSettings();
        $bank = [
            'title' => null,
            'name' => $settings['bank_name'] ?? null,
            'holder' => $settings['bank_account_holder'] ?? null,
            'account' => $settings['bank_account_number'] ?? null,
            'swift_code' => null,
            'bic' => null,
            'iban' => null,
            'routing_sort_code' => null,
            'address' => null,
            'branch' => $settings['bank_branch_code'] ?? null,
            'type' => $settings['bank_account_type'] ?? null,
        ];
        if (collect($bank)->filter(fn (?string $v) => $v !== null && $v !== '')->isEmpty()) {
            return [];
        }

        return [$bank];
    }

    /**
     * Data URI for embedding the team logo in DomPDF (HTTP URLs are often unreliable: relative paths, localhost, SSL).
     */
    public function logoDataUriForPdf(): ?string
    {
        $media = $this->getFirstMedia('logo');
        if ($media === null) {
            return null;
        }

        $binary = null;
        $path = $media->getPath();
        if ($path !== '' && @is_readable($path)) {
            $binary = @file_get_contents($path);
        }

        if ($binary === false || $binary === null || $binary === '') {
            $relative = $media->getPathRelativeToRoot();
            if ($relative !== '') {
                $disk = Storage::disk($media->disk);
                if ($disk->exists($relative)) {
                    $binary = $disk->get($relative);
                }
            }
        }

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $mime = $media->mime_type;
        if ($mime === null || $mime === '' || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultBusinessSettings(): array
    {
        return [
            'trading_name' => null,
            'registration_number' => null,
            'vat_number' => null,
            'tax_reference' => null,
            'industry' => null,
            'financial_year_end_month' => 2,
            /** null = use instance default timezone */
            'timezone' => null,
            'physical_street' => null,
            'physical_city' => null,
            'physical_province' => null,
            'physical_postal_code' => null,
            'physical_country' => 'South Africa',
            'postal_same_as_physical' => true,
            'postal_street' => null,
            'postal_city' => null,
            'postal_province' => null,
            'postal_postal_code' => null,
            'postal_country' => null,
            'business_email' => null,
            'business_phone' => null,
            'business_website' => null,
            'invoice_default_payment_terms_days' => 30,
            'invoice_default_currency' => 'ZAR',
            'invoice_prefix' => 'INV',
            'invoice_number_include_month' => false,
            'invoice_number_use_random_suffix' => false,
            'estimate_prefix' => 'EST',
            'estimate_number_include_month' => false,
            'estimate_number_use_random_suffix' => false,
            'estimate_default_notes' => null,
            'estimate_default_terms' => '50% deposit on acceptance. Balance due on delivery.',
            'estimate_show_street_address' => true,
            'invoice_default_notes' => null,
            'invoice_default_footer' => null,
            'invoice_show_street_address' => true,
            /** Expense account for realised FX losses on foreign invoice payments; null = chart code 5900. */
            'fx_loss_account_id' => null,
            /** Income account for realised FX gains on foreign invoice payments; null = chart code 4950. */
            'fx_gain_account_id' => null,
            'invoice_email_subject_template' => 'Invoice {{number}} from {{business}}',
            'invoice_email_body_template' => "Hi {{client_name}},\n\nPlease find invoice {{number}} attached.\n\nThank you,\n{{business}}",
            'vat_registered' => false,
            'vat_period_type' => 'bi_monthly',
            /** Decimal 0–1; 0 = zero-rated. Free-form (not tied to a tax_rates row). */
            'default_vat_rate' => 0.0,
            /** @deprecated Prefer default_vat_rate; kept for reading legacy settings. */
            'default_tax_rate_id' => null,
            /** Labels offered when picking a unit on catalog items. */
            'item_units' => self::defaultItemUnits(),
            /** Master switch: hosted checkout on invoice + public pay page (Stripe, PayFast, …). */
            'payment_pages_enabled' => false,
            /** 0 = off (Laravel SESSION_LIFETIME only). Cap at config('session.lifetime'). */
            'session_idle_timeout_minutes' => 0,
            /** Optional AI provider (expenses, documents, …). Env keys are per-provider fallback. */
            'ai' => [
                'enabled' => false,
                'provider' => 'openai',
                'api_key' => null,
                'model' => 'gpt-4o-mini',
                'base_url' => null,
            ],
            'bank_name' => null,
            'bank_account_holder' => null,
            'bank_account_number' => null,
            'bank_branch_code' => null,
            'bank_account_type' => 'current',
            'payment_gateways' => [
                'payfast' => [
                    'enabled' => false,
                    'merchant_id' => null,
                    'merchant_key' => null,
                    'passphrase' => null,
                ],
                'stripe' => [
                    'enabled' => false,
                    'publishable_key' => null,
                    'secret_key' => null,
                    'webhook_secret' => null,
                ],
                'paypal' => [
                    'enabled' => false,
                    'client_id' => null,
                    'client_secret' => null,
                    'environment' => 'sandbox',
                ],
                'netcash' => [
                    'enabled' => false,
                    'account_id' => null,
                    'service_key' => null,
                ],
                'snapscan' => [
                    'enabled' => false,
                    'merchant_id' => null,
                    'api_key' => null,
                ],
                'zapper' => [
                    'enabled' => false,
                    'merchant_id' => null,
                    'api_key' => null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedBusinessSettings(): array
    {
        $stored = is_array($this->business_settings) ? $this->business_settings : [];
        $normalized = $stored;

        foreach ([
            'quote_prefix' => 'estimate_prefix',
            'quote_number_include_month' => 'estimate_number_include_month',
            'quote_number_use_random_suffix' => 'estimate_number_use_random_suffix',
        ] as $legacyKey => $key) {
            if (array_key_exists($legacyKey, $normalized) && ! array_key_exists($key, $normalized)) {
                $normalized[$key] = $normalized[$legacyKey];
            }
        }

        if (! array_key_exists('estimate_show_street_address', $normalized) && array_key_exists('invoice_show_street_address', $normalized)) {
            $normalized['estimate_show_street_address'] = $normalized['invoice_show_street_address'];
        }

        // Legacy company_* contact keys → business_*.
        foreach (['email', 'phone', 'website'] as $suffix) {
            $legacy = 'company_'.$suffix;
            $key = 'business_'.$suffix;
            if (array_key_exists($legacy, $normalized) && ! array_key_exists($key, $normalized)) {
                $normalized[$key] = $normalized[$legacy];
            }
            unset($normalized[$legacy]);
        }

        // Legacy key from early receipt-scan settings.
        if (array_key_exists('receipt_scan', $normalized) && ! array_key_exists('ai', $normalized)) {
            $normalized['ai'] = $normalized['receipt_scan'];
        }
        unset($normalized['receipt_scan']);

        // Legacy standalone Ollama provider → OpenAI-compatible.
        if (is_array($normalized['ai'] ?? null) && ($normalized['ai']['provider'] ?? null) === 'ollama') {
            $normalized['ai']['provider'] = 'openai_compatible';
            if (trim((string) ($normalized['ai']['base_url'] ?? '')) === '') {
                $normalized['ai']['base_url'] = 'http://127.0.0.1:11434/v1';
            }
        }

        $merged = array_replace_recursive(
            self::defaultBusinessSettings(),
            $normalized
        );

        // Indexed lists must replace wholesale — recursive merge keeps leftover default indices.
        if (array_key_exists('item_units', $normalized)) {
            $merged['item_units'] = self::normalizeItemUnits($normalized['item_units']);
        } else {
            $merged['item_units'] = self::defaultItemUnits();
        }

        // New companies start VAT-off; normalize legacy string/int flags to a real boolean.
        $merged['vat_registered'] = filter_var($merged['vat_registered'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $merged['default_vat_rate'] = $this->resolveDefaultVatRateFromSettings($stored, $merged);
        $merged['payment_pages_enabled'] = filter_var($merged['payment_pages_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $merged['payment_gateways'] = $this->normalizePaymentGatewayFlags(
            is_array($merged['payment_gateways'] ?? null) ? $merged['payment_gateways'] : []
        );
        $merged['ai'] = $this->normalizeAiSettings(
            is_array($stored['ai'] ?? null) ? $stored['ai'] : [],
            is_array($merged['ai'] ?? null) ? $merged['ai'] : []
        );

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $storedAi
     * @param  array<string, mixed>  $mergedAi
     * @return array<string, mixed>
     */
    private function normalizeAiSettings(array $storedAi, array $mergedAi): array
    {
        if (array_key_exists('enabled', $storedAi)) {
            $mergedAi['enabled'] = filter_var($storedAi['enabled'], FILTER_VALIDATE_BOOLEAN);
        } else {
            // Legacy teams had no master switch — treat as on when credentials were already configured.
            $mergedAi['enabled'] = $this->aiCredentialsConfigured($mergedAi);
        }

        return $mergedAi;
    }

    /**
     * @param  array<string, mixed>  $ai
     */
    private function aiCredentialsConfigured(array $ai): bool
    {
        $provider = trim((string) ($ai['provider'] ?? ''));
        if ($provider === '' || ! AiCatalog::isValidProvider($provider)) {
            $provider = AiCatalog::PROVIDER_OPENAI;
        }

        $apiKey = trim((string) ($ai['api_key'] ?? ''));
        $baseUrl = trim((string) ($ai['base_url'] ?? ''));

        if (AiCatalog::apiKeyOptional($provider)) {
            return $baseUrl !== '';
        }

        return $apiKey !== '';
    }

    /**
     * Ensure each gateway's enabled flag is a real boolean (default off).
     *
     * @param  array<string, mixed>  $gateways
     * @return array<string, mixed>
     */
    private function normalizePaymentGatewayFlags(array $gateways): array
    {
        foreach (['payfast', 'stripe', 'paypal', 'netcash', 'snapscan', 'zapper'] as $key) {
            if (! is_array($gateways[$key] ?? null)) {
                continue;
            }
            $gateways[$key]['enabled'] = filter_var($gateways[$key]['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $gateways;
    }

    /**
     * Decimal 0–1 default VAT for new lines. Prefer stored default_vat_rate; else legacy tax rate id; else 0.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $merged
     */
    private function resolveDefaultVatRateFromSettings(array $stored, array $merged): float
    {
        if (array_key_exists('default_vat_rate', $stored) && $stored['default_vat_rate'] !== null && $stored['default_vat_rate'] !== '') {
            return max(0.0, min(1.0, round((float) $stored['default_vat_rate'], 4)));
        }

        $taxRateId = (int) ($stored['default_tax_rate_id'] ?? 0);
        if ($taxRateId > 0 && $this->id) {
            $rate = TaxRate::queryWithoutTeamScope()
                ->where('team_id', $this->id)
                ->whereKey($taxRateId)
                ->value('rate');
            if ($rate !== null) {
                return max(0.0, min(1.0, (float) $rate));
            }
        }

        return max(0.0, min(1.0, (float) ($merged['default_vat_rate'] ?? 0)));
    }

    /**
     * Default unit labels for the items catalog picker.
     *
     * @return list<string>
     */
    public static function defaultItemUnits(): array
    {
        return [
            'each',
            'hour',
            'day',
            'week',
            'month',
            'year',
            'kg',
            'g',
            'L',
            'm',
            'm²',
            'km',
            'box',
            'pack',
            'set',
            'service',
        ];
    }

    /**
     * @return list<string>
     */
    public static function normalizeItemUnits(mixed $units): array
    {
        if (! is_array($units)) {
            return self::defaultItemUnits();
        }

        $seen = [];
        $out = [];

        foreach ($units as $unit) {
            $label = trim((string) $unit);
            if ($label === '' || mb_strlen($label) > 32) {
                continue;
            }

            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $label;
        }

        return array_values($out);
    }

    /**
     * Item unit labels for this team (catalog settings), never empty of defaults unless explicitly cleared.
     *
     * @return list<string>
     */
    public function itemUnits(): array
    {
        $settings = $this->mergedBusinessSettings();
        $units = self::normalizeItemUnits($settings['item_units'] ?? null);

        return $units !== [] ? $units : self::defaultItemUnits();
    }

    /**
     * Substitute invoice email template placeholders.
     * Primary token is {{business}}; {{company}} is accepted as a legacy alias.
     *
     * @param  array{number?: string, business?: string, company?: string, client_name?: string}  $vars
     */
    public static function renderInvoiceEmailTemplate(string $template, array $vars): string
    {
        $business = (string) ($vars['business'] ?? $vars['company'] ?? '');
        $replacements = [
            '{{number}}' => (string) ($vars['number'] ?? ''),
            '{{client_name}}' => (string) ($vars['client_name'] ?? ''),
            '{{business}}' => $business,
            '{{company}}' => $business,
        ];

        return strtr($template, $replacements);
    }

    /**
     * Issuer / "from" block for invoices, estimates, previews, PDFs, and client-facing emails.
     *
     * @return array{
     *     name: string,
     *     address: string|null,
     *     address_lines: list<string>,
     *     email: string|null,
     *     phone: string|null,
     *     website: string|null,
     *     registration_number: string|null,
     *     vat_number: string|null,
     * }
     */
    public function issuerForInvoicingDocuments(string $documentType = 'invoice'): array
    {
        $settings = $this->mergedBusinessSettings();

        $trading = trim((string) ($settings['trading_name'] ?? ''));
        $name = $trading !== '' ? $trading : (string) $this->name;

        $showStreetSetting = $documentType === 'estimate' ? 'estimate_show_street_address' : 'invoice_show_street_address';
        $showStreet = (bool) ($settings[$showStreetSetting] ?? true);
        $addressLines = $this->physicalAddressLines($settings, $showStreet);
        $address = $addressLines !== [] ? implode("\n", $addressLines) : null;

        $nullIfEmpty = static function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }

            return (string) $v;
        };

        return [
            'name' => $name,
            'address' => $address,
            'address_lines' => $addressLines,
            'email' => $nullIfEmpty($settings['business_email'] ?? null),
            'phone' => $nullIfEmpty($settings['business_phone'] ?? null),
            'website' => $nullIfEmpty($settings['business_website'] ?? null),
            'registration_number' => $nullIfEmpty($settings['registration_number'] ?? null),
            'vat_number' => $nullIfEmpty($settings['vat_number'] ?? null),
        ];
    }

    /**
     * Format the team physical address as postal-style lines for invoices/PDFs.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function physicalAddressLines(array $settings, bool $includeStreet): array
    {
        $trim = static fn (mixed $value): string => trim((string) ($value ?? ''));

        $street = $includeStreet ? $trim($settings['physical_street'] ?? null) : '';
        $city = $trim($settings['physical_city'] ?? null);
        $province = $trim($settings['physical_province'] ?? null);
        $postalCode = $trim($settings['physical_postal_code'] ?? null);
        $country = $trim($settings['physical_country'] ?? null);

        $lines = [];

        if ($street !== '') {
            $lines[] = $street;
        }

        // e.g. "Cape Town, Western Cape 8001"
        $localityParts = array_values(array_filter([$city, $province], static fn (string $part): bool => $part !== ''));
        $locality = implode(', ', $localityParts);
        if ($postalCode !== '') {
            $locality = $locality !== '' ? "{$locality} {$postalCode}" : $postalCode;
        }
        if ($locality !== '') {
            $lines[] = $locality;
        }

        if ($country !== '') {
            $lines[] = $country;
        }

        return $lines;
    }

    /**
     * Whether invoices/estimates may apply VAT (including 0% zero-rated): VAT-registered in settings.
     */
    public function chargesVat(): bool
    {
        return filter_var($this->mergedBusinessSettings()['vat_registered'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Expense account used when booking a foreign-exchange loss on invoice payment.
     * Preference: explicit override → business setting → system chart code 5900.
     */
    public function fxLossAccount(?int $overrideId = null): ?Account
    {
        return $this->resolveConfiguredChartAccount(
            overrideId: $overrideId,
            settingKey: 'fx_loss_account_id',
            type: AccountType::Expense,
            fallbackCode: '5900',
        );
    }

    /**
     * Income account used when booking a foreign-exchange gain on invoice payment.
     * Preference: explicit override → business setting → system chart code 4950.
     */
    public function fxGainAccount(?int $overrideId = null): ?Account
    {
        return $this->resolveConfiguredChartAccount(
            overrideId: $overrideId,
            settingKey: 'fx_gain_account_id',
            type: AccountType::Income,
            fallbackCode: '4950',
        );
    }

    private function resolveConfiguredChartAccount(
        ?int $overrideId,
        string $settingKey,
        AccountType $type,
        string $fallbackCode,
    ): ?Account {
        if (! Schema::hasTable('accounts')) {
            return null;
        }

        $teamId = (int) $this->id;
        $candidates = [];

        if ($overrideId !== null && $overrideId > 0) {
            $candidates[] = $overrideId;
        }

        $configured = (int) ($this->mergedBusinessSettings()[$settingKey] ?? 0);
        if ($configured > 0) {
            $candidates[] = $configured;
        }

        foreach (array_values(array_unique($candidates)) as $accountId) {
            $account = Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('id', $accountId)
                ->where('type', $type->value)
                ->where('is_active', true)
                ->first();
            if ($account !== null) {
                return $account;
            }
        }

        return Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', $fallbackCode)
            ->where('type', $type->value)
            ->first();
    }

    /**
     * Effective IANA timezone for this business (own setting, else instance default).
     */
    public function timezone(): string
    {
        $configured = $this->mergedBusinessSettings()['timezone'] ?? null;
        if (is_string($configured) && Timezones::isValid(trim($configured))) {
            return trim($configured);
        }

        return app(InstanceTimezoneSettings::class)->resolved();
    }

    /** Effective default VAT rate (0–1) for new line items; 0 when not VAT-registered or when zero-rated. */
    public function defaultVatRateForInvoicing(): float
    {
        if (! $this->chargesVat()) {
            return 0.0;
        }

        return (float) ($this->mergedBusinessSettings()['default_vat_rate'] ?? 0);
    }

    public function aiEnabled(): bool
    {
        $settings = $this->mergedBusinessSettings();
        if (! filter_var($settings['ai']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $provider = $this->aiProvider();

        if (AiCatalog::apiKeyOptional($provider)) {
            return $this->aiBaseUrl() !== '';
        }

        return $this->aiApiKey() !== '';
    }

    public function aiProvider(): string
    {
        $settings = $this->mergedBusinessSettings();
        $fromTeam = trim((string) ($settings['ai']['provider'] ?? ''));
        if ($fromTeam !== '' && AiCatalog::isValidProvider($fromTeam)) {
            return $fromTeam;
        }

        $fromEnv = trim((string) config('services.ai.provider', AiCatalog::PROVIDER_OPENAI));

        return AiCatalog::isValidProvider($fromEnv)
            ? $fromEnv
            : AiCatalog::PROVIDER_OPENAI;
    }

    public function aiApiKey(): string
    {
        $settings = $this->mergedBusinessSettings();
        $fromTeam = trim((string) ($settings['ai']['api_key'] ?? ''));
        if ($fromTeam !== '') {
            return $fromTeam;
        }

        return match ($this->aiProvider()) {
            AiCatalog::PROVIDER_ANTHROPIC => trim((string) config('services.anthropic.api_key', '')),
            AiCatalog::PROVIDER_GEMINI => trim((string) config('services.gemini.api_key', '')),
            AiCatalog::PROVIDER_OPENROUTER => trim((string) config('services.openrouter.api_key', '')),
            AiCatalog::PROVIDER_OPENAI_COMPATIBLE => trim((string) config('services.openai_compatible.api_key', '')),
            default => trim((string) config('services.openai.api_key', '')),
        };
    }

    public function aiModel(): string
    {
        $provider = $this->aiProvider();
        $settings = $this->mergedBusinessSettings();
        $fromTeam = trim((string) ($settings['ai']['model'] ?? ''));
        if ($fromTeam !== '' && AiCatalog::isValidModel($provider, $fromTeam)) {
            return $fromTeam;
        }

        $fromEnv = match ($provider) {
            AiCatalog::PROVIDER_ANTHROPIC => trim((string) config('services.anthropic.model', '')),
            AiCatalog::PROVIDER_GEMINI => trim((string) config('services.gemini.model', '')),
            AiCatalog::PROVIDER_OPENROUTER => trim((string) config('services.openrouter.model', '')),
            AiCatalog::PROVIDER_OPENAI_COMPATIBLE => trim((string) config('services.openai_compatible.model', '')),
            default => trim((string) config('services.openai.model', '')),
        };

        if ($fromEnv !== '' && AiCatalog::isValidModel($provider, $fromEnv)) {
            return $fromEnv;
        }

        return AiCatalog::defaultModel($provider);
    }

    public function aiBaseUrl(): string
    {
        $provider = $this->aiProvider();
        $settings = $this->mergedBusinessSettings();
        $fromTeam = trim((string) ($settings['ai']['base_url'] ?? ''));
        if ($fromTeam !== '') {
            return rtrim($fromTeam, '/');
        }

        $fromEnv = match ($provider) {
            AiCatalog::PROVIDER_OPENROUTER => trim((string) config('services.openrouter.base_url', '')),
            AiCatalog::PROVIDER_OPENAI_COMPATIBLE => trim((string) config('services.openai_compatible.base_url', '')),
            default => '',
        };

        if ($fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $default = AiCatalog::defaultBaseUrl($provider);

        return $default !== null ? rtrim($default, '/') : '';
    }
}
