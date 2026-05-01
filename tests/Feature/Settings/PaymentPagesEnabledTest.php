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
            'company_settings' => array_replace_recursive(
                is_array($team->company_settings) ? $team->company_settings : [],
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

    public function test_payment_pages_enabled_default_allows_configured_stripe(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'company_settings' => array_replace_recursive(
                is_array($team->company_settings) ? $team->company_settings : [],
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

        $this->assertSame(['stripe'], InvoiceOnlinePaymentProviders::enabledForInvoice($invoice->fresh()));
    }

    public function test_start_online_payment_rejects_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $team->forceFill([
            'company_settings' => array_replace_recursive(
                is_array($team->company_settings) ? $team->company_settings : [],
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
}
