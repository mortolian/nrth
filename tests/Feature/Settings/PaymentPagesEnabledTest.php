<?php

namespace Tests\Feature\Settings;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\User;
use App\Support\InvoiceOnlinePaymentProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPagesEnabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_pages_disabled_yields_no_online_providers_even_when_stripe_configured(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'payment_pages_enabled' => false,
                    'payment_gateways' => [
                        'stripe' => [
                            'enabled' => true,
                            'secret_key' => 'sk_test_fake',
                            'publishable_key' => 'pk_test_fake',
                        ],
                    ],
                ]
            ),
        ])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'currency' => 'ZAR',
        ]);

        $this->assertSame([], InvoiceOnlinePaymentProviders::enabledForInvoice($invoice->fresh()));
    }

    public function test_payment_pages_enabled_default_blocks_configured_stripe(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'payment_gateways' => [
                        'stripe' => [
                            'enabled' => true,
                            'secret_key' => 'sk_test_fake',
                            'publishable_key' => 'pk_test_fake',
                        ],
                    ],
                ]
            ),
        ])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'currency' => 'ZAR',
        ]);

        $this->assertSame([], InvoiceOnlinePaymentProviders::enabledForInvoice($invoice->fresh()));
    }

    public function test_payment_pages_enabled_allows_configured_stripe(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'payment_pages_enabled' => true,
                    'payment_gateways' => [
                        'stripe' => [
                            'enabled' => true,
                            'secret_key' => 'sk_test_fake',
                            'publishable_key' => 'pk_test_fake',
                        ],
                    ],
                ]
            ),
        ])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'currency' => 'ZAR',
        ]);

        $this->assertSame(['stripe'], InvoiceOnlinePaymentProviders::enabledForInvoice($invoice->fresh()));
    }

    public function test_new_company_starts_with_online_payments_and_providers_off(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $settings = $team->mergedBusinessSettings();
        $this->assertFalse($settings['payment_pages_enabled']);
        foreach (['payfast', 'stripe', 'paypal', 'netcash', 'snapscan', 'zapper'] as $gateway) {
            $this->assertFalse($settings['payment_gateways'][$gateway]['enabled']);
        }

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'currency' => 'ZAR',
        ]);
        $this->assertSame([], InvoiceOnlinePaymentProviders::enabledForInvoice($invoice->fresh()));
    }

    public function test_enabling_stripe_without_credentials_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $settings = $team->mergedBusinessSettings();

        $payload = $this->businessPaymentTabPayload($team->name, $settings, [
            'stripe' => [
                'enabled' => true,
                'publishable_key' => '',
                'secret_key' => '',
            ],
        ]);

        $this->actingAs($user)
            ->post(route('settings.business.update'), $payload)
            ->assertSessionHasErrors([
                'payment_gateways.stripe.publishable_key',
                'payment_gateways.stripe.secret_key',
            ]);
    }

    public function test_enabling_stripe_without_webhook_secret_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $settings = $team->mergedBusinessSettings();

        $payload = $this->businessPaymentTabPayload($team->name, $settings, [
            'stripe' => [
                'enabled' => true,
                'publishable_key' => 'pk_test',
                'secret_key' => 'sk_test',
                'webhook_secret' => '',
            ],
        ]);

        $this->actingAs($user)
            ->post(route('settings.business.update'), $payload)
            ->assertSessionHasErrors('payment_gateways.stripe.webhook_secret');
    }

    public function test_enabling_payfast_without_passphrase_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $settings = $team->mergedBusinessSettings();

        $payload = $this->businessPaymentTabPayload($team->name, $settings, [
            'payfast' => [
                'enabled' => true,
                'merchant_id' => '100001',
                'merchant_key' => 'abc123',
                'passphrase' => '',
            ],
        ]);

        $this->actingAs($user)
            ->post(route('settings.business.update'), $payload)
            ->assertSessionHasErrors('payment_gateways.payfast.passphrase');
    }

    public function test_start_online_payment_rejects_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'payment_pages_enabled' => false,
                    'payment_gateways' => [
                        'stripe' => [
                            'enabled' => true,
                            'secret_key' => 'sk_test_fake',
                            'publishable_key' => 'pk_test_fake',
                        ],
                    ],
                ]
            ),
        ])->save();

        $this->actingAs($user);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
            'currency' => 'ZAR',
            'total_cents' => 10_000,
            'amount_paid_cents' => 0,
        ]);

        $response = $this->post(route('invoicing.invoices.online-payments.store', $invoice), [
            'provider' => 'stripe',
        ]);

        $response->assertSessionHasErrors('provider');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $gatewayOverrides
     * @return array<string, mixed>
     */
    private function businessPaymentTabPayload(string $name, array $settings, array $gatewayOverrides): array
    {
        return [
            'tab' => 'payment_pages',
            'name' => $name,
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
            'payment_pages_enabled' => true,
            'session_idle_timeout_minutes' => $settings['session_idle_timeout_minutes'],
            'ai' => $settings['ai'],
            'payment_gateways' => array_replace_recursive($settings['payment_gateways'], $gatewayOverrides),
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
