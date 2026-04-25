<?php

namespace Tests\Feature\Domain\Accounting;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Exceptions\UnbalancedTransactionException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Models\Team;
use App\Models\User;
use Brick\Money\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostTransactionActionTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_balanced_transaction_posts_successfully(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $expense = Account::factory()->for($team)->expense()->create(['code' => '9001', 'name' => 'Test expense']);
        $equity = Account::factory()->for($team)->equity()->create(['code' => '9002', 'name' => 'Test equity']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::JournalAdjustment,
            'status' => TransactionStatus::Draft,
            'reference' => 'TEST-1',
            'description' => 'Balanced pair',
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $expense->id,
            'type' => EntryType::Debit,
            'amount_cents' => 10_000,
            'currency' => 'ZAR',
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $equity->id,
            'type' => EntryType::Credit,
            'amount_cents' => 10_000,
            'currency' => 'ZAR',
        ]);

        $ledger = new LedgerService;
        $posted = (new PostTransactionAction($ledger))->execute($transaction->fresh());

        $this->assertSame(TransactionStatus::Posted, $posted->status);
        $this->assertNotNull($posted->posted_at);
    }

    public function test_unbalanced_transaction_throws(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $a = Account::factory()->for($team)->asset()->create(['code' => '9101']);
        $b = Account::factory()->for($team)->asset()->create(['code' => '9102']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::JournalAdjustment,
            'status' => TransactionStatus::Draft,
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $a->id,
            'type' => EntryType::Debit,
            'amount_cents' => 5_000,
            'currency' => 'ZAR',
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $b->id,
            'type' => EntryType::Credit,
            'amount_cents' => 3_000,
            'currency' => 'ZAR',
        ]);

        $this->expectException(UnbalancedTransactionException::class);

        (new PostTransactionAction(new LedgerService))->execute($transaction->fresh());
    }

    public function test_void_creates_reversing_entries_and_restores_net_balance(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $expense = Account::factory()->for($team)->expense()->create(['code' => '9201']);
        $equity = Account::factory()->for($team)->equity()->create(['code' => '9202']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Draft,
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $expense->id,
            'type' => EntryType::Debit,
            'amount_cents' => 7_500,
            'currency' => 'ZAR',
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $equity->id,
            'type' => EntryType::Credit,
            'amount_cents' => 7_500,
            'currency' => 'ZAR',
        ]);

        $ledger = new LedgerService;
        $post = new PostTransactionAction($ledger);
        $void = new VoidTransactionAction($post);

        $post->execute($transaction->fresh());

        $balanceAfterPost = $ledger->getBalance($expense);
        $this->assertTrue($balanceAfterPost->isEqualTo(Money::ofMinor(7_500, 'ZAR')));

        $void->execute($transaction->fresh(), 'Customer cancelled');

        $this->assertSame(TransactionStatus::Void, $transaction->fresh()->status);

        $balanceAfterVoid = $ledger->getBalance($expense);
        $this->assertTrue($balanceAfterVoid->isZero());
    }

    public function test_balance_matches_posted_lines(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $asset = Account::factory()->for($team)->asset()->create(['code' => '9301']);
        $liability = Account::factory()->for($team)->liability()->create(['code' => '9302']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Draft,
            'transaction_date' => Carbon::parse('2026-01-15')->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $asset->id,
            'type' => EntryType::Debit,
            'amount_cents' => 2_000,
            'currency' => 'ZAR',
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $liability->id,
            'type' => EntryType::Credit,
            'amount_cents' => 2_000,
            'currency' => 'ZAR',
        ]);

        $ledger = new LedgerService;
        (new PostTransactionAction($ledger))->execute($transaction->fresh());

        $asOf = Carbon::parse('2026-01-20');
        $this->assertTrue($ledger->getBalance($asset, $asOf)->isEqualTo(Money::ofMinor(2_000, 'ZAR')));
        $this->assertTrue($ledger->getBalance($liability, $asOf)->isEqualTo(Money::ofMinor(2_000, 'ZAR')));
    }
}
