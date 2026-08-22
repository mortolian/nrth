<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\Services\VATService;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftInvoiceExclusionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_outstanding_excludes_drafts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($user->currentTeam);
        $team = $user->currentTeam;
        $client = Client::factory()->for($team)->create();

        Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
            'total_cents' => 50_000,
            'amount_paid_cents' => 0,
            'number' => 'INV-DRAFT-1',
        ]);

        Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'total_cents' => 25_000,
            'amount_paid_cents' => 0,
            'number' => 'INV-SENT-1',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('outstanding_invoices.data', 1)
                ->where('outstanding_invoices.data.0.number', 'INV-SENT-1')
                ->where('outstanding_invoices.data.0.amount', 25000)
                ->has('revenue_chart_meta.period_label')
                ->has('revenue_chart_meta.financial_year_label')
            );
    }

    public function test_client_outstanding_and_history_exclude_drafts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($user->currentTeam);
        $team = $user->currentTeam;
        $client = Client::factory()->for($team)->create();

        Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
            'total_cents' => 80_000,
            'amount_paid_cents' => 0,
            'vat_amount_cents' => 0,
            'subtotal_cents' => 80_000,
            'number' => 'INV-DRAFT-2',
            'issue_date' => now()->toDateString(),
        ]);

        Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'total_cents' => 10_000,
            'amount_paid_cents' => 0,
            'vat_amount_cents' => 0,
            'subtotal_cents' => 10_000,
            'number' => 'INV-SENT-2',
            'issue_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('invoicing.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients.data.0.outstanding_balance_cents', 10000)
            );

        $this->actingAs($user)
            ->get(route('invoicing.clients.show', $client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoice_history.data', 1)
                ->where('invoice_history.data.0.number', 'INV-SENT-2')
                ->where('stats_by_currency.0.outstanding_cents', 10000)
                ->where('stats_by_currency.0.invoiced_cents', 10000)
            );
    }

    public function test_vat_output_excludes_draft_invoices(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $client = Client::factory()->for($team)->create();

        Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
            'issue_date' => now()->toDateString(),
            'vat_amount_cents' => 15_00,
            'subtotal_cents' => 100_00,
            'total_cents' => 115_00,
        ]);

        Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'issue_date' => now()->toDateString(),
            'vat_amount_cents' => 7_50,
            'subtotal_cents' => 50_00,
            'total_cents' => 57_50,
        ]);

        $vat = app(VATService::class)->calculateOutputVAT(
            $team,
            now()->startOfMonth(),
            now()->endOfMonth(),
        );

        $this->assertSame(750, $vat->getMinorAmount()->toInt());
    }
}
