<?php

namespace Tests\Feature\Banking;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankingTransactionIndexTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_index_lists_imported_transactions_with_filters(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $accountA = BankingAccount::factory()->for($team)->create(['name' => 'Cheque']);
        $accountB = BankingAccount::factory()->for($team)->create(['name' => 'Savings']);

        BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $accountA->id,
            'transaction_date' => '2026-01-10',
            'description' => 'Rent payment',
            'amount' => '1500.00',
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Debit,
            'source_hash' => hash('sha256', 'rent-a'),
            'duplicate_key' => hash('sha256', 'rent-a-key'),
        ]);

        BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $accountB->id,
            'transaction_date' => '2026-01-15',
            'description' => 'Salary',
            'amount' => '20000.00',
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Credit,
            'source_hash' => hash('sha256', 'salary-b'),
            'duplicate_key' => hash('sha256', 'salary-b-key'),
        ]);

        $this->get(route('banking.transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Banking/Transactions/Index')
                ->has('transactions.data', 2)
                ->where('transactions.total', 2)
            );

        $this->get(route('banking.transactions.index', ['account_id' => $accountA->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.description', 'Rent payment')
            );

        $this->get(route('banking.transactions.index', ['search' => 'Salary']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.description', 'Salary')
            );

        $this->get(route('banking.transactions.index', ['search' => 'salary']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.description', 'Salary')
            );
    }

    public function test_search_is_case_insensitive_for_reference(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $account = BankingAccount::factory()->for($team)->create();

        BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $account->id,
            'transaction_date' => '2026-02-01',
            'description' => 'Card purchase',
            'reference' => 'REF-ABC-99',
            'amount' => '99.00',
            'currency' => 'ZAR',
            'direction' => TransactionDirection::Debit,
            'source_hash' => hash('sha256', 'card'),
            'duplicate_key' => hash('sha256', 'card-key'),
        ]);

        $this->get(route('banking.transactions.index', ['search' => 'ref-abc']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.reference', 'REF-ABC-99')
            );
    }
}
