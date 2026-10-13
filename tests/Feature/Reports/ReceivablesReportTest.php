<?php

namespace Tests\Feature\Reports;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Reports\Services\AgedReceivablesPdfService;
use App\Domain\Reports\Services\ClientStatementPdfService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivablesReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_aged_receivables_buckets_open_invoices_and_ignores_later_payments(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $client = Client::factory()->for($team)->create(['name' => 'Northwind Studio']);

        $this->invoice($team->id, $client->id, 'INV-CURRENT', '2026-08-01', '2026-09-01', 2_000);
        $aged = $this->invoice($team->id, $client->id, 'INV-30', '2026-07-01', '2026-08-08', 10_000);
        $this->pay($team->id, $aged->id, 4_000, '2026-08-01');
        $this->pay($team->id, $aged->id, 1_000, '2026-08-20');
        $this->invoice($team->id, $client->id, 'INV-75', '2026-05-01', '2026-06-04', 5_000);
        $settled = $this->invoice($team->id, $client->id, 'INV-PAID', '2026-06-01', '2026-06-15', 3_000, InvoiceStatus::Paid);
        $this->pay($team->id, $settled->id, 3_000, '2026-06-10');
        $this->invoice($team->id, $client->id, 'INV-DRAFT', '2026-07-01', '2026-07-15', 9_000, InvoiceStatus::Draft);

        $other = User::factory()->withPersonalTeam()->create();
        $otherClient = Client::factory()->for($other->currentTeam)->create(['name' => 'Isolation Client']);
        $this->invoice($other->currentTeam->id, $otherClient->id, 'INV-OTHER', '2026-07-01', '2026-07-15', 8_000);

        $this->actingAs($user)
            ->get(route('reports.aged-receivables', ['as_of' => '2026-08-18']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/AgedReceivables')
                ->where('report.invoice_count', 3)
                ->where('report.totals.current', 2_000)
                ->where('report.totals.days_1_30', 6_000)
                ->where('report.totals.days_31_60', 0)
                ->where('report.totals.days_61_90', 5_000)
                ->where('report.totals.over_90', 0)
                ->where('report.totals.total', 13_000)
                ->where('report.clients.0.name', 'Northwind Studio')
                ->where('report.clients.0.total', 13_000));

        $html = view('pdf.aged-receivables', app(AgedReceivablesPdfService::class)->document($team, Carbon::parse('2026-08-18')))->render();
        $this->assertStringContainsString('Northwind Studio', $html);
        $this->assertStringContainsString('INV-30', $html);
        $this->assertStringNotContainsString('Isolation Client', $html);
        $this->assertStringNotContainsString('INV-DRAFT', $html);
        $this->assertStringNotContainsString('INV-PAID', $html);

        $this->actingAs($user)
            ->get(route('reports.aged-receivables.pdf', ['as_of' => '2026-08-18']))
            ->assertOk();
    }

    public function test_client_statement_carries_opening_balance_and_lists_period_activity(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $client = Client::factory()->for($team)->create(['name' => 'Northwind Studio']);
        $otherClient = Client::factory()->for($team)->create(['name' => 'Other Client']);

        $opening = $this->invoice($team->id, $client->id, 'INV-OPEN', '2026-07-01', '2026-07-31', 10_000);
        $this->pay($team->id, $opening->id, 2_000, '2026-07-20', 'PAY-JUL');
        $this->invoice($team->id, $client->id, 'INV-AUG', '2026-08-05', '2026-09-04', 4_000);
        $august = Invoice::queryWithoutTeamScope()->where('number', 'INV-AUG')->first();
        $this->pay($team->id, $august->id, 1_500, '2026-08-10', 'PAY-AUG');
        $this->invoice($team->id, $otherClient->id, 'INV-OTHER-CLIENT', '2026-08-06', '2026-09-05', 7_000);
        $stranger = User::factory()->withPersonalTeam()->create();
        $strangerClient = Client::factory()->for($stranger->currentTeam)->create(['name' => 'Stranger Client']);

        $this->actingAs($user)
            ->get(route('reports.client-statement', [
                'client_id' => $client->id,
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/ClientStatement')
                ->where('statement.client.name', 'Northwind Studio')
                ->where('statement.opening_cents', 8_000)
                ->where('statement.charges_cents', 4_000)
                ->where('statement.credits_cents', 1_500)
                ->where('statement.closing_cents', 10_500)
                ->where('statement.lines.0.reference', 'INV-AUG')
                ->where('statement.lines.1.reference', 'PAY-AUG'));

        $html = view('pdf.client-statement', app(ClientStatementPdfService::class)->document(
            $team,
            $client,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
        ))->render();
        $this->assertStringContainsString('Northwind Studio', $html);
        $this->assertStringContainsString('INV-AUG', $html);
        $this->assertStringNotContainsString('INV-OTHER-CLIENT', $html);

        $this->actingAs($user)
            ->get(route('reports.client-statement.pdf', [
                'client_id' => $client->id,
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('reports.client-statement', ['client_id' => $strangerClient->id]))
            ->assertNotFound();
    }

    private function invoice(
        int $teamId,
        int $clientId,
        string $number,
        string $issued,
        string $due,
        int $total,
        InvoiceStatus $status = InvoiceStatus::Sent,
    ): Invoice {
        return Invoice::factory()->create([
            'team_id' => $teamId,
            'client_id' => $clientId,
            'status' => $status,
            'number' => $number,
            'issue_date' => $issued,
            'due_date' => $due,
            'subtotal_cents' => $total,
            'vat_amount_cents' => 0,
            'total_cents' => $total,
            'amount_paid_cents' => 0,
            'currency' => 'ZAR',
        ]);
    }

    private function pay(int $teamId, int $invoiceId, int $amount, string $date, ?string $reference = null): void
    {
        Payment::factory()->create([
            'team_id' => $teamId,
            'invoice_id' => $invoiceId,
            'amount_cents' => $amount,
            'currency' => 'ZAR',
            'payment_date' => $date,
            'reference' => $reference,
        ]);
    }
}
