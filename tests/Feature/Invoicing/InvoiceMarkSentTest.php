<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceMarkSentTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_draft_invoice_can_be_marked_sent_without_email_flow(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
            'sent_at' => null,
        ]);

        $response = $this->post(route('invoicing.invoices.mark-sent', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }
}
