<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionIndexTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_index_shows_external_reference_and_live_supplier_for_expenses(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $supplier = Supplier::factory()->for($team)->create(['name' => 'Current Supplier Name']);
        $expenseAccount = Account::factory()->for($team)->expense()->create(['code' => '7500']);
        $bankAccount = Account::factory()->for($team)->asset()->create(['code' => '1010']);

        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => $supplier->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'reference' => 'Old Supplier Snapshot',
            'description' => 'Office supplies',
            'expense_meta' => [
                'external_reference' => 'INV-4455',
            ],
            'transaction_date' => Carbon::parse('2026-07-01')->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $expenseAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => 1000,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bankAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => 1000,
            'currency' => 'ZAR',
        ]);

        $this->get(route('accounting.transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Transactions/Index')
                ->has('transactions.data', 1)
                ->where('transactions.data.0.id', $transaction->id)
                ->where('transactions.data.0.reference', 'INV-4455')
                ->where('transactions.data.0.supplier', 'Current Supplier Name')
                ->where('transactions.data.0.can_edit_expense', true));
    }

    public function test_index_falls_back_to_reference_for_free_text_expense_supplier(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => null,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'reference' => 'Walk-in Vendor',
            'description' => 'Misc',
            'expense_meta' => [
                'external_reference' => 'R-9',
            ],
            'transaction_date' => Carbon::parse('2026-07-02')->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->get(route('accounting.transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.id', $transaction->id)
                ->where('transactions.data.0.reference', 'R-9')
                ->where('transactions.data.0.supplier', 'Walk-in Vendor')
                ->where('transactions.data.0.can_edit_expense', true));
    }

    public function test_index_keeps_column_reference_for_non_expense_types(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Posted,
            'reference' => 'EFT-88',
            'description' => 'Invoice payment',
            'transaction_date' => Carbon::parse('2026-07-03')->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->get(route('accounting.transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.id', $transaction->id)
                ->where('transactions.data.0.reference', 'EFT-88')
                ->where('transactions.data.0.supplier', null)
                ->where('transactions.data.0.can_edit_expense', false));
    }

    public function test_index_search_matches_external_reference_and_supplier_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $supplier = Supplier::factory()->for($team)->create(['name' => 'Acme Trading']);

        $match = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => $supplier->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'reference' => 'Acme Trading',
            'description' => 'Matched expense',
            'expense_meta' => [
                'external_reference' => 'PO-777',
            ],
            'transaction_date' => Carbon::parse('2026-07-04')->toDateString(),
            'created_by' => $user->id,
        ]);

        Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'reference' => 'Other',
            'description' => 'Unrelated',
            'expense_meta' => [
                'external_reference' => 'ZZZ',
            ],
            'transaction_date' => Carbon::parse('2026-07-05')->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->get(route('accounting.transactions.index', ['search' => 'PO-777']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.id', $match->id));

        $this->get(route('accounting.transactions.index', ['search' => 'Acme Trading']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.id', $match->id));
    }

    public function test_account_statement_uses_external_reference_for_expenses(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $expenseAccount = Account::factory()->for($team)->expense()->create(['code' => '7500']);
        $bankAccount = Account::factory()->for($team)->asset()->create(['code' => '1010']);

        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'reference' => 'Supplier Snapshot',
            'description' => 'Stationery',
            'expense_meta' => [
                'external_reference' => 'RCP-12',
            ],
            'transaction_date' => Carbon::parse('2026-07-10')->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $expenseAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => 2500,
            'currency' => 'ZAR',
            'description' => 'Stationery',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bankAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => 2500,
            'currency' => 'ZAR',
        ]);

        $this->get(route('accounting.accounts.statement', [
            'account' => $expenseAccount,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Accounts/Statement')
                ->has('entries.data', 1)
                ->where('entries.data.0.reference', 'RCP-12'));
    }
}
