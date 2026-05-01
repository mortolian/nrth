<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceEstimateDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_user_can_delete_invoice_without_payments(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
        ]);

        $response = $this->delete(route('invoicing.invoices.destroy', $invoice));

        $response->assertRedirect(route('invoicing.invoices.index'));
        $this->assertNull(Invoice::query()->find($invoice->id));
    }

    public function test_user_cannot_delete_invoice_with_payments(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
        ]);

        Payment::factory()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
        ]);

        $response = $this->from(route('invoicing.invoices.show', $invoice))
            ->delete(route('invoicing.invoices.destroy', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $response->assertSessionHasErrors('delete');
        $this->assertNotNull(Invoice::query()->find($invoice->id));
    }

    public function test_user_can_delete_estimate(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $estimate = Estimate::query()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => EstimateStatus::Draft,
            'number' => 'EST-2026-9001',
            'issue_date' => '2026-04-01',
            'expiry_date' => '2026-05-01',
            'subtotal_cents' => 10000,
            'vat_amount_cents' => 0,
            'total_cents' => 10000,
            'currency' => 'ZAR',
            'line_items' => [
                ['description' => 'Work', 'quantity' => 1, 'unit_price_cents' => 10000, 'vat_rate' => 0],
            ],
            'notes' => null,
            'terms' => null,
            'sent_at' => null,
            'accepted_at' => null,
            'declined_at' => null,
            'converted_invoice_id' => null,
        ]);

        $response = $this->delete(route('invoicing.estimates.destroy', $estimate));

        $response->assertRedirect(route('invoicing.estimates.index'));
        $this->assertNull(Estimate::query()->find($estimate->id));
    }
}
