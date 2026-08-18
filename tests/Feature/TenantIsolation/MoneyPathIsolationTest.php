<?php

namespace Tests\Feature\TenantIsolation;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Item;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyPathIsolationTest extends TestCase
{
    use IsolatesTwoBusinesses;
    use RefreshDatabase;

    private const INVOICE_NUMBER = 'INV-ISO-A-9911';

    private const ESTIMATE_NUMBER = 'EST-ISO-A-9911';

    private const CLIENT_NAME = 'Isolation Client Alpha';

    private const ITEM_NAME = 'Isolation item Alpha';

    private const EXPENSE_DESCRIPTION = 'Isolation expense Alpha';

    private const SUPPLIER_NAME = 'Isolation Supplier Alpha';

    private const ACCOUNT_NAME = 'Isolation GL Alpha';

    private const TAX_CODE = 'ISOA15';

    private const AMOUNT_CENTS = 876543;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoBusinesses();
    }

    public function test_scope_hides_other_business_invoice_from_eloquent(): void
    {
        $invoice = $this->invoiceOnTeamA();

        $this->asOutsider();

        $this->assertNull(Invoice::query()->find($invoice->id));
        $this->assertNotNull(Invoice::queryWithoutTeamScope()->find($invoice->id));
    }

    public function test_outsider_cannot_open_or_mutate_invoices(): void
    {
        $invoice = $this->invoiceOnTeamA();

        $this->asOutsider();

        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.invoices.show', $invoice)));
        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.invoices.edit', $invoice)));
        $this->assertHiddenFromOtherTeam($this->get(route('invoices.pdf.download', $invoice)));
        $this->assertHiddenFromOtherTeam($this->delete(route('invoicing.invoices.destroy', $invoice)));
        $this->assertHiddenFromOtherTeam($this->post(route('invoicing.invoices.void', $invoice)));
        $this->assertHiddenFromOtherTeam($this->post(route('invoicing.invoices.payments.store', $invoice), [
            'amount' => 10,
            'date' => '2026-08-18',
        ]));

        $this->assertNotNull(Invoice::queryWithoutTeamScope()->find($invoice->id));
    }

    public function test_invoice_index_does_not_list_other_business(): void
    {
        $this->invoiceOnTeamA();

        $this->asOutsider();

        $this->get(route('invoicing.invoices.index'))
            ->assertOk()
            ->assertDontSee(self::INVOICE_NUMBER);
    }

    public function test_outsider_cannot_open_clients_items_estimates_or_recurring(): void
    {
        $client = Client::factory()->for($this->teamA)->create(['name' => self::CLIENT_NAME]);
        $item = Item::factory()->for($this->teamA)->create(['name' => self::ITEM_NAME]);
        $estimate = Estimate::query()->create([
            'team_id' => $this->teamA->id,
            'client_id' => $client->id,
            'status' => EstimateStatus::Draft,
            'number' => self::ESTIMATE_NUMBER,
            'issue_date' => '2026-08-01',
            'expiry_date' => '2026-09-01',
            'subtotal_cents' => 10000,
            'vat_amount_cents' => 0,
            'total_cents' => 10000,
            'currency' => 'ZAR',
            'line_items' => [
                ['description' => 'Work', 'quantity' => 1, 'unit_price_cents' => 10000, 'vat_rate' => 0],
            ],
        ]);
        $recurring = RecurringInvoice::factory()->for($this->teamA)->create([
            'client_id' => $client->id,
        ]);

        $this->asOutsider();

        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.clients.show', $client)));
        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.clients.edit', $client)));
        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.items.show', $item)));
        $this->assertHiddenFromOtherTeam($this->put(route('invoicing.items.update', $item), [
            'name' => 'Hijacked',
            'unit_price_cents' => 1,
        ]));
        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.estimates.show', $estimate)));
        $this->assertHiddenFromOtherTeam($this->delete(route('invoicing.estimates.destroy', $estimate)));
        $this->assertHiddenFromOtherTeam($this->get(route('invoicing.recurring.show', $recurring)));
        $this->assertHiddenFromOtherTeam($this->delete(route('invoicing.recurring.destroy', $recurring)));

        $this->get(route('invoicing.clients.index'))->assertOk()->assertDontSee(self::CLIENT_NAME);
        $this->get(route('invoicing.items.index'))->assertOk()->assertDontSee(self::ITEM_NAME);
        $this->get(route('invoicing.estimates.index'))->assertOk()->assertDontSee(self::ESTIMATE_NUMBER);

        $this->assertNotNull(Client::queryWithoutTeamScope()->find($client->id));
        $this->assertNotNull(Item::queryWithoutTeamScope()->find($item->id));
        $this->assertNotNull(Estimate::queryWithoutTeamScope()->find($estimate->id));
        $this->assertNotNull(RecurringInvoice::queryWithoutTeamScope()->find($recurring->id));
    }

    public function test_outsider_cannot_open_or_mutate_expenses_or_suppliers(): void
    {
        $supplier = Supplier::factory()->for($this->teamA)->create(['name' => self::SUPPLIER_NAME]);
        $expense = $this->postedExpenseOnTeamA();

        $this->asOutsider();

        $this->assertHiddenFromOtherTeam($this->get(route('expenses.edit', $expense)));
        $this->assertHiddenFromOtherTeam($this->put(route('expenses.update', $expense), [
            'date' => '2026-08-18',
            'description' => 'Hijacked',
            'amount_excl_vat_cents' => 100,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'category_account_id' => 1,
        ]));
        $this->assertHiddenFromOtherTeam($this->delete(route('expenses.destroy', $expense)));
        $this->assertHiddenFromOtherTeam($this->get(route('suppliers.show', $supplier)));
        $this->assertHiddenFromOtherTeam($this->delete(route('suppliers.destroy', $supplier)));

        $this->get(route('expenses.index'))->assertOk()->assertDontSee(self::EXPENSE_DESCRIPTION);
        $this->get(route('suppliers.index'))->assertOk()->assertDontSee(self::SUPPLIER_NAME);

        $this->assertNotNull(Transaction::queryWithoutTeamScope()->find($expense->id));
        $this->assertNotNull(Supplier::queryWithoutTeamScope()->find($supplier->id));
    }

    public function test_journal_and_chart_hide_other_business(): void
    {
        $expense = $this->postedExpenseOnTeamA();
        $account = Account::queryWithoutTeamScope()
            ->where('team_id', $this->teamA->id)
            ->where('name', self::ACCOUNT_NAME)
            ->firstOrFail();

        $this->asOutsider();

        $this->get(route('accounting.transactions.index'))
            ->assertOk()
            ->assertDontSee(self::EXPENSE_DESCRIPTION);
        $this->get(route('accounting.journal.index'))
            ->assertOk()
            ->assertDontSee(self::EXPENSE_DESCRIPTION);

        $this->assertHiddenFromOtherTeam($this->delete(route('accounting.transactions.destroy', $expense)));
        $this->assertHiddenFromOtherTeam($this->get(route('accounting.accounts.edit', $account)));
        $this->assertHiddenFromOtherTeam($this->get(route('accounting.accounts.statement', $account)));
        $this->assertHiddenFromOtherTeam($this->get(route('accounting.accounts.statement.export', $account)));

        $this->assertNotNull(Transaction::queryWithoutTeamScope()->find($expense->id));
        $this->assertNotNull(Account::queryWithoutTeamScope()->find($account->id));
    }

    public function test_reports_do_not_include_other_business_totals(): void
    {
        $this->postedExpenseOnTeamA();

        $this->asOutsider();

        $query = ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'];

        foreach ([
            'reports.profit-loss',
            'reports.balance-sheet',
            'reports.trial-balance',
            'reports.cash-flow',
        ] as $routeName) {
            $this->get(route($routeName, $query))
                ->assertOk()
                ->assertDontSee((string) self::AMOUNT_CENTS)
                ->assertDontSee(self::ACCOUNT_NAME)
                ->assertDontSee(self::EXPENSE_DESCRIPTION);
        }
    }

    public function test_vat_rates_and_periods_stay_on_own_business(): void
    {
        $this->invoiceOnTeamA();
        $rate = TaxRate::factory()->for($this->teamA)->create([
            'name' => 'Isolation VAT',
            'code' => self::TAX_CODE,
            'is_default' => false,
        ]);
        $period = TaxPeriod::factory()->for($this->teamA)->create();

        $this->asOutsider();

        $this->get(route('tax.vat.index'))
            ->assertOk()
            ->assertDontSee(self::INVOICE_NUMBER);
        $this->get(route('tax.vat-rates.index'))
            ->assertOk()
            ->assertDontSee(self::TAX_CODE);

        $this->assertHiddenFromOtherTeam($this->put(route('tax.vat-rates.update', $rate), [
            'name' => 'Hijacked',
            'code' => 'HIJACK',
            'rate_percent' => 99,
        ]));
        $this->assertHiddenFromOtherTeam($this->delete(route('tax.vat-rates.destroy', $rate)));
        $this->assertHiddenFromOtherTeam($this->post(route('tax.vat.submit', $period)));

        $this->assertNotNull(TaxRate::queryWithoutTeamScope()->find($rate->id));
        $this->assertSame(self::TAX_CODE, TaxRate::queryWithoutTeamScope()->find($rate->id)?->code);
        $this->assertNotNull(TaxPeriod::queryWithoutTeamScope()->find($period->id));
    }

    private function invoiceOnTeamA(): Invoice
    {
        $client = Client::factory()->for($this->teamA)->create(['name' => self::CLIENT_NAME]);

        return Invoice::factory()->for($this->teamA)->create([
            'client_id' => $client->id,
            'number' => self::INVOICE_NUMBER,
        ]);
    }

    private function postedExpenseOnTeamA(): Transaction
    {
        $category = Account::factory()->for($this->teamA)->expense()->create([
            'code' => '7599',
            'name' => self::ACCOUNT_NAME,
        ]);
        $bank = Account::factory()->for($this->teamA)->asset()->create([
            'code' => '1010',
            'name' => 'Bank',
        ]);

        $transaction = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $this->teamA->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'description' => self::EXPENSE_DESCRIPTION,
            'transaction_date' => '2026-08-18',
            'posted_at' => now(),
            'created_by' => $this->ownerA->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $category->id,
            'type' => EntryType::Debit,
            'amount_cents' => self::AMOUNT_CENTS,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bank->id,
            'type' => EntryType::Credit,
            'amount_cents' => self::AMOUNT_CENTS,
            'currency' => 'ZAR',
        ]);

        return $transaction;
    }
}
