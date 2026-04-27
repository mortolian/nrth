<?php

namespace App\Models;

use App\Domain\Tax\Models\TaxRate;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
            'invoice_prefix' => 'INV',
            'invoice_number_include_month' => false,
            'invoice_number_use_random_suffix' => false,
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
        return array_replace_recursive(
            self::defaultCompanySettings(),
            $this->company_settings ?? []
        );
    }

    /**
     * Whether invoices/quotes may apply VAT: VAT-registered in settings and a valid default VAT rate is configured.
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
