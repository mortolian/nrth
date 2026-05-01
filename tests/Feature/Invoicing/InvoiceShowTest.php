<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Invoice;
use App\Models\User;
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
                ->has('charges_vat'));
    }
}
