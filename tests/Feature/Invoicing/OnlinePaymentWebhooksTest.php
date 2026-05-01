<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\OnlinePaymentSessionStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceOnlinePaymentSession;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Support\PayFastSignature;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlinePaymentWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    private function teamWithStripeWebhook(Team $team, string $webhookSecret = 'test_webhook_signing_secret'): void
    {
        $team->company_settings = array_replace_recursive(
            is_array($team->company_settings) ? $team->company_settings : [],
            [
                'payment_gateways' => [
                    'stripe' => [
                        'enabled' => true,
                        'publishable_key' => 'pk_test',
                        'secret_key' => 'sk_test',
                        'webhook_secret' => $webhookSecret,
                    ],
                ],
            ]
        );
        $team->save();
    }

    private function teamWithPayFast(Team $team, string $passphrase = 'pf-secret'): void
    {
        $team->company_settings = array_replace_recursive(
            is_array($team->company_settings) ? $team->company_settings : [],
            [
                'payment_gateways' => [
                    'payfast' => [
                        'enabled' => true,
                        'merchant_id' => '100001',
                        'merchant_key' => 'abc123',
                        'passphrase' => $passphrase,
                    ],
                ],
            ]
        );
        $team->save();
    }

    public function test_stripe_webhook_records_payment_and_is_idempotent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->teamWithStripeWebhook($team);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'subtotal_cents' => 100_00,
                'vat_amount_cents' => 15_00,
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
                'currency' => 'ZAR',
            ]);

        $session = InvoiceOnlinePaymentSession::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'provider' => 'stripe',
            'status' => OnlinePaymentSessionStatus::Pending,
            'amount_cents' => 115_00,
            'currency' => 'ZAR',
            'provider_checkout_id' => 'cs_test_123',
        ]);

        $payloadArray = [
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_123',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 115_00,
                    'currency' => 'zar',
                    'metadata' => [
                        'team_id' => (string) $team->id,
                        'invoice_id' => (string) $invoice->id,
                        'nrth_session_id' => (string) $session->id,
                    ],
                    'payment_intent' => 'pi_test_1',
                ],
            ],
        ];
        $payload = json_encode($payloadArray);
        $this->assertIsString($payload);
        $timestamp = time();
        $secret = 'test_webhook_signing_secret';
        $signedPayload = $timestamp.'.'.$payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        $header = 't='.$timestamp.',v1='.$signature;

        $this->call('POST', route('webhooks.stripe', $team), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(115_00, (int) $invoice->getRawOriginal('amount_paid_cents'));
        $this->assertSame(1, Payment::queryWithoutTeamScope()->where('invoice_id', $invoice->id)->count());

        $session->refresh();
        $this->assertSame(OnlinePaymentSessionStatus::Completed, $session->status);

        $this->call('POST', route('webhooks.stripe', $team), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertOk();

        $this->assertSame(1, Payment::queryWithoutTeamScope()->where('invoice_id', $invoice->id)->count());
    }

    public function test_stripe_webhook_rejects_team_metadata_mismatch(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->teamWithStripeWebhook($team);

        $other = User::factory()->withPersonalTeam()->create();
        $otherTeam = $other->currentTeam;
        $this->assertNotNull($otherTeam);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
                'currency' => 'ZAR',
            ]);

        InvoiceOnlinePaymentSession::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'provider' => 'stripe',
            'status' => OnlinePaymentSessionStatus::Pending,
            'amount_cents' => 115_00,
            'currency' => 'ZAR',
            'provider_checkout_id' => 'cs_test_456',
        ]);

        $payloadArray = [
            'id' => 'evt_2',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_456',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 115_00,
                    'currency' => 'zar',
                    'metadata' => [
                        'team_id' => (string) $team->id,
                        'invoice_id' => (string) $invoice->id,
                    ],
                    'payment_intent' => 'pi_x',
                ],
            ],
        ];
        $payload = json_encode($payloadArray);
        $this->assertIsString($payload);
        $timestamp = time();
        $secret = 'test_webhook_signing_secret';
        $header = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $this->call('POST', route('webhooks.stripe', $otherTeam), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload)->assertStatus(400);
    }

    public function test_payfast_webhook_records_payment(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->teamWithPayFast($team, 'pf-secret');

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'subtotal_cents' => 100_00,
                'vat_amount_cents' => 15_00,
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
                'currency' => 'ZAR',
            ]);

        $mPaymentId = 'nrth-pf-1';

        InvoiceOnlinePaymentSession::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'provider' => 'payfast',
            'status' => OnlinePaymentSessionStatus::Pending,
            'amount_cents' => 115_00,
            'currency' => 'ZAR',
            'provider_checkout_id' => $mPaymentId,
        ]);

        $fields = [
            'm_payment_id' => $mPaymentId,
            'pf_payment_id' => '123456',
            'payment_status' => 'COMPLETE',
            'amount_gross' => '115.00',
            'amount_fee' => '-3.45',
            'amount_net' => '111.55',
        ];
        $fields['signature'] = PayFastSignature::build($fields, 'pf-secret');

        $this->post(route('webhooks.payfast', $team), $fields)->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(115_00, (int) $invoice->getRawOriginal('amount_paid_cents'));
    }

    public function test_payfast_webhook_rejects_bad_signature(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->teamWithPayFast($team);

        $this->post(route('webhooks.payfast', $team), [
            'm_payment_id' => 'x',
            'payment_status' => 'COMPLETE',
            'amount_gross' => '1.00',
            'signature' => 'not-valid',
        ])->assertStatus(400);
    }

    public function test_online_payment_start_requires_configuration(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
                'currency' => 'ZAR',
            ]);

        $this->post(route('invoicing.invoices.online-payments.store', $invoice), [
            'provider' => 'stripe',
        ])->assertSessionHasErrors('provider');
    }
}
