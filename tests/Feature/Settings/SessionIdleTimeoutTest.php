<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionIdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function companySettingsPayload(Team $team, array $overrides = []): array
    {
        $settings = $team->mergedCompanySettings();

        return array_replace_recursive([
            'tab' => 'security',
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
            'company_email' => $settings['company_email'],
            'company_phone' => $settings['company_phone'],
            'company_website' => $settings['company_website'],
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
        ], $overrides);
    }

    public function test_security_tab_persists_idle_timeout_setting(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->post(
            route('settings.company.update'),
            $this->companySettingsPayload($team, [
                'session_idle_timeout_minutes' => 30,
                'tab' => 'security',
            ])
        )->assertRedirect(route('settings.company', ['tab' => 'security']));

        $this->assertSame(30, (int) $team->fresh()->mergedCompanySettings()['session_idle_timeout_minutes']);
    }

    public function test_idle_timeout_cannot_exceed_session_lifetime(): void
    {
        config(['session.lifetime' => 120]);

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->post(
            route('settings.company.update'),
            $this->companySettingsPayload($team, [
                'session_idle_timeout_minutes' => 121,
                'tab' => 'security',
            ])
        )->assertSessionHasErrors('session_idle_timeout_minutes');

        $this->assertSame(0, (int) $team->fresh()->mergedCompanySettings()['session_idle_timeout_minutes']);
    }

    public function test_request_past_idle_window_logs_user_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'company_settings' => array_replace_recursive(
                is_array($team->company_settings) ? $team->company_settings : [],
                ['session_idle_timeout_minutes' => 15]
            ),
        ])->save();

        $this->actingAs($user);

        $stale = now()->subMinutes(16)->getTimestamp();

        $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => $stale,
        ])->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were signed out due to inactivity.');

        $this->assertGuest();
    }

    public function test_request_within_idle_window_refreshes_activity(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'company_settings' => array_replace_recursive(
                is_array($team->company_settings) ? $team->company_settings : [],
                ['session_idle_timeout_minutes' => 15]
            ),
        ])->save();

        $this->actingAs($user);

        $recent = now()->subMinutes(5)->getTimestamp();

        $response = $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => $recent,
        ])->get(route('dashboard'));

        $response->assertOk();
        $this->assertAuthenticatedAs($user);

        $updated = session(EnforceSessionIdleTimeout::SESSION_KEY);
        $this->assertIsNumeric($updated);
        $this->assertGreaterThan($recent, (int) $updated);
    }

    public function test_idle_timeout_disabled_does_not_log_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->assertSame(0, (int) $team->mergedCompanySettings()['session_idle_timeout_minutes']);

        $this->actingAs($user);

        $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => now()->subHours(10)->getTimestamp(),
        ])->get(route('dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
