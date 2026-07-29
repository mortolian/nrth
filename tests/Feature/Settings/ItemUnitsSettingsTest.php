<?php

namespace Tests\Feature\Settings;

use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemUnitsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_settings_include_default_item_units(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $this->assertNotNull($team);

        $units = $team->itemUnits();

        $this->assertContains('hour', $units);
        $this->assertContains('each', $units);
        $this->assertContains('month', $units);
        $this->assertSame(Team::defaultItemUnits(), $units);
    }

    public function test_owner_can_save_custom_item_units(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($user->currentTeam);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $settings = $team->mergedBusinessSettings();

        $payload = [
            'tab' => 'items',
            'name' => $team->name,
            'trading_name' => $settings['trading_name'],
            'registration_number' => $settings['registration_number'],
            'vat_number' => $settings['vat_number'],
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
            'vat_registered' => $settings['vat_registered'],
            'vat_period_type' => $settings['vat_period_type'],
            'default_tax_rate_id' => $settings['default_tax_rate_id'],
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
            'item_units' => ['hour', 'day', 'retainer'],
        ];

        $this->actingAs($user)
            ->post(route('settings.business.update'), $payload)
            ->assertRedirect(route('settings.business', ['tab' => 'items']));

        $team->refresh();
        $this->assertSame(['hour', 'day', 'retainer'], $team->itemUnits());
    }

    public function test_item_form_rejects_unit_not_in_catalog(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($user->currentTeam);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->business_settings = array_replace_recursive(
            $team->mergedBusinessSettings(),
            ['item_units' => ['hour', 'day']]
        );
        $team->save();

        $this->actingAs($user)
            ->post(route('invoicing.items.store'), [
                'name' => 'Consulting',
                'unit' => 'furlong',
                'unit_price_cents' => 10000,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('unit');

        $this->actingAs($user)
            ->post(route('invoicing.items.store'), [
                'name' => 'Consulting',
                'unit' => 'hour',
                'unit_price_cents' => 10000,
                'is_active' => true,
            ])
            ->assertRedirect();
    }
}
