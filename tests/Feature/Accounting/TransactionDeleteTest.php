<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Actions\DeleteTransactionAction;
use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_deletes_draft_transaction_and_journal_lines(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $a = Account::factory()->for($team)->asset()->create(['code' => '9201']);
        $b = Account::factory()->for($team)->asset()->create(['code' => '9202']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Draft,
            'reference' => 'DRAFT-1',
            'description' => 'Test',
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $a->id,
            'type' => EntryType::Debit,
            'amount_cents' => 100,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $b->id,
            'type' => EntryType::Credit,
            'amount_cents' => 100,
            'currency' => 'ZAR',
        ]);

        (new DeleteTransactionAction)->execute($transaction->fresh());

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_deletes_posted_expense_when_not_linked(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $expense = Account::factory()->for($team)->expense()->create(['code' => '9203']);
        $bank = Account::factory()->for($team)->asset()->create(['code' => '9204']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Draft,
            'reference' => 'EXP-1',
            'description' => 'Expense',
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $expense->id,
            'type' => EntryType::Debit,
            'amount_cents' => 500,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bank->id,
            'type' => EntryType::Credit,
            'amount_cents' => 500,
            'currency' => 'ZAR',
        ]);

        (new PostTransactionAction(new LedgerService))->execute($transaction->fresh());

        (new DeleteTransactionAction)->execute($transaction->fresh());

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_cannot_delete_posted_payment_when_invoice_links_transaction(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'transaction_id' => null,
        ]);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Posted,
            'reference' => 'PAY-1',
            'description' => 'Payment',
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
            'posted_at' => now(),
        ]);

        $invoice->update(['transaction_id' => $transaction->id]);

        $this->expectException(ValidationException::class);
        (new DeleteTransactionAction)->execute($transaction->fresh());
    }

    public function test_cannot_delete_when_payment_links_transaction(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $invoice = Invoice::factory()->for($team)->create(['status' => InvoiceStatus::Sent]);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Posted,
            'reference' => 'PAY-2',
            'description' => 'Payment',
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
            'posted_at' => now(),
        ]);

        Payment::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => 100,
            'currency' => 'ZAR',
            'payment_date' => Carbon::now()->toDateString(),
            'method' => 'eft',
            'transaction_id' => $transaction->id,
        ]);

        $this->expectException(ValidationException::class);
        (new DeleteTransactionAction)->execute($transaction->fresh());
    }

    public function test_destroy_route_deletes_eligible_transaction(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Draft,
            'reference' => 'HTTP-1',
            'description' => 'Del',
            'transaction_date' => Carbon::now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->from(route('accounting.transactions.index'))
            ->delete(route('accounting.transactions.destroy', $transaction))
            ->assertRedirect();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }
}
