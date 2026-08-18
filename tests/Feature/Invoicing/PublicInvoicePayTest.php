<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\OnlinePaymentSessionStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceOnlinePaymentSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvoicePayTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pay_page_returns_404_for_invalid_token(): void
    {
        $this->get(route('public.invoice.pay', ['token' => str_repeat('0', 32)]))->assertNotFound();
    }

    public function test_public_pay_page_renders_for_valid_token(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'a1b2c3d4e5f6789012345678abcdef01',
                'total_cents' => 100_00,
                'amount_paid_cents' => 0,
            ]);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/InvoicePay')
                ->has('invoice.number'));
    }

    public function test_draft_invoice_with_token_still_404_public(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Draft,
                'public_token' => 'b2c3d4e5f6789012345678abcdef01',
            ]);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))->assertNotFound();
    }

    public function test_public_pay_qr_returns_png_for_team_member(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'c3d4e5f6789012345678abcdef0123',
            ]);

        $this->get(route('invoicing.invoices.public-pay-qr', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_authenticated_user_can_create_public_pay_link(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => null,
            ]);

        $this->post(route('invoicing.invoices.public-pay-link.store', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertNotNull($invoice->public_token);
        $this->assertSame(32, strlen((string) $invoice->public_token));
    }

    public function test_public_pay_page_not_accessible_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => false],
            ),
        ])->save();

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'deadbeefdeadbeefdeadbeefdeadbeef',
            ]);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))->assertNotFound();
    }

    public function test_public_pay_checkout_not_accessible_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => false],
            ),
        ])->save();

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'cafebabecafebabecafebabecafebabe',
            ]);

        $this->post(route('public.invoice.checkout', ['token' => $invoice->public_token]), [
            'provider' => 'stripe',
        ])->assertNotFound();
    }

    public function test_public_pay_pdf_not_accessible_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => false],
            ),
        ])->save();

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'fedcba9876543210fedcba9876543210',
            ]);

        $this->get(route('public.invoice.pdf', ['token' => $invoice->public_token]))->assertNotFound();
    }

    public function test_public_pay_qr_forbidden_for_team_member_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => false],
            ),
        ])->save();
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => '0123456789abcdef0123456789abcdef',
            ]);

        $this->get(route('invoicing.invoices.public-pay-qr', $invoice))->assertForbidden();
    }

    public function test_store_public_pay_link_forbidden_when_payment_pages_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => false],
            ),
        ])->save();
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => null,
            ]);

        $this->post(route('invoicing.invoices.public-pay-link.store', $invoice))->assertForbidden();

        $invoice->refresh();
        $this->assertNull($invoice->public_token);
    }

    public function test_public_pay_page_includes_client_when_guest_team_scope_is_forced(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();

        $client = Client::factory()->for($team)->create([
            'name' => 'Visible Client',
            'email' => 'billing@visible.test',
        ]);
        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'c1d2e3f4a5b6789012345678abcdef01',
                'total_cents' => 100_00,
                'amount_paid_cents' => 0,
            ]);

        $this->app->instance('nrth.forceGuestTeamScope', true);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/InvoicePay')
                ->where('invoice.client.name', 'Visible Client')
                ->where('invoice.client.email', 'billing@visible.test'));
    }

    public function test_public_pay_success_banner_requires_a_completed_session(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'd2e3f4a5b6789012345678abcdef0123',
                'total_cents' => 100_00,
                'amount_paid_cents' => 0,
            ]);

        $this->get(route('public.invoice.pay', [
            'token' => $invoice->public_token,
            'online_payment' => 'success',
        ]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flash_online_payment', null));

        InvoiceOnlinePaymentSession::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'provider' => 'payfast',
            'status' => OnlinePaymentSessionStatus::Completed,
            'amount_cents' => 100_00,
            'currency' => 'ZAR',
            'provider_checkout_id' => 'nrth-done',
        ]);

        $this->get(route('public.invoice.pay', [
            'token' => $invoice->public_token,
            'online_payment' => 'success',
        ]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flash_online_payment', 'success'));
    }
}
