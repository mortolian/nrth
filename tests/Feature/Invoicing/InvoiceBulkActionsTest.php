<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_bulk_mark_sent_updates_drafts_and_stays_on_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $draftA = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Draft,
            'sent_at' => null,
        ]);
        $draftB = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Draft,
            'sent_at' => null,
        ]);
        $alreadySent = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now()->subDay(),
        ]);

        $response = $this->from(route('invoicing.invoices.index'))
            ->post(route('invoicing.invoices.bulk-mark-sent'), [
                'invoice_ids' => [$draftA->id, $draftB->id, $alreadySent->id],
            ]);

        $response->assertRedirect(route('invoicing.invoices.index'));
        $response->assertSessionHas('success');

        $this->assertSame(InvoiceStatus::Sent, $draftA->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $draftB->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $alreadySent->fresh()->status);
    }

    public function test_bulk_mark_sent_warns_when_no_drafts_selected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $sent = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
        ]);

        $response = $this->from(route('invoicing.invoices.index'))
            ->post(route('invoicing.invoices.bulk-mark-sent'), [
                'invoice_ids' => [$sent->id],
            ]);

        $response->assertRedirect(route('invoicing.invoices.index'));
        $response->assertSessionHas('warning');
        $this->assertSame(InvoiceStatus::Sent, $sent->fresh()->status);
    }

    public function test_bulk_void_voids_sent_invoices(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $sent = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
        ]);
        $draft = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->from(route('invoicing.invoices.index'))
            ->post(route('invoicing.invoices.bulk-void'), [
                'invoice_ids' => [$sent->id, $draft->id],
            ]);

        $response->assertRedirect(route('invoicing.invoices.index'));
        $response->assertSessionHas('success');
        $this->assertSame(InvoiceStatus::Void, $sent->fresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
    }

    public function test_viewer_cannot_bulk_mark_sent(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);
        EnsureTeamSystemRoles::ensureFor($team);

        $viewer = User::factory()->create();
        $team->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $team->id])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Draft,
        ]);

        $this->actingAs($viewer->fresh())
            ->post(route('invoicing.invoices.bulk-mark-sent'), [
                'invoice_ids' => [$invoice->id],
            ])
            ->assertForbidden();

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_bulk_actions_ignore_other_team_invoices(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $other = User::factory()->withPersonalTeam()->create();
        $otherTeam = $other->currentTeam;
        $this->assertNotNull($otherTeam);

        $own = Invoice::factory()->for($team)->create(['status' => InvoiceStatus::Draft]);
        $foreign = Invoice::factory()->for($otherTeam)->create(['status' => InvoiceStatus::Draft]);

        $this->from(route('invoicing.invoices.index'))
            ->post(route('invoicing.invoices.bulk-mark-sent'), [
                'invoice_ids' => [$own->id, $foreign->id],
            ])
            ->assertRedirect(route('invoicing.invoices.index'));

        $this->assertSame(InvoiceStatus::Sent, $own->fresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $foreign->fresh()->status);
    }
}
