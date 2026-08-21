<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
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
