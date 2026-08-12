<?php

namespace Tests\Feature\Settings;

use App\Domain\Instance\Services\InstanceTimezoneSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTimezoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_settings_page_includes_timezone_options(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($owner);

        $this->get(route('settings.business'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Business')
                ->has('timezone_options')
                ->has('instance_timezone')
                ->where('settings.timezone', null));
    }

    public function test_owner_can_set_and_clear_business_timezone(): void
    {
        app(InstanceTimezoneSettings::class)->update(['timezone' => 'UTC']);

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->actingAs($owner);

        $settings = $team->mergedBusinessSettings();
        $base = $this->businessPayload($team->name, $settings, 'Africa/Johannesburg');

        $this->post(route('settings.business.update'), $base)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $team = $team->fresh();
        $this->assertSame('Africa/Johannesburg', $team->mergedBusinessSettings()['timezone']);
        $this->assertSame('Africa/Johannesburg', $team->timezone());

        $cleared = $this->businessPayload($team->name, $team->mergedBusinessSettings(), null);
        $this->post(route('settings.business.update'), $cleared)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $team = $team->fresh();
        $this->assertNull($team->mergedBusinessSettings()['timezone']);
        $this->assertSame('UTC', $team->timezone());
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function businessPayload(string $name, array $settings, ?string $timezone): array
    {
        return [
            'tab' => 'profile',
            'name' => $name,
            'trading_name' => $settings['trading_name'],
            'registration_number' => $settings['registration_number'],
            'vat_number' => $settings['vat_number'],
            'tax_reference' => $settings['tax_reference'],
            'industry' => $settings['industry'],
            'financial_year_end_month' => $settings['financial_year_end_month'],
            'timezone' => $timezone,
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
            'vat_registered' => $settings['vat_registered'],
            'vat_period_type' => $settings['vat_period_type'],
            'default_vat_rate' => $settings['default_vat_rate'],
            'payment_pages_enabled' => $settings['payment_pages_enabled'],
            'session_idle_timeout_minutes' => $settings['session_idle_timeout_minutes'] ?? 0,
            'ai' => $settings['ai'],
            'payment_gateways' => $settings['payment_gateways'],
            'item_units' => $settings['item_units'],
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
    }
}
