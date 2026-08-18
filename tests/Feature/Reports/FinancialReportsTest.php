<?php

namespace Tests\Feature\Reports;

use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_profit_loss_totals_posted_income_minus_expenses_in_the_period(): void
    {
        [$user] = $this->seedKnownBooks();

        $this->actingAs($user)
            ->get(route('reports.profit-loss', [
                'preset' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/ProfitLoss')
                ->where('period.from', '2026-08-01')
                ->where('period.to', '2026-08-31')
                ->where('report.totals.income', 100_00)
                ->where('report.totals.expenses', 30_00)
                ->where('report.totals.net_profit', 70_00));
    }

    public function test_profit_loss_ignores_drafts_and_entries_outside_the_period(): void
    {
        [$user, $team, $accounts] = $this->seedKnownBooks();

        $this->postPair(
            $team,
            $user,
            '2026-07-31',
            $accounts['ar'],
            $accounts['income'],
            999_00,
        );
        $this->postPair(
            $team,
            $user,
            '2026-09-01',
            $accounts['ar'],
            $accounts['income'],
            888_00,
        );
        $this->postPair(
            $team,
            $user,
            '2026-08-15',
            $accounts['ar'],
            $accounts['income'],
            777_00,
            TransactionStatus::Draft,
        );

        $this->actingAs($user)
            ->get(route('reports.profit-loss', [
                'preset' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.totals.income', 100_00)
                ->where('report.totals.expenses', 30_00)
                ->where('report.totals.net_profit', 70_00));
    }

    public function test_voided_expense_does_not_remain_in_profit_loss(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsOwner($user, $team);

        $bank = Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank']);
        $expense = Account::factory()->for($team)->expense()->create(['code' => '5000', 'name' => 'Office']);

        $transaction = $this->postPair($team, $user, '2026-08-12', $expense, $bank, 45_00);

        app(VoidTransactionAction::class)->execute($transaction->fresh(['journalEntries']), 'Correction');

        $this->actingAs($user)
            ->get(route('reports.profit-loss', [
                'preset' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.totals.income', 0)
                ->where('report.totals.expenses', 0)
                ->where('report.totals.net_profit', 0));
    }

    public function test_trial_balance_debits_equal_credits(): void
    {
        [$user] = $this->seedKnownBooks();

        $this->actingAs($user)
            ->get(route('reports.trial-balance', ['as_of' => '2026-08-31']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/TrialBalance')
                ->where('as_of', '2026-08-31')
                ->where('report.totals.debits', 300_00)
                ->where('report.totals.credits', 300_00)
                ->where('report.totals.difference', 0)
                ->where('report.totals.is_balanced', true));
    }

    public function test_balance_sheet_lists_ledger_asset_and_equity_balances(): void
    {
        [$user] = $this->seedKnownBooks();

        $this->actingAs($user)
            ->get(route('reports.balance-sheet', ['as_of' => '2026-08-31']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/BalanceSheet')
                ->where('as_of', '2026-08-31')
                ->where('report.assets.0.code', '1010')
                ->where('report.assets.0.amount', 170_00)
                ->where('report.assets.1.code', '1100')
                ->where('report.assets.1.amount', 100_00)
                ->where('report.equity.0.code', '3000')
                ->where('report.equity.0.amount', 200_00)
                ->where('report.totals.assets', 270_00)
                ->where('report.totals.liabilities', 0)
                ->where('report.totals.equity', 200_00)
                ->where('report.totals.liabilities_plus_equity', 200_00)
                ->where('report.is_balanced', false));
    }

    public function test_cash_flow_reconciles_opening_plus_net_change_to_closing_cash(): void
    {
        [$user] = $this->seedKnownBooks();

        $this->actingAs($user)
            ->get(route('reports.cash-flow', [
                'preset' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/CashFlow')
                ->where('period.from', '2026-08-01')
                ->where('period.to', '2026-08-31')
                ->where('report.operating.lines.0.key', 'net_profit')
                ->where('report.operating.lines.0.amount', 70_00)
                ->where('report.operating.lines.3.key', 'receivables')
                ->where('report.operating.lines.3.amount', -100_00)
                ->where('report.operating.subtotal', -30_00)
                ->where('report.investing.subtotal', 0)
                ->where('report.financing.subtotal', 0)
                ->where('report.summary.net_change', -30_00)
                ->where('report.summary.opening_cash', 200_00)
                ->where('report.summary.closing_cash', 170_00)
                ->where('report.summary.reconciliation_difference', 0));
    }

    /**
     * July owner contribution (cash), August unpaid income, August cash expense.
     *
     * @return array{0: User, 1: Team, 2: array{bank: Account, ar: Account, equity: Account, income: Account, expense: Account}}
     */
    private function seedKnownBooks(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsOwner($user, $team);

        $accounts = [
            'bank' => Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank']),
            'ar' => Account::factory()->for($team)->asset()->create(['code' => '1100', 'name' => 'Accounts Receivable']),
            'equity' => Account::factory()->for($team)->equity()->create(['code' => '3000', 'name' => 'Owner Equity']),
            'income' => Account::factory()->for($team)->income()->create(['code' => '4000', 'name' => 'Services']),
            'expense' => Account::factory()->for($team)->expense()->create(['code' => '5000', 'name' => 'Office']),
        ];

        $this->postPair($team, $user, '2026-07-15', $accounts['bank'], $accounts['equity'], 200_00);
        $this->postPair($team, $user, '2026-08-10', $accounts['ar'], $accounts['income'], 100_00);
        $this->postPair($team, $user, '2026-08-20', $accounts['expense'], $accounts['bank'], 30_00);

        return [$user, $team, $accounts];
    }

    private function actingAsOwner(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    private function postPair(
        Team $team,
        User $user,
        string $date,
        Account $debit,
        Account $credit,
        int $cents,
        TransactionStatus $status = TransactionStatus::Posted,
    ): Transaction {
        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::JournalAdjustment,
            'status' => $status,
            'description' => 'Test '.$date,
            'transaction_date' => $date,
            'posted_at' => $status === TransactionStatus::Posted ? now() : null,
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $debit->id,
            'type' => EntryType::Debit,
            'amount_cents' => $cents,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $credit->id,
            'type' => EntryType::Credit,
            'amount_cents' => $cents,
            'currency' => 'ZAR',
        ]);

        return $transaction;
    }
}
