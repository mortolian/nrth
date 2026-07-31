<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessVatSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_company_starts_with_vat_registered_off_and_zero_default_rate(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $settings = $team->mergedBusinessSettings();
        $this->assertFalse($settings['vat_registered']);
        $this->assertSame(0.0, (float) $settings['default_vat_rate']);
        $this->assertFalse($team->chargesVat());
        $this->assertSame(0.0, $team->defaultVatRateForInvoicing());
    }

    public function test_vat_registered_with_zero_default_still_charges_vat_as_zero_rated(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'vat_registered' => true,
                    'default_vat_rate' => 0,
                ],
            ),
        ])->save();

        $team = $team->fresh();
        $this->assertTrue($team->chargesVat());
        $this->assertSame(0.0, $team->defaultVatRateForInvoicing());
    }

    public function test_business_settings_accepts_freeform_default_vat_rate_percent(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        $settings = $team->mergedBusinessSettings();

        $payload = [
            'tab' => 'tax',
            'name' => $team->name,
            'trading_name' => $settings['trading_name'],
            'registration_number' => $settings['registration_number'],
            'vat_number' => '4123456789',
            'tax_reference' => $settings['tax_reference'],
            'industry' => $settings['industry'],
            'financial_year_end_month' => $settings['financial_year_end_month'],
            'physical_street' => $settings['physical_street'],
            'physical_city' => $settings['physical_city'],
            'physical_province' => $settings['physical_province'],
            'physical_postal_code' => $settings['physical_postal_code'],
            'physical_country' => $settings['physical_country'],
            'postal_same_as_physical' => $settings['postal_same_as_physical'],
            'postal_street' => $settings['postal_street'],
            'postal_city' => $settings['postal_city'],
            'postal_province' => $settings['postal_province'],
            'postal_postal_code' => $settings['postal_postal_code'],
            'postal_country' => $settings['postal_country'],
            'business_email' => $settings['business_email'],
            'business_phone' => $settings['business_phone'],
            'business_website' => $settings['business_website'],
            'invoice_default_payment_terms_days' => $settings['invoice_default_payment_terms_days'],
            'invoice_default_currency' => $settings['invoice_default_currency'],
            'invoice_prefix' => $settings['invoice_prefix'],
            'invoice_number_include_month' => $settings['invoice_number_include_month'],
            'invoice_number_use_random_suffix' => $settings['invoice_number_use_random_suffix'],
            'estimate_prefix' => $settings['estimate_prefix'],
            'estimate_number_include_month' => $settings['estimate_number_include_month'],
            'estimate_number_use_random_suffix' => $settings['estimate_number_use_random_suffix'],
            'estimate_default_notes' => $settings['estimate_default_notes'],
            'estimate_default_terms' => $settings['estimate_default_terms'],
            'invoice_show_street_address' => $settings['invoice_show_street_address'],
            'estimate_show_street_address' => $settings['estimate_show_street_address'],
            'invoice_default_notes' => $settings['invoice_default_notes'],
            'invoice_default_footer' => $settings['invoice_default_footer'],
            'invoice_email_subject_template' => $settings['invoice_email_subject_template'],
            'invoice_email_body_template' => $settings['invoice_email_body_template'],
            'vat_registered' => true,
            'vat_period_type' => 'bi_monthly',
            'default_vat_rate' => 0.15,
            'payment_pages_enabled' => $settings['payment_pages_enabled'],
            'session_idle_timeout_minutes' => $settings['session_idle_timeout_minutes'],
            'ai' => $settings['ai'],
            'payment_gateways' => $settings['payment_gateways'],
            'bank_accounts' => [
                [
                    'title' => '',
                    'bank_name' => '',
                    'bank_account_holder' => '',
                    'bank_account_number' => '',
                    'swift_code' => '',
                    'bic' => '',
                    'iban' => '',
                    'routing_sort_code' => '',
                    'bank_branch_code' => '',
                    'bank_account_type' => 'current',
                    'show_on_invoice' => true,
                ],
            ],
        ];

        $this->actingAs($owner)
            ->post(route('settings.business.update'), $payload)
            ->assertRedirect(route('settings.business', ['tab' => 'tax']));

        $fresh = $team->fresh()->mergedBusinessSettings();
        $this->assertTrue($fresh['vat_registered']);
        $this->assertSame(0.15, (float) $fresh['default_vat_rate']);
        $this->assertNull($fresh['default_tax_rate_id']);
    }

    public function test_business_settings_vat_period_types_omit_sars_small_vendor_label(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);

        $this->actingAs($owner)
            ->get(route('settings.business', ['tab' => 'tax']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Business')
                ->where('settings.vat_registered', false)
                ->where('settings.default_vat_rate', 0)
                ->where('vat_period_types', [
                    ['value' => 'bi_monthly', 'label' => 'Bi-monthly'],
                    ['value' => 'monthly', 'label' => 'Monthly'],
                    ['value' => 'quarterly', 'label' => 'Quarterly'],
                ])
            );
    }
}
