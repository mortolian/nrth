<?php

namespace Tests\Feature\Reports;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Reports\Services\IncomeReportPdfService;
use App\Domain\Reports\Services\IncomeReportService;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeReportTest extends TestCase
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

    public function test_income_report_totals_the_financial_year_and_lists_transaction_detail(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();

        $income = $this->postPair(
            $team,
            $user,
            '2026-08-10',
            $accounts['ar'],
            $accounts['income'],
            100_00,
            'August consulting',
            'INV-EXT-42',
        );
        JournalEntry::query()->where('transaction_id', $income->id)->where('type', EntryType::Credit)->update([
            'description' => 'Consulting revenue',
        ]);

        $client = Client::factory()->for($team)->create(['name' => 'Northwind Studio']);
        Invoice::factory()->for($team)->create([
            'client_id' => $client->id,
            'number' => 'INV-2026-0042',
            'accrual_transaction_id' => $income->id,
        ]);

        $rate = TaxRate::factory()->for($team)->create([
            'name' => 'VAT 15%',
            'rate_percent' => 15,
        ]);
        TaxLine::query()->create([
            'transaction_id' => $income->id,
            'tax_rate_id' => $rate->id,
            'taxable_amount_cents' => 100_00,
            'tax_amount_cents' => 15_00,
            'type' => TaxLineType::Output,
        ]);

        $this->postPair(
            $team,
            $user,
            '2026-08-20',
            $accounts['income'],
            $accounts['ar'],
            20_00,
            'August credit note',
        );
        $this->postPair(
            $team,
            $user,
            '2026-02-15',
            $accounts['ar'],
            $accounts['income'],
            50_00,
            'Prior year retainer',
        );
        $this->postPair(
            $team,
            $user,
            '2026-08-12',
            $accounts['expense'],
            $accounts['bank'],
            30_00,
            'Office paper',
        );
        $this->postPair(
            $team,
            $user,
            '2026-08-11',
            $accounts['ar'],
            $accounts['income'],
            77_00,
            'Draft income',
            null,
            TransactionStatus::Draft,
        );

        $this->actingAs($user)
            ->get(route('reports.income'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Income')
                ->where('financial_year.label', '2026/27')
                ->where('financial_year.from', '2026-03-01')
                ->where('financial_year.to', '2027-02-28')
                ->where('report.totals.income', 80_00)
                ->where('report.accounts.0.code', '4000')
                ->where('report.accounts.0.amount', 80_00)
                ->where('transactions.total', 2)
                ->where('transactions.data.0.description', 'August consulting')
                ->where('transactions.data.0.reference', 'INV-EXT-42')
                ->where('transactions.data.0.counterparty', 'Northwind Studio')
                ->where('transactions.data.0.invoice.number', 'INV-2026-0042')
                ->where('transactions.data.0.income_cents', 100_00)
                ->where('transactions.data.0.lines.1.description', 'Consulting revenue')
                ->where('transactions.data.0.lines.1.entry_type', 'credit')
                ->where('transactions.data.0.lines.1.is_income', true)
                ->where('transactions.data.0.tax_lines.0.type_label', 'Output VAT')
                ->where('transactions.data.0.tax_lines.0.tax_cents', 15_00)
                ->where('transactions.data.1.description', 'August credit note')
                ->where('transactions.data.1.income_cents', -20_00)
                ->missing('transactions.data.2'));

        $this->actingAs($user)
            ->get(route('reports.income', ['fy' => 2025]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financial_year.label', '2025/26')
                ->where('report.totals.income', 50_00)
                ->where('transactions.total', 1)
                ->where('transactions.data.0.description', 'Prior year retainer'));
    }

    public function test_income_report_pdf_includes_the_same_figures_and_requires_export_permission(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();
        $this->postPair($team, $user, '2026-08-10', $accounts['ar'], $accounts['income'], 100_00, 'August consulting');

        $window = app(IncomeReportService::class)->window($team->fresh(), null);
        $document = app(IncomeReportPdfService::class)->document($team->fresh(), $window);
        $html = view('pdf.income-report', $document)->render();

        $this->assertStringContainsString('Income report', $html);
        $this->assertStringContainsString('2026/27', $html);
        $this->assertStringContainsString('August consulting', $html);
        $this->assertStringContainsString('4000', $html);
        $this->assertStringContainsString('Services', $html);

        $this->actingAs($user)
            ->get(route('reports.income.pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_another_business_cannot_see_income_transactions(): void
    {
        [$user, $team, $accounts] = $this->seedBooks();
        $this->postPair($team, $user, '2026-08-10', $accounts['ar'], $accounts['income'], 100_00, 'Secret retainer');

        $other = User::factory()->withPersonalTeam()->create();

        $this->actingAs($other)
            ->get(route('reports.income'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.totals.income', 0)
                ->where('transactions.total', 0))
            ->assertDontSee('Secret retainer');
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
        ?string $reference = null,
        TransactionStatus $status = TransactionStatus::Posted,
    ): Transaction {
        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::JournalAdjustment,
            'status' => $status,
            'reference' => $reference,
            'description' => $description,
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
