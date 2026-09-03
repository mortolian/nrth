<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceIndexSortTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_invoice_index_sorts_by_total_ascending(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['name' => 'Acme']);
        $cheap = Invoice::factory()->for($team)->for($client)->create([
            'number' => 'INV-LOW',
            'status' => InvoiceStatus::Sent,
            'total_cents' => 100_00,
            'amount_paid_cents' => 0,
            'issue_date' => '2026-01-10',
        ]);
        $expensive = Invoice::factory()->for($team)->for($client)->create([
            'number' => 'INV-HIGH',
            'status' => InvoiceStatus::Sent,
            'total_cents' => 900_00,
            'amount_paid_cents' => 0,
            'issue_date' => '2026-01-01',
        ]);

        $this->get(route('invoicing.invoices.index', [
            'sort' => 'total',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoicing/Invoices/Index')
                ->where('filters.sort', 'total')
                ->where('filters.direction', 'asc')
                ->has('invoices.data', 2)
                ->where('invoices.data.0.id', $cheap->id)
                ->where('invoices.data.1.id', $expensive->id)
            );
    }

    public function test_invoice_index_sorts_by_client_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $zeta = Client::factory()->for($team)->create(['name' => 'Zeta Co']);
        $alpha = Client::factory()->for($team)->create(['name' => 'Alpha Co']);
        $zetaInvoice = Invoice::factory()->for($team)->for($zeta)->create([
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-02-01',
        ]);
        $alphaInvoice = Invoice::factory()->for($team)->for($alpha)->create([
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-01-01',
        ]);

        $this->get(route('invoicing.invoices.index', [
            'sort' => 'client',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoicing/Invoices/Index')
                ->where('invoices.data.0.id', $alphaInvoice->id)
                ->where('invoices.data.1.id', $zetaInvoice->id)
            );
    }

    public function test_invoice_index_falls_back_to_issue_date_for_unknown_sort(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $older = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-01-01',
        ]);
        $newer = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-03-01',
        ]);

        $this->get(route('invoicing.invoices.index', [
            'sort' => 'not-a-column',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoicing/Invoices/Index')
                ->where('filters.sort', 'issue')
                ->where('invoices.data.0.id', $older->id)
                ->where('invoices.data.1.id', $newer->id)
            );
    }
}
