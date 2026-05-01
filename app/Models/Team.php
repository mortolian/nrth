<?php

namespace App\Models;

use App\Domain\Tax\Models\TaxRate;
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
        'company_settings',
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'company_settings' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
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
     * @return list<array{title: string|null, name: string|null, holder: string|null, account: string|null, branch: string|null, type: string|null}>
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

        $settings = $this->mergedCompanySettings();
        $bank = [
            'title' => null,
            'name' => $settings['bank_name'] ?? null,
            'holder' => $settings['bank_account_holder'] ?? null,
            'account' => $settings['bank_account_number'] ?? null,
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
    public static function defaultCompanySettings(): array
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
            'company_email' => null,
            'company_phone' => null,
            'company_website' => null,
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
            'invoice_email_subject_template' => 'Invoice {{number}} from {{company}}',
            'invoice_email_body_template' => "Hi {{client_name}},\n\nPlease find invoice {{number}} attached.\n\nThank you,\n{{company}}",
            'vat_registered' => true,
            'vat_period_type' => 'bi_monthly',
            'default_tax_rate_id' => null,
            'bank_name' => null,
            'bank_account_holder' => null,
            'bank_account_number' => null,
            'bank_branch_code' => null,
            'bank_account_type' => 'current',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedCompanySettings(): array
    {
        $stored = is_array($this->company_settings) ? $this->company_settings : [];
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

        return array_replace_recursive(
            self::defaultCompanySettings(),
            $normalized
        );
    }

    /**
     * Issuer / "from" block for invoices, estimates, previews, PDFs, and client-facing emails.
     *
     * @return array{
     *     name: string,
     *     address: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     website: string|null,
     *     registration_number: string|null,
     *     vat_number: string|null,
     * }
     */
    public function issuerForInvoicingDocuments(string $documentType = 'invoice'): array
    {
        $settings = $this->mergedCompanySettings();

        $trading = trim((string) ($settings['trading_name'] ?? ''));
        $name = $trading !== '' ? $trading : (string) $this->name;

        $showStreetSetting = $documentType === 'estimate' ? 'estimate_show_street_address' : 'invoice_show_street_address';
        $showStreet = (bool) ($settings[$showStreetSetting] ?? true);
        $physicalParts = $showStreet
            ? [
                $settings['physical_street'] ?? null,
                $settings['physical_city'] ?? null,
                $settings['physical_province'] ?? null,
                $settings['physical_postal_code'] ?? null,
                $settings['physical_country'] ?? null,
            ]
            : [
                $settings['physical_city'] ?? null,
                $settings['physical_province'] ?? null,
                $settings['physical_postal_code'] ?? null,
                $settings['physical_country'] ?? null,
            ];

        $address = trim(collect($physicalParts)->filter()->implode(', '));
        $address = $address !== '' ? $address : null;

        $nullIfEmpty = static function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }

            return (string) $v;
        };

        return [
            'name' => $name,
            'address' => $address,
            'email' => $nullIfEmpty($settings['company_email'] ?? null),
            'phone' => $nullIfEmpty($settings['company_phone'] ?? null),
            'website' => $nullIfEmpty($settings['company_website'] ?? null),
            'registration_number' => $nullIfEmpty($settings['registration_number'] ?? null),
            'vat_number' => $nullIfEmpty($settings['vat_number'] ?? null),
        ];
    }

    /**
     * Whether invoices/estimates may apply VAT: VAT-registered in settings and a valid default VAT rate is configured.
     */
    public function chargesVat(): bool
    {
        $settings = $this->mergedCompanySettings();
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

        $settings = $this->mergedCompanySettings();
        $taxRateId = (int) ($settings['default_tax_rate_id'] ?? 0);
        $rate = TaxRate::queryWithoutTeamScope()
            ->where('team_id', $this->id)
            ->whereKey($taxRateId)
            ->value('rate');

        return $rate !== null ? (float) $rate : 0.0;
    }
}
