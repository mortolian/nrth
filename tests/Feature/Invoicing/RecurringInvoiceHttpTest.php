<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringInvoiceHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_store_show_and_generate_recurring(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $client = Client::factory()->for($owner->currentTeam)->create([
            'email' => 'c@example.com',
            'is_active' => true,
        ]);

        $payload = [
            'client_id' => $client->id,
            'frequency' => 'monthly',
            'generate_on_weekday' => null,
            'generate_on_day' => 1,
            'generate_on_last_day' => false,
            'generate_on_month' => null,
            'limit_type' => 'none',
            'limit_count' => null,
            'limit_end_date' => null,
            'next_run_date' => '2026-07-01',
            'auto_send' => false,
            'period_offset_months' => 0,
            'due_date_rule' => 'days_after_issue',
            'due_days' => 10,
            'due_on_day' => null,
            'currency' => 'ZAR',
            'reference' => null,
            'notes' => null,
            'footer' => null,
            'line_items' => [[
                'description' => 'Rent for {{month_year}}',
                'quantity' => 1,
                'unit_price_cents' => 10000,
                'vat_rate' => 0,
                'item_id' => null,
            ]],
        ];

        $this->actingAs($owner)
            ->post(route('invoicing.recurring.store'), $payload)
            ->assertRedirect();

        $recurring = RecurringInvoice::queryWithoutTeamScope()->first();
        $this->assertNotNull($recurring);

        $this->actingAs($owner)
            ->get(route('invoicing.recurring.show', $recurring))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('invoicing.recurring.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Invoicing/Recurring/Index')->has('recurring.data', 1));

        $this->actingAs($owner)
            ->post(route('invoicing.recurring.generate', $recurring))
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'recurring_invoice_id' => $recurring->id,
        ]);
    }

    public function test_store_rejects_invalid_payload_with_errors(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $client = Client::factory()->for($owner->currentTeam)->create();

        $this->actingAs($owner)
            ->from(route('invoicing.recurring.create'))
            ->post(route('invoicing.recurring.store'), [
                'client_id' => $client->id,
                'frequency' => 'monthly',
                'generate_on_last_day' => false,
                'auto_send' => false,
                'period_offset_months' => 0,
                'due_date_rule' => 'client_terms',
                'limit_type' => 'none',
                'next_run_date' => '2026-07-01',
                'currency' => 'ZAR',
                'line_items' => [
                    [
                        'description' => '',
                        'quantity' => 0,
                        'unit_price_cents' => -1,
                    ],
                ],
            ])
            ->assertRedirect(route('invoicing.recurring.create'))
            ->assertSessionHasErrors(['line_items.0.description', 'line_items.0.quantity', 'line_items.0.unit_price_cents']);
    }

    public function test_create_form_renders(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        Client::factory()->for($owner->currentTeam)->create();

        $this->actingAs($owner)
            ->get(route('invoicing.recurring.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Invoicing/Recurring/Form'));
    }

    public function test_store_persists_line_discounts_and_income_account(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($owner->currentTeam);

        $income = Account::factory()->for($owner->currentTeam)->create([
            'type' => AccountType::Income,
            'code' => '4200',
            'name' => 'Consulting',
            'is_active' => true,
        ]);

        $client = Client::factory()->for($owner->currentTeam)->create([
            'email' => 'c@example.com',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('invoicing.recurring.store'), [
                'client_id' => $client->id,
                'frequency' => 'monthly',
                'generate_on_weekday' => null,
                'generate_on_day' => 1,
                'generate_on_last_day' => false,
                'generate_on_month' => null,
                'limit_type' => 'none',
                'limit_count' => null,
                'limit_end_date' => null,
                'next_run_date' => '2026-07-01',
                'auto_send' => false,
                'period_offset_months' => 0,
                'due_date_rule' => 'days_after_issue',
                'due_days' => 10,
                'due_on_day' => null,
                'currency' => 'ZAR',
                'reference' => null,
                'notes' => null,
                'footer' => null,
                'line_items' => [[
                    'description' => 'Retainer for {{month_year}}',
                    'quantity' => 1,
                    'unit_price_cents' => 20000,
                    'vat_rate' => 0,
                    'item_id' => null,
                    'discount_type' => 'percent',
                    'discount_percent' => 5,
                    'discount_cents' => 9999,
                    'income_account_id' => $income->id,
                ]],
            ])
            ->assertRedirect();

        $recurring = RecurringInvoice::queryWithoutTeamScope()->first();
        $this->assertNotNull($recurring);
        $line = $recurring->line_items[0];
        $this->assertSame('percent', $line['discount_type']);
        $this->assertSame(5.0, (float) $line['discount_percent']);
        $this->assertNull($line['discount_cents']);
        $this->assertSame($income->id, (int) $line['income_account_id']);
    }
}
