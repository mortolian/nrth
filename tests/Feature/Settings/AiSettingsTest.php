<?php

namespace Tests\Feature\Settings;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function companySettingsPayload(Team $team, array $overrides = []): array
    {
        $settings = $team->mergedBusinessSettings();

        return array_replace_recursive([
            'tab' => 'ai',
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
            'default_vat_rate' => $settings['default_vat_rate'],
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
        ], $overrides);
    }

    public function test_ai_tab_persists_provider_api_key_and_model(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'anthropic',
                    'api_key' => 'sk-ant-live',
                    'model' => 'claude-sonnet-4-5',
                ],
                'tab' => 'ai',
            ])
        )->assertRedirect(route('settings.business', ['tab' => 'ai']));

        $ai = $team->fresh()->mergedBusinessSettings()['ai'];
        $this->assertSame('anthropic', $ai['provider']);
        $this->assertSame('sk-ant-live', $ai['api_key']);
        $this->assertSame('claude-sonnet-4-5', $ai['model']);
        $this->assertTrue($team->fresh()->aiEnabled());
        $this->assertSame('anthropic', $team->fresh()->aiProvider());
    }

    public function test_ai_rejects_model_for_wrong_provider(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'anthropic',
                    'api_key' => 'sk-ant-live',
                    'model' => 'gpt-4o-mini',
                ],
                'tab' => 'ai',
            ])
        )->assertSessionHasErrors('ai.model');
    }

    public function test_ai_rejects_unknown_provider(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'not-a-provider',
                    'api_key' => 'sk-test',
                    'model' => 'gpt-4o-mini',
                ],
                'tab' => 'ai',
            ])
        )->assertSessionHasErrors('ai.provider');
    }

    public function test_ai_tab_persists_gemini_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'gemini',
                    'api_key' => 'AIza-test',
                    'model' => 'gemini-2.5-flash',
                ],
                'tab' => 'ai',
            ])
        )->assertRedirect(route('settings.business', ['tab' => 'ai']));

        $this->assertSame('gemini', $team->fresh()->aiProvider());
        $this->assertSame('AIza-test', $team->fresh()->aiApiKey());
    }

    public function test_ai_tab_persists_openai_compatible_without_api_key(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'openai_compatible',
                    'api_key' => '',
                    'model' => 'llava',
                    'base_url' => 'http://127.0.0.1:11434/v1',
                ],
                'tab' => 'ai',
            ])
        )->assertRedirect(route('settings.business', ['tab' => 'ai']));

        $fresh = $team->fresh();
        $this->assertSame('openai_compatible', $fresh->aiProvider());
        $this->assertSame('http://127.0.0.1:11434/v1', $fresh->aiBaseUrl());
        $this->assertTrue($fresh->aiEnabled());
    }

    public function test_legacy_ollama_provider_maps_to_openai_compatible(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => [
                'ai' => [
                    'provider' => 'ollama',
                    'api_key' => null,
                    'model' => 'llava',
                ],
            ],
        ])->save();

        $fresh = $team->fresh();
        $this->assertSame('openai_compatible', $fresh->aiProvider());
        $this->assertSame('http://127.0.0.1:11434/v1', $fresh->aiBaseUrl());
        $this->assertTrue($fresh->aiEnabled());
    }

    public function test_openai_compatible_requires_base_url(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'openai_compatible',
                    'api_key' => 'sk-local',
                    'model' => 'gpt-4o-mini',
                    'base_url' => '',
                ],
                'tab' => 'ai',
            ])
        )->assertSessionHasErrors('ai.base_url');
    }

    public function test_ai_tab_persists_openrouter_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingAs($user);

        $this->post(
            route('settings.business.update'),
            $this->companySettingsPayload($team, [
                'ai' => [
                    'provider' => 'openrouter',
                    'api_key' => 'sk-or-test',
                    'model' => 'openai/gpt-4o-mini',
                    'base_url' => 'https://openrouter.ai/api/v1',
                ],
                'tab' => 'ai',
            ])
        )->assertRedirect(route('settings.business', ['tab' => 'ai']));

        $fresh = $team->fresh();
        $this->assertSame('openrouter', $fresh->aiProvider());
        $this->assertSame('https://openrouter.ai/api/v1', $fresh->aiBaseUrl());
    }

    public function test_legacy_receipt_scan_settings_migrate_to_ai(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => [
                'receipt_scan' => [
                    'provider' => 'openai',
                    'api_key' => 'sk-legacy',
                    'model' => 'gpt-4o',
                ],
            ],
        ])->save();

        $this->assertSame('sk-legacy', $team->fresh()->aiApiKey());
        $this->assertSame('gpt-4o', $team->fresh()->aiModel());
        $this->assertArrayNotHasKey('receipt_scan', $team->fresh()->mergedBusinessSettings());
    }
}
