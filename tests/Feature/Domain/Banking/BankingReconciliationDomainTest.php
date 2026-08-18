<?php

namespace Tests\Feature\Domain\Banking;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\AllocateBankingTransactionAction;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Services\BankingReconciliationTotals;
use App\Domain\Banking\Services\SuggestReconciliationCandidates;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankingReconciliationDomainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team, 2: BankingAccount, 3: Account, 4: Account}
     */
    private function setupTeam(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $expense = Account::factory()->for($team)->expense()->create(['code' => '7500']);
        $bankGl = Account::factory()->for($team)->asset()->create(['code' => '1010', 'is_system' => true]);
        $banking = BankingAccount::factory()->for($team)->create(['gl_account_id' => $bankGl->id]);

        return [$user, $team, $banking, $expense, $bankGl];
    }

    private function bankLine(
        Team $team,
        BankingAccount $account,
        string $amount,
        string $date = '2026-08-12',
        TransactionDirection $direction = TransactionDirection::Debit,
        string $description = 'Card purchase Corner Cafe',
    ): BankingTransaction {
        return BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => $description,
            'reference' => 'REF-CAFE',
            'amount' => $amount,
            'currency' => 'ZAR',
            'direction' => $direction,
            'source_hash' => hash('sha256', $amount.$date.uniqid('', true)),
            'duplicate_key' => hash('sha256', 'dup-'.$amount.$date.uniqid('', true)),
        ]);
    }

    private function postedExpense(Team $team, User $user, Account $expense, Account $bankGl, string $date, int $cents, string $description): Transaction
    {
        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'description' => $description,
            'transaction_date' => $date,
            'posted_at' => now(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $expense->id,
            'type' => EntryType::Debit,
            'amount_cents' => $cents,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bankGl->id,
            'type' => EntryType::Credit,
            'amount_cents' => $cents,
            'currency' => 'ZAR',
        ]);

        return $transaction->fresh(['journalEntries']);
    }

    public function test_candidates_prefer_exact_amount_and_nearby_date(): void
    {
        [$user, $team, $banking, $expense, $bankGl] = $this->setupTeam();
        $bankLine = $this->bankLine($team, $banking, '45.00', '2026-08-12');

        $exact = $this->postedExpense($team, $user, $expense, $bankGl, '2026-08-12', 4500, 'Corner Cafe lunch');
        $this->postedExpense($team, $user, $expense, $bankGl, '2026-08-20', 9000, 'Unrelated');

        $candidates = app(SuggestReconciliationCandidates::class)->for($bankLine);
        $this->assertNotEmpty($candidates);
        $this->assertSame($exact->id, $candidates[0]['transaction']->id);
        $this->assertSame(4500, $candidates[0]['suggested_amount_cents']);
    }

    public function test_allocate_rejects_amount_above_remaining_transaction(): void
    {
        [$user, $team, $banking, $expense, $bankGl] = $this->setupTeam();
        $bankLine = $this->bankLine($team, $banking, '80.00');
        $txn = $this->postedExpense($team, $user, $expense, $bankGl, '2026-08-12', 4500, 'Cafe');

        $this->expectException(ValidationException::class);
        app(AllocateBankingTransactionAction::class)->execute(
            $bankLine,
            $txn,
            8000,
            (int) $team->id,
            (int) $user->id,
        );
    }

    public function test_transfer_can_be_matched_on_both_bank_accounts(): void
    {
        [$user, $team, $fromBanking, , $fromGl] = $this->setupTeam();

        $toGl = Account::factory()->for($team)->asset()->create(['code' => '1020', 'is_system' => true]);
        $toBanking = BankingAccount::factory()->for($team)->create(['gl_account_id' => $toGl->id]);

        $transfer = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Transfer,
            'status' => TransactionStatus::Posted,
            'description' => 'Move to savings',
            'transaction_date' => '2026-08-12',
            'posted_at' => now(),
            'created_by' => $user->id,
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transfer->id,
            'account_id' => $toGl->id,
            'type' => EntryType::Debit,
            'amount_cents' => 10000,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transfer->id,
            'account_id' => $fromGl->id,
            'type' => EntryType::Credit,
            'amount_cents' => 10000,
            'currency' => 'ZAR',
        ]);
        $transfer = $transfer->fresh(['journalEntries']);
        $this->assertNotNull($transfer);

        $outflow = $this->bankLine(
            $team,
            $fromBanking,
            '100.00',
            '2026-08-12',
            TransactionDirection::Debit,
            'Transfer to savings',
        );
        $inflow = $this->bankLine(
            $team,
            $toBanking,
            '100.00',
            '2026-08-12',
            TransactionDirection::Credit,
            'Transfer from cheque',
        );

        app(AllocateBankingTransactionAction::class)->execute(
            $outflow,
            $transfer,
            10000,
            (int) $team->id,
            (int) $user->id,
        );

        $totals = app(BankingReconciliationTotals::class);
        $this->assertSame(0, $totals->remainingTransactionCents($transfer->fresh(['journalEntries']), $outflow));
        $this->assertSame(10000, $totals->remainingTransactionCents($transfer->fresh(['journalEntries']), $inflow));

        $candidates = app(SuggestReconciliationCandidates::class)->for($inflow);
        $this->assertNotEmpty($candidates);
        $this->assertSame($transfer->id, $candidates[0]['transaction']->id);
        $this->assertSame(10000, $candidates[0]['suggested_amount_cents']);

        app(AllocateBankingTransactionAction::class)->execute(
            $inflow,
            $transfer,
            10000,
            (int) $team->id,
            (int) $user->id,
        );

        $this->assertSame(0, $totals->remainingTransactionCents($transfer->fresh(['journalEntries']), $inflow));
    }
}
