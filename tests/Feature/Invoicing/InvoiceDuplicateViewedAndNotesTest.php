<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\NoteTemplate;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDuplicateViewedAndNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pay_marks_sent_as_viewed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'sent_at' => Carbon::parse('2026-07-01'),
            'public_token' => 'c3d4e5f6789012345678abcdef012345',
            'viewed_at' => null,
        ]);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Viewed, $invoice->status);
        $this->assertNotNull($invoice->viewed_at);
    }

    public function test_public_pay_keeps_overdue_status(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Overdue,
            'public_token' => 'd4e5f6789012345678abcdef01234567',
            'viewed_at' => null,
        ]);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Overdue, $invoice->status);
        $this->assertNotNull($invoice->viewed_at);
    }

    public function test_public_pay_keeps_paid_status(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['payment_pages_enabled' => true],
            ),
        ])->save();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Paid,
            'public_token' => 'e5f6789012345678abcdef0123456789',
            'viewed_at' => null,
            'amount_paid_cents' => 10000,
            'total_cents' => 10000,
        ]);

        $this->get(route('public.invoice.pay', ['token' => $invoice->public_token]))->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->viewed_at);
    }

    public function test_note_template_crud_and_client_assignment_prefills_create(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;

        $this->actingAs($owner)
            ->post(route('settings.note-templates.store'), [
                'name' => 'Banking',
                'body' => '**Pay** to ABC Bank',
                'target' => 'notes',
                'is_active' => true,
                'sort_order' => 0,
            ])
            ->assertRedirect();

        $template = NoteTemplate::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($template);

        $client = Client::factory()->for($team)->create();
        $this->actingAs($owner)
            ->put(route('invoicing.clients.update', $client), [
                'name' => $client->name,
                'currency' => 'ZAR',
                'payment_terms_days' => 30,
                'is_active' => true,
                'note_template_ids' => [$template->id],
            ])
            ->assertRedirect();

        $client->refresh();
        $this->assertTrue($client->noteTemplates()->whereKey($template->id)->exists());

        $this->actingAs($owner)
            ->get(route('invoicing.invoices.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Form')
                ->has('note_templates', 1)
                ->where('clients.0.default_notes', '**Pay** to ABC Bank'));
    }

    public function test_viewer_cannot_manage_note_templates(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('settings.note-templates.index'))
            ->assertForbidden();
    }

    public function test_editing_template_does_not_rewrite_saved_invoice_notes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        $template = NoteTemplate::factory()->for($team)->create([
            'body' => 'Original body',
            'target' => 'notes',
        ]);
        $invoice = Invoice::factory()->for($team)->create([
            'notes' => 'Original body',
        ]);

        $this->actingAs($owner)
            ->put(route('settings.note-templates.update', $template), [
                'name' => $template->name,
                'body' => 'Changed later',
                'target' => 'notes',
                'is_active' => true,
                'sort_order' => 0,
            ])
            ->assertRedirect();

        $this->assertSame('Original body', $invoice->fresh()->notes);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function ownerAndMember(string $role): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return [$owner, $member];
    }
}
