<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_show_renders_inertia_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()->for($team)->create();

        $this->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Show')
                ->has('invoice.id')
                ->has('online_payment_providers')
                ->has('charges_vat')
                ->where('can.clone', true));
    }

    public function test_invoice_show_allows_resend_for_paid_invoice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
            'sent_at' => now()->subDay(),
        ]);

        $this->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Show')
                ->where('can.send', true)
                ->where('can.remind', false)
                ->where('can.clone', true));
    }

    public function test_invoice_show_includes_bank_amount_for_foreign_currency_payments(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Partial,
            'currency' => 'EUR',
            'business_currency_code' => 'ZAR',
            'total_cents' => 100_00,
            'total_business_currency_cents' => 2000_00,
            'fx_rate_invoice_to_business' => '20',
            'fx_rate_date' => '2026-04-25',
            'amount_paid_cents' => 50_00,
        ]);

        $payment = Payment::factory()->for($team)->for($invoice)->create([
            'amount_cents' => 50_00,
            'currency' => 'EUR',
            'bank_amount_business_cents' => 950_00,
            'method' => PaymentMethod::Eft,
        ]);

        $this->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Show')
                ->where('business_currency', 'ZAR')
                ->where('invoice.currency', 'EUR')
                ->has('invoice.payments', 1)
                ->where('invoice.payments.0.id', $payment->id)
                ->where('invoice.payments.0.amount_cents', 50_00)
                ->where('invoice.payments.0.bank_amount_business_cents', 950_00));
    }

    public function test_viewer_cannot_clone_from_invoice_show(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);
        EnsureTeamSystemRoles::ensureFor($team);

        $viewer = User::factory()->create();
        $team->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $team->id])->save();

        $invoice = Invoice::factory()->for($team)->create();

        $this->actingAs($viewer->fresh())
            ->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Show')
                ->where('can.clone', false)
                ->where('can.edit', false));

        $this->actingAs($viewer->fresh())
            ->get(route('invoicing.invoices.create', ['from' => $invoice->id]))
            ->assertForbidden();
    }
}
