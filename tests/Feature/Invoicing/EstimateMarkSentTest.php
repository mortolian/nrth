<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateMarkSentTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_draft_estimate_can_be_marked_sent_without_email_flow(): void
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
            'number' => 'EST-2026-1001',
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

        $response = $this->post(route('invoicing.estimates.mark-sent', $estimate));

        $response->assertRedirect();
        $estimate->refresh();
        $this->assertSame(EstimateStatus::Sent, $estimate->status);
        $this->assertNotNull($estimate->sent_at);
    }
}
