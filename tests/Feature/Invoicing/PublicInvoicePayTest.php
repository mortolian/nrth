<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
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
}
