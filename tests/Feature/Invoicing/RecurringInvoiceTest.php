<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Actions\GenerateRecurringInvoiceAction;
use App\Domain\Invoicing\Enums\RecurringDueDateRule;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Domain\Invoicing\Services\RecurringDueDateResolver;
use App\Domain\Invoicing\Services\RecurringPlaceholderResolver;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholders_and_due_rules(): void
    {
        $issue = Carbon::parse('2026-07-01');
        $due = Carbon::parse('2026-07-31');

        $text = RecurringPlaceholderResolver::replace(
            'Rent for {{month_year}} due {{due_date}}',
            $issue,
            $due,
            -1,
        );

        $this->assertSame('Rent for June 2026 due 2026-07-31', $text);

        $resolver = app(RecurringDueDateResolver::class);
        $this->assertSame(
            '2026-07-11',
            $resolver->resolve($issue, RecurringDueDateRule::DaysAfterIssue, 10, null, 30)->toDateString()
        );
        $this->assertSame(
            '2026-07-31',
            $resolver->resolve($issue, RecurringDueDateRule::LastDayOfMonth, null, null, 30)->toDateString()
        );
    }

    public function test_generate_creates_invoice_and_advances_next_run(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $teamId = (int) $owner->current_team_id;
        $client = Client::factory()->create(['team_id' => $teamId, 'email' => 'client@example.com', 'payment_terms_days' => 30]);

        $recurring = RecurringInvoice::factory()->create([
            'team_id' => $teamId,
            'client_id' => $client->id,
            'frequency' => RecurringFrequency::Monthly,
            'generate_on_day' => 1,
            'next_run_date' => '2026-07-01',
            'due_date_rule' => RecurringDueDateRule::DaysAfterIssue,
            'due_days' => 10,
            'period_offset_months' => 0,
            'line_items' => [[
                'description' => 'Fee for {{month_year}}',
                'quantity' => 1,
                'unit_price_cents' => 10000,
                'vat_rate' => 0,
            ]],
        ]);

        $invoice = app(GenerateRecurringInvoiceAction::class)->execute($recurring, Carbon::parse('2026-07-01'));
        $this->assertNotNull($invoice);
        $this->assertSame('Fee for July 2026', $invoice->lineItems()->first()->description);
        $this->assertSame('2026-07-01', $invoice->issue_date->toDateString());
        $this->assertSame('2026-07-11', $invoice->due_date->toDateString());
        $this->assertSame($recurring->id, (int) $invoice->recurring_invoice_id);

        $recurring->refresh();
        $this->assertSame(1, (int) $recurring->generated_count);
        $this->assertSame('2026-08-01', $recurring->next_run_date->toDateString());
    }

    public function test_on_hold_and_count_limit(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $teamId = (int) $owner->current_team_id;
        $client = Client::factory()->create(['team_id' => $teamId]);

        $held = RecurringInvoice::factory()->create([
            'team_id' => $teamId,
            'client_id' => $client->id,
            'status' => RecurringInvoiceStatus::OnHold,
            'next_run_date' => now()->toDateString(),
        ]);
        $this->assertNull(app(GenerateRecurringInvoiceAction::class)->execute($held));

        $limited = RecurringInvoice::factory()->create([
            'team_id' => $teamId,
            'client_id' => $client->id,
            'limit_type' => RecurringLimitType::Count,
            'limit_count' => 1,
            'next_run_date' => '2026-07-01',
            'generate_on_day' => 1,
            'line_items' => [[
                'description' => 'One-off',
                'quantity' => 1,
                'unit_price_cents' => 1000,
                'vat_rate' => 0,
            ]],
        ]);
        app(GenerateRecurringInvoiceAction::class)->execute($limited, Carbon::parse('2026-07-01'));
        $limited->refresh();
        $this->assertSame(RecurringInvoiceStatus::Completed, $limited->status);
    }

    public function test_viewer_cannot_manage_recurring(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $viewer = User::factory()->create();
        $owner->currentTeam->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($viewer)
            ->get(route('invoicing.recurring.create'))
            ->assertForbidden();
    }
}
