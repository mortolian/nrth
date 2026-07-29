<?php

namespace App\Models;

use App\Domain\Ai\AiCatalog;
use App\Domain\Tax\Models\TaxRate;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
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
     * @return list<array{title: string|null, name: string|null, holder: string|null, account: string|null, swift_code: string|null, bic: string|null, iban: string|null, routing_sort_code: string|null, branch: string|null, type: string|null}>
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
        if ($mime === null || $mime === '') {
            $mime = 'image/png';
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
            'invoice_email_subject_template' => 'Invoice {{number}} from {{business}}',
            'invoice_email_body_template' => "Hi {{client_name}},\n\nPlease find invoice {{number}} attached.\n\nThank you,\n{{business}}",
            'vat_registered' => false,
            'vat_period_type' => 'bi_monthly',
            'default_tax_rate_id' => null,
            /** Labels offered when picking a unit on catalog items. */
            'item_units' => self::defaultItemUnits(),
            /** Master switch: hosted checkout on invoice + public pay page (Stripe, PayFast, …). */
            'payment_pages_enabled' => false,
            /** 0 = off (Laravel SESSION_LIFETIME only). Cap at config('session.lifetime'). */
            'session_idle_timeout_minutes' => 0,
            /** Optional AI provider (expenses, documents, …). Env keys are per-provider fallback. */
            'ai' => [
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

        return $merged;
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
     * @param  mixed  $units
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
     * Whether invoices/estimates may apply VAT: VAT-registered in settings and a valid default VAT rate is configured.
     */
    public function chargesVat(): bool
    {
        $settings = $this->mergedBusinessSettings();
        if (! filter_var($settings['vat_registered'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $taxRateId = $settings['default_tax_rate_id'] ?? null;
        if ($taxRateId === null || $taxRateId === '' || (int) $taxRateId <= 0) {
            return false;
        }

        return TaxRate::queryWithoutTeamScope()
            ->where('team_id', $this->id)
            ->whereKey((int) $taxRateId)
            ->where('is_active', true)
            ->exists();
    }

    /** Effective default VAT rate (0–1) for new line items; 0 when VAT must not be charged. */
    public function defaultVatRateForInvoicing(): float
    {
        if (! $this->chargesVat()) {
            return 0.0;
        }

        $settings = $this->mergedBusinessSettings();
        $taxRateId = (int) ($settings['default_tax_rate_id'] ?? 0);
        $rate = TaxRate::queryWithoutTeamScope()
            ->where('team_id', $this->id)
            ->whereKey($taxRateId)
            ->value('rate');

        return $rate !== null ? (float) $rate : 0.0;
    }

    public function aiEnabled(): bool
    {
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
