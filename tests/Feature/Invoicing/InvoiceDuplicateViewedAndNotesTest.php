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

    public function test_public_pay_does_not_mark_sent_as_viewed(): void
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
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNull($invoice->viewed_at);
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
        $this->assertNull($invoice->viewed_at);
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
        $this->assertNull($invoice->viewed_at);
    }

    public function test_note_template_crud_and_client_default_notes_prefills_create(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;

        $this->actingAs($owner)
            ->post(route('settings.note-templates.store'), [
                'name' => 'Banking',
                'body' => '**Pay** to ABC Bank',
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
                'default_invoice_notes' => $template->body,
            ])
            ->assertRedirect();

        $client->refresh();
        $this->assertSame('**Pay** to ABC Bank', $client->default_invoice_notes);

        $this->actingAs($owner)
            ->get(route('invoicing.invoices.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Form')
                ->has('note_templates', 1)
                ->where('note_templates.0.body', '**Pay** to ABC Bank')
                ->where('clients.0.default_notes', '**Pay** to ABC Bank'));

        $this->actingAs($owner)
            ->get(route('invoicing.estimates.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Estimates/Form')
                ->has('note_templates', 1)
                ->where('clients.0.default_notes', '**Pay** to ABC Bank'));

        $this->actingAs($owner)
            ->get(route('invoicing.recurring.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Recurring/Form')
                ->has('note_templates', 1));
    }

    public function test_inactive_note_templates_are_excluded_from_document_forms(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;

        $active = NoteTemplate::factory()->for($team)->create([
            'name' => 'Active',
            'body' => 'Active body',
            'target' => 'notes',
            'is_active' => true,
        ]);
        NoteTemplate::factory()->for($team)->create([
            'name' => 'Inactive',
            'body' => 'Inactive body',
            'target' => 'notes',
            'is_active' => false,
        ]);

        Client::factory()->for($team)->create([
            'default_invoice_notes' => 'Client default notes',
        ]);

        $this->actingAs($owner)
            ->get(route('invoicing.invoices.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('note_templates', 1)
                ->where('note_templates.0.id', $active->id)
                ->where('clients.0.default_notes', 'Client default notes'));
    }

    public function test_note_template_can_be_updated_from_settings(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        $template = NoteTemplate::factory()->for($team)->create([
            'name' => 'Banking',
            'body' => 'Old body',
            'target' => 'notes',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->put(route('settings.note-templates.update', $template), [
                'name' => 'Banking details',
                'body' => '**Pay** to XYZ Bank',
                'is_active' => false,
                'sort_order' => 2,
            ])
            ->assertRedirect();

        $template->refresh();
        $this->assertSame('Banking details', $template->name);
        $this->assertSame('**Pay** to XYZ Bank', $template->body);
        $this->assertSame('notes', $template->target);
        $this->assertFalse($template->is_active);
        $this->assertSame(2, (int) $template->sort_order);
    }

    public function test_invoice_show_renders_markdown_html_for_notes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        $invoice = Invoice::factory()->for($team)->create([
            'notes' => '**Bold** banking',
            'footer' => 'Thanks',
        ]);

        $this->actingAs($owner)
            ->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Show')
                ->where('invoice.notes', '**Bold** banking')
                ->where('invoice.notes_html', fn ($html) => is_string($html) && str_contains($html, '<strong>Bold</strong>'))
                ->where('invoice.footer_html', fn ($html) => is_string($html) && str_contains($html, 'Thanks')));
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
