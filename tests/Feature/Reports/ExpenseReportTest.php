<?php

namespace Tests\Feature\Reports;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Reports\Services\ExpenseReportPdfService;
use App\Domain\Reports\Services\ExpenseReportService;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseReportTest extends TestCase
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

    public function test_expense_report_totals_the_financial_year_and_lists_transaction_detail(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Paper Co']);

        $expense = $this->postPair(
            $team,
            $user,
            '2026-08-10',
            $accounts['expense'],
            $accounts['bank'],
            100_00,
            'August stationery',
            $supplier,
        );
        JournalEntry::query()->where('transaction_id', $expense->id)->where('type', EntryType::Debit)->update([
            'description' => 'Printer paper',
        ]);

        $rate = TaxRate::factory()->for($team)->create([
            'name' => 'VAT 15%',
            'rate_percent' => 15,
        ]);
        TaxLine::query()->create([
            'transaction_id' => $expense->id,
            'tax_rate_id' => $rate->id,
            'taxable_amount_cents' => 100_00,
            'tax_amount_cents' => 15_00,
            'type' => TaxLineType::Input,
        ]);

        $this->postPair(
            $team,
            $user,
            '2026-08-20',
            $accounts['bank'],
            $accounts['expense'],
            20_00,
            'August refund',
        );
        $this->postPair(
            $team,
            $user,
            '2026-02-15',
            $accounts['expense'],
            $accounts['bank'],
            50_00,
            'Prior year rent',
        );
        $this->postPair(
            $team,
            $user,
            '2026-08-12',
            $accounts['ar'],
            $accounts['income'],
            80_00,
            'Consulting income',
        );
        $this->postPair(
            $team,
            $user,
            '2026-08-11',
            $accounts['expense'],
            $accounts['bank'],
            77_00,
            'Draft stationery',
            null,
            TransactionStatus::Draft,
        );

        $this->actingAs($user)
            ->get(route('reports.expense'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Expenses')
                ->where('financial_year.label', '2026/27')
                ->where('financial_year.from', '2026-03-01')
                ->where('financial_year.to', '2027-02-28')
                ->where('report.totals.expenses', 80_00)
                ->where('report.accounts.0.code', '5000')
                ->where('report.accounts.0.amount', 80_00)
                ->where('transactions.total', 2)
                ->where('transactions.data.0.description', 'August stationery')
                ->where('transactions.data.0.reference', 'RCT-100')
                ->where('transactions.data.0.counterparty', 'Paper Co')
                ->where('transactions.data.0.paid_from', '1010 - Bank')
                ->where('transactions.data.0.notes', 'Box of paper')
                ->where('transactions.data.0.expense_cents', 100_00)
                ->where('transactions.data.0.lines.0.description', 'Printer paper')
                ->where('transactions.data.0.lines.0.entry_type', 'debit')
                ->where('transactions.data.0.lines.0.is_expense', true)
                ->where('transactions.data.0.tax_lines.0.type_label', 'Input VAT')
                ->where('transactions.data.0.tax_lines.0.tax_cents', 15_00)
                ->where('transactions.data.1.description', 'August refund')
                ->where('transactions.data.1.expense_cents', -20_00)
                ->missing('transactions.data.2'));

        $this->actingAs($user)
            ->get(route('reports.expense', ['fy' => 2025]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financial_year.label', '2025/26')
                ->where('report.totals.expenses', 50_00)
                ->where('transactions.total', 1)
                ->where('transactions.data.0.description', 'Prior year rent'));
    }

    public function test_expense_report_pdf_includes_the_same_figures(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();
        $this->postPair($team, $user, '2026-08-10', $accounts['expense'], $accounts['bank'], 100_00, 'August stationery');

        $window = app(ExpenseReportService::class)->window($team->fresh(), null);
        $document = app(ExpenseReportPdfService::class)->document($team->fresh(), $window);
        $html = view('pdf.expense-report', $document)->render();

        $this->assertStringContainsString('Expense report', $html);
        $this->assertStringContainsString('2026/27', $html);
        $this->assertStringContainsString('August stationery', $html);
        $this->assertStringContainsString('5000', $html);
        $this->assertStringContainsString('Office', $html);
        $this->assertStringContainsString('Transaction register', $html);
        $this->assertStringNotContainsString('class="txn"', $html);
        $this->assertStringNotContainsString('>Debit<', $html);

        $this->actingAs($user)
            ->get(route('reports.expense.pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_summary_omits_the_register_and_monthly_breakdown_splits_the_year(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();
        $this->postPair($team, $user, '2026-03-04', $accounts['expense'], $accounts['bank'], 40_00, 'March rent');
        $this->postPair($team, $user, '2026-08-10', $accounts['expense'], $accounts['bank'], 100_00, 'August stationery');

        $this->actingAs($user)
            ->get(route('reports.expense', ['view' => 'summary', 'breakdown' => 'monthly']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('presentation.view', 'summary')
                ->where('presentation.breakdown', 'monthly')
                ->where('transactions', null)
                ->where('transaction_count', 2)
                ->where('report.totals.expenses', 140_00)
                ->where('months.0.label', 'March 2026')
                ->where('months.0.total', 40_00)
                ->where('months.5.label', 'August 2026')
                ->where('months.5.total', 100_00)
                ->where('months.1.total', 0));

        $window = app(ExpenseReportService::class)->window($team->fresh(), null);
        $html = view('pdf.expense-report', app(ExpenseReportPdfService::class)->document(
            $team->fresh(),
            $window,
            ['view' => 'summary', 'breakdown' => 'monthly'],
        ))->render();

        $this->assertStringContainsString('August 2026', $html);
        $this->assertStringContainsString('March 2026', $html);
        $this->assertStringNotContainsString('April 2026', $html);
        $this->assertStringNotContainsString('Transaction register', $html);
        $this->assertStringNotContainsString('August stationery', $html);
    }

    public function test_another_business_cannot_see_expense_transactions(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();
        $this->postPair($team, $user, '2026-08-10', $accounts['expense'], $accounts['bank'], 100_00, 'Secret stationery');

        $other = User::factory()->withPersonalTeam()->create();

        $this->actingAs($other)
            ->get(route('reports.expense'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.totals.expenses', 0)
                ->where('transactions.total', 0))
            ->assertDontSee('Secret stationery');
    }

    /**
     * @return array{0: User, 1: Team, 2: array{bank: Account, ar: Account, income: Account, expense: Account}}
     */
    private function seedBooks(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $accounts = [
            'bank' => Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank']),
            'ar' => Account::factory()->for($team)->asset()->create(['code' => '1100', 'name' => 'Accounts Receivable']),
            'income' => Account::factory()->for($team)->income()->create(['code' => '4000', 'name' => 'Services']),
            'expense' => Account::factory()->for($team)->expense()->create(['code' => '5000', 'name' => 'Office']),
        ];

        return [$user, $team, $accounts];
    }

    private function postPair(
        Team $team,
        User $user,
        string $date,
        Account $debit,
        Account $credit,
        int $cents,
        string $description,
        ?Supplier $supplier = null,
        TransactionStatus $status = TransactionStatus::Posted,
    ): Transaction {
        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => $supplier?->id,
            'type' => TransactionType::Expense,
            'status' => $status,
            'description' => $description,
            'expense_meta' => $supplier === null ? null : [
                'external_reference' => 'RCT-100',
                'paid_from_account_name' => '1010 - Bank',
                'notes' => 'Box of paper',
            ],
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
            'description' => $description,
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
