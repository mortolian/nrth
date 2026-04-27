<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceStoreTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_authenticated_user_can_store_draft_invoice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();

        $issue = '2026-04-26';
        $due = '2026-05-26';

        $response = $this->post(route('invoicing.invoices.store'), [
            'client_id' => $client->id,
            'number' => 'INV-TEST-0001',
            'reference' => null,
            'issue_date' => $issue,
            'due_date' => $due,
            'notes' => null,
            'footer' => null,
            'line_items' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 2,
                    'unit_price_cents' => 50000,
                    'vat_rate' => 0.15,
                ],
            ],
        ]);

        $invoice = Invoice::query()->where('team_id', $team->id)->first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $this->assertSame($client->id, $invoice->client_id);
        $this->assertSame(100000, (int) $invoice->getRawOriginal('subtotal_cents'));
    }
}
