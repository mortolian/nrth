<?php

namespace Tests\Feature\Expenses;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
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
        $this->assertFalse((bool) $recurring->receipt_not_required);

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

    public function test_create_form_prefills_supplier_from_query(): void
    {
        [$owner, $team] = $this->ownerWithPayload();
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Prefill Landlord']);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['supplier_id' => $supplier->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Recurring/Form')
                ->where('recurring.supplier_id', $supplier->id)
                ->where('recurring.supplier_custom', '')
                ->where('recurring.prefill_source', 'supplier'));
    }

    public function test_create_form_prefills_from_expense(): void
    {
        [$owner, $team, $category, $banking] = $this->ownerWithPayload();
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Monthly Host']);

        $this->actingAs($owner)
            ->post(route('expenses.store'), [
                'date' => '2026-03-15',
                'supplier_id' => $supplier->id,
                'category_account_id' => $category->id,
                'description' => 'Hosting for March',
                'amount_excl_vat_cents' => 10000,
                'vat_rate' => 'vat15',
                'vat_amount_cents' => 1500,
                'paid_from_banking_account_id' => $banking->id,
                'reference' => 'INV-88',
                'notes' => 'Keep receipt',
            ])
            ->assertRedirect(route('expenses.index'));

        $expense = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($expense);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['expense_id' => $expense->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Recurring/Form')
                ->where('recurring.prefill_source', 'expense')
                ->where('recurring.supplier_id', $supplier->id)
                ->where('recurring.category_account_id', $category->id)
                ->where('recurring.paid_from_banking_account_id', $banking->id)
                ->where('recurring.amount_excl_vat_cents', 10000)
                ->where('recurring.vat_rate', 'vat15')
                ->where('recurring.vat_amount_cents', 1500)
                ->where('recurring.description', 'Hosting for March')
                ->where('recurring.notes', 'Keep receipt')
                ->where('recurring.reference', 'INV-88')
                ->where('recurring.frequency', 'monthly')
                ->where('recurring.generate_on_day', 15)
                ->where('recurring.generate_on_last_day', false));
    }

    public function test_create_form_clears_travel_category_when_prefilling_from_expense(): void
    {
        [$owner, $team, , $banking] = $this->ownerWithPayload();
        $travel = Account::factory()->for($team)->expense()->create([
            'code' => '7700',
            'name' => 'Travel',
        ]);

        $this->actingAs($owner)
            ->post(route('expenses.store'), [
                'date' => '2026-04-10',
                'supplier' => 'Airline',
                'category_account_id' => $travel->id,
                'description' => 'Flight',
                'amount_excl_vat_cents' => 50000,
                'vat_rate' => 'no_vat',
                'vat_amount_cents' => 0,
                'paid_from_banking_account_id' => $banking->id,
                'distance_km' => 10,
                'reference' => null,
                'notes' => null,
            ])
            ->assertRedirect(route('expenses.index'));

        $expense = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($expense);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['expense_id' => $expense->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recurring.prefill_source', 'expense')
                ->where('recurring.category_account_id', 0)
                ->where('recurring.description', 'Flight'));
    }

    public function test_create_form_prefills_from_bank_debit(): void
    {
        [$owner, $team, , $banking] = $this->ownerWithPayload();
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Corner Cafe']);
        $line = BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $banking->id,
            'transaction_date' => '2026-08-03',
            'description' => 'Corner Cafe',
            'amount' => '45.00',
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Debit,
            'source_hash' => hash('sha256', 'recurring-prefill-cafe'),
            'duplicate_key' => hash('sha256', 'recurring-prefill-cafe-key'),
        ]);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['banking_transaction_id' => $line->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Recurring/Form')
                ->where('recurring.prefill_source', 'banking')
                ->where('recurring.supplier_id', $supplier->id)
                ->where('recurring.supplier_custom', '')
                ->where('recurring.category_account_id', 0)
                ->where('recurring.paid_from_banking_account_id', $banking->id)
                ->where('recurring.amount_excl_vat_cents', 3913)
                ->where('recurring.vat_rate', 'vat15')
                ->where('recurring.vat_amount_cents', 587)
                ->where('recurring.description', 'Corner Cafe')
                ->where('recurring.frequency', 'monthly')
                ->where('recurring.generate_on_day', 3));
    }

    public function test_create_form_rejects_bank_credits_and_excluded_lines(): void
    {
        [$owner, $team, , $banking] = $this->ownerWithPayload();

        $credit = BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $banking->id,
            'transaction_date' => '2026-08-05',
            'description' => 'Salary',
            'amount' => '20000.00',
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Credit,
            'source_hash' => hash('sha256', 'recurring-prefill-credit'),
            'duplicate_key' => hash('sha256', 'recurring-prefill-credit-key'),
        ]);
        $excluded = BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $banking->id,
            'transaction_date' => '2026-08-06',
            'description' => 'Transfer',
            'amount' => '100.00',
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Debit,
            'source_hash' => hash('sha256', 'recurring-prefill-excluded'),
            'duplicate_key' => hash('sha256', 'recurring-prefill-excluded-key'),
        ]);
        $excluded->forceFill([
            'reconciliation_status' => ReconciliationStatus::Excluded,
            'exclusion_note' => 'Personal',
            'excluded_at' => now(),
        ])->save();

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['banking_transaction_id' => $credit->id]))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['banking_transaction_id' => $excluded->id]))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['banking_transaction_id' => 999999]))
            ->assertNotFound();
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

    public function test_store_persists_receipt_not_required_and_prefills_from_expense(): void
    {
        [$owner, $team, $category, $banking, $payload] = $this->ownerWithPayload();

        $this->actingAs($owner)
            ->post(route('expenses.recurring.store'), [
                ...$payload,
                'receipt_not_required' => true,
            ])
            ->assertRedirect();

        $recurring = RecurringExpense::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($recurring);
        $this->assertTrue((bool) $recurring->receipt_not_required);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.edit', $recurring))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Recurring/Form')
                ->where('recurring.receipt_not_required', true));

        $this->actingAs($owner)
            ->post(route('expenses.store'), [
                'date' => '2026-03-15',
                'supplier' => 'Fee Co',
                'category_account_id' => $category->id,
                'description' => 'Bank fee',
                'amount_excl_vat_cents' => 1000,
                'vat_rate' => 'no_vat',
                'vat_amount_cents' => 0,
                'paid_from_banking_account_id' => $banking->id,
                'receipt_not_required' => true,
            ])
            ->assertRedirect(route('expenses.index'));

        $expense = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($expense);

        $this->actingAs($owner)
            ->get(route('expenses.recurring.create', ['expense_id' => $expense->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Expenses/Recurring/Form')
                ->where('recurring.receipt_not_required', true));
    }
}
