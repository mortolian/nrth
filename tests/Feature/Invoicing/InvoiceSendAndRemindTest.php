<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Mail\InvoiceMailer;
use App\Mail\InvoiceReminderMailer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class InvoiceSendAndRemindTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    private function mockPdfService(): void
    {
        $pdfService = Mockery::mock(InvoicePdfService::class);
        $pdfService->shouldReceive('generate')->andReturn(null);
        $this->app->instance(InvoicePdfService::class, $pdfService);
    }

    public function test_send_queues_mail_and_marks_draft_sent(): void
    {
        Mail::fake();
        $this->mockPdfService();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['email' => 'client@example.com']);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
            'sent_at' => null,
        ]);

        $response = $this->post(route('invoicing.invoices.send', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $response->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
        Mail::assertQueued(InvoiceMailer::class);
    }

    public function test_send_requires_client_email(): void
    {
        Mail::fake();
        $this->mockPdfService();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['email' => null]);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->from(route('invoicing.invoices.show', $invoice))
            ->post(route('invoicing.invoices.send', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $response->assertSessionHasErrors('email');
        Mail::assertNothingQueued();
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_resend_preserves_partial_status(): void
    {
        Mail::fake();
        $this->mockPdfService();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['email' => 'client@example.com']);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Partial,
            'sent_at' => now()->subDay(),
            'total_cents' => 100_00,
            'amount_paid_cents' => 40_00,
        ]);

        $response = $this->post(route('invoicing.invoices.send', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $this->assertSame(InvoiceStatus::Partial, $invoice->fresh()->status);
        Mail::assertQueued(InvoiceMailer::class);
    }

    public function test_resend_allows_paid_invoice(): void
    {
        Mail::fake();
        $this->mockPdfService();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['email' => 'client@example.com']);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Paid,
            'sent_at' => now()->subDays(2),
            'paid_at' => now()->subDay(),
            'total_cents' => 100_00,
            'amount_paid_cents' => 100_00,
        ]);

        $response = $this->post(route('invoicing.invoices.send', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $response->assertSessionHas('success');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        Mail::assertQueued(InvoiceMailer::class);
    }

    public function test_remind_queues_reminder_mail(): void
    {
        Mail::fake();
        $this->mockPdfService();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['email' => 'client@example.com']);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'sent_at' => now()->subDay(),
            'total_cents' => 100_00,
            'amount_paid_cents' => 0,
        ]);

        $response = $this->post(route('invoicing.invoices.remind', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $response->assertSessionHas('success');
        Mail::assertQueued(InvoiceReminderMailer::class);
        Mail::assertNotQueued(InvoiceMailer::class);
    }

    public function test_remind_rejects_draft_invoice(): void
    {
        Mail::fake();
        $this->mockPdfService();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create(['email' => 'client@example.com']);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->from(route('invoicing.invoices.show', $invoice))
            ->post(route('invoicing.invoices.remind', $invoice));

        $response->assertRedirect(route('invoicing.invoices.show', $invoice));
        $response->assertSessionHasErrors('status');
        Mail::assertNothingQueued();
    }
}
