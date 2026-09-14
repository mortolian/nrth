<?php

namespace Tests\Feature\Expenses;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringExpenseHttpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team, 2: Account, 3: BankingAccount, 4: array<string, mixed>}
     */
    private function ownerWithPayload(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);
        EnsureTeamSystemRoles::ensureFor($team);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);

        $category = Account::factory()->for($team)->expense()->create([
            'code' => '7600',
            'name' => 'Software',
        ]);
        $bankGl = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1010')
            ->first();
        if ($bankGl === null) {
            $bankGl = Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank']);
        }
        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'Operating bank',
            'gl_account_id' => $bankGl->id,
            'is_active' => true,
        ]);

        $payload = [
            'supplier' => 'SaaS Co',
            'category_account_id' => $category->id,
            'paid_from_banking_account_id' => $banking->id,
            'frequency' => 'monthly',
            'generate_on_weekday' => null,
            'generate_on_day' => 1,
            'generate_on_last_day' => false,
            'generate_on_month' => null,
            'limit_type' => 'none',
            'limit_count' => null,
            'limit_end_date' => null,
            'next_run_date' => '2026-07-01',
            'period_offset_months' => 0,
            'description' => 'Subscription for {{month_year}}',
            'notes' => null,
            'reference' => null,
            'amount_excl_vat_cents' => 19900,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 2985,
        ];

        return [$owner, $team, $category, $banking, $payload];
    }

    public function test_owner_can_store_show_and_generate_recurring(): void
    {
        [$owner, $team, , , $payload] = $this->ownerWithPayload();

        $this->actingAs($owner)
            ->post(route('expenses.recurring.store'), $payload)
            ->assertRedirect();

        $recurring = RecurringExpense::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->first();
        $this->assertNotNull($recurring);
        $this->assertSame('SaaS Co', $recurring->supplier_name);
        $this->assertSame(19900, (int) $recurring->amount_excl_vat_cents);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.show', $recurring))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Expenses/Recurring/Show'));

        $this->actingAs($owner)
            ->get(route('expenses.recurring.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Expenses/Recurring/Index')->has('recurring.data', 1));

        $this->actingAs($owner)
            ->post(route('expenses.recurring.generate', $recurring))
            ->assertRedirect();

        $this->assertDatabaseHas('transactions', [
            'recurring_expense_id' => $recurring->id,
        ]);

        $expense = Transaction::queryWithoutTeamScope()
            ->where('recurring_expense_id', $recurring->id)
            ->first();
        $this->assertNotNull($expense);
        $this->assertStringStartsWith('Subscription for ', (string) $expense->description);
    }

    public function test_store_rejects_invalid_payload_with_errors(): void
    {
        [$owner, , $category, $banking] = $this->ownerWithPayload();

        $this->actingAs($owner)
            ->from(route('expenses.recurring.create'))
            ->post(route('expenses.recurring.store'), [
                'category_account_id' => $category->id,
                'paid_from_banking_account_id' => $banking->id,
                'frequency' => 'monthly',
                'generate_on_last_day' => false,
                'period_offset_months' => 0,
                'limit_type' => 'none',
                'next_run_date' => '2026-07-01',
                'amount_excl_vat_cents' => 1000,
                'vat_rate' => 'no_vat',
                'vat_amount_cents' => 0,
            ])
            ->assertRedirect(route('expenses.recurring.create'))
            ->assertSessionHasErrors(['supplier']);
    }

    public function test_create_form_renders(): void
    {
        [$owner] = $this->ownerWithPayload();

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Expenses/Recurring/Form'));
    }

    public function test_store_links_supplier_and_rejects_travel_category(): void
    {
        [$owner, $team, , $banking] = $this->ownerWithPayload();
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Linked Vendor']);
        $travel = Account::factory()->for($team)->expense()->create([
            'code' => '7700',
            'name' => 'Travel',
        ]);

        $this->actingAs($owner)
            ->post(route('expenses.recurring.store'), [
                'supplier_id' => $supplier->id,
                'category_account_id' => $travel->id,
                'paid_from_banking_account_id' => $banking->id,
                'frequency' => 'monthly',
                'generate_on_day' => 1,
                'generate_on_last_day' => false,
                'limit_type' => 'none',
                'next_run_date' => '2026-07-01',
                'period_offset_months' => 0,
                'description' => 'Trip',
                'amount_excl_vat_cents' => 1000,
                'vat_rate' => 'no_vat',
                'vat_amount_cents' => 0,
            ])
            ->assertSessionHasErrors(['category_account_id']);

        $software = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '7600')
            ->firstOrFail();

        $this->actingAs($owner)
            ->post(route('expenses.recurring.store'), [
                'supplier_id' => $supplier->id,
                'category_account_id' => $software->id,
                'paid_from_banking_account_id' => $banking->id,
                'frequency' => 'monthly',
                'generate_on_day' => 1,
                'generate_on_last_day' => false,
                'limit_type' => 'none',
                'next_run_date' => '2026-07-01',
                'period_offset_months' => 0,
                'description' => 'Software',
                'amount_excl_vat_cents' => 5000,
                'vat_rate' => 'no_vat',
                'vat_amount_cents' => 0,
            ])
            ->assertRedirect();

        $recurring = RecurringExpense::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($recurring);
        $this->assertSame($supplier->id, (int) $recurring->supplier_id);
        $this->assertNull($recurring->supplier_name);
    }
}
