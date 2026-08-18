<?php

namespace Tests\Feature\Banking;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    /**
     * @return array{0: User, 1: Team, 2: Account, 3: BankingAccount}
     */
    private function teamWithBanking(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);
        $bankGl = Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank', 'is_system' => true]);
        Account::factory()->for($team)->asset()->create(['code' => '1020', 'name' => 'Cash on hand', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2000', 'name' => 'Accounts Payable', 'is_system' => true]);

        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'Operating bank',
            'gl_account_id' => $bankGl->id,
        ]);

        return [$user, $team, $bankGl, $banking];
    }

    private function createBankLine(
        Team $team,
        BankingAccount $account,
        string $date,
        string $description,
        string $amount,
        TransactionDirection $direction,
        string $hash,
    ): BankingTransaction {
        return BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'account_id' => $account->id,
            'transaction_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'currency' => 'ZAR',
            'direction' => $direction,
            'source_hash' => hash('sha256', $hash),
            'duplicate_key' => hash('sha256', $hash.'-key'),
        ]);
    }

    private function createExpense(Team $team, BankingAccount $banking, Account $category, string $date, int $amountCents, string $supplier): Transaction
    {
        $this->post(route('expenses.store'), [
            'date' => $date,
            'supplier' => $supplier,
            'category_account_id' => $category->id,
            'description' => $supplier,
            'amount_excl_vat_cents' => $amountCents,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => null,
            'notes' => null,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);

        return $txn;
    }

    public function test_index_lists_unreviewed_lines_and_filters_excluded(): void
    {
        [, $team, , $banking] = $this->teamWithBanking();

        $unreviewed = $this->createBankLine($team, $banking, '2026-08-01', 'Coffee shop', '45.00', TransactionDirection::Debit, 'coffee');
        $personal = $this->createBankLine($team, $banking, '2026-08-02', 'Grocery store', '320.00', TransactionDirection::Debit, 'grocery');
        $personal->forceFill([
            'reconciliation_status' => ReconciliationStatus::Excluded,
            'exclusion_note' => 'Personal groceries',
            'excluded_at' => now(),
        ])->save();

        $this->get(route('banking.reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Banking/Reconciliation/Index')
                ->where('counts.unreviewed', 1)
                ->where('counts.excluded', 1)
                ->has('lines.data', 1)
                ->where('lines.data.0.id', $unreviewed->id)
            );

        $this->get(route('banking.reconciliation.index', ['status' => 'excluded']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lines.data', 1)
                ->where('lines.data.0.id', $personal->id)
            );
    }

    public function test_can_match_bank_line_to_posted_expense(): void
    {
        [, $team, , $banking] = $this->teamWithBanking();
        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->firstOrFail();

        $bankLine = $this->createBankLine($team, $banking, '2026-08-03', 'Corner Cafe', '45.00', TransactionDirection::Debit, 'cafe');
        $expense = $this->createExpense($team, $banking, $category, '2026-08-03', 4500, 'Corner Cafe');

        $this->get(route('banking.reconciliation.index', ['selected' => $bankLine->id, 'status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.id', $bankLine->id)
                ->has('selected.candidates')
            );

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $expense->id,
            'amount_cents' => 4500,
        ])->assertRedirect();

        $this->assertDatabaseHas('banking_transaction_allocations', [
            'banking_transaction_id' => $bankLine->id,
            'transaction_id' => $expense->id,
            'amount_cents' => 4500,
        ]);
        $this->assertSame(
            ReconciliationStatus::Matched,
            $bankLine->fresh()->reconciliation_status
        );
    }

    public function test_can_match_bank_line_to_invoice_payment(): void
    {
        [$user, $team, , $banking] = $this->teamWithBanking();
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 0,
            'total_cents' => 100_00,
            'amount_paid_cents' => 0,
        ]);

        $payment = (new RecordPaymentAction(
            new PostTransactionAction(new LedgerService)
        ))->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 100_00,
            paymentDate: '2026-08-04',
            bankingAccountId: (int) $banking->id,
            method: PaymentMethod::Eft,
            createdBy: $user->id,
        ));

        $bankLine = $this->createBankLine($team, $banking, '2026-08-04', 'Invoice payment', '100.00', TransactionDirection::Credit, 'inv-pay');

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $payment->transaction_id,
            'amount_cents' => 100_00,
        ])->assertRedirect();

        $this->assertSame(ReconciliationStatus::Matched, $bankLine->fresh()->reconciliation_status);
    }

    public function test_split_allocations_across_two_posted_expenses(): void
    {
        [, $team, , $banking] = $this->teamWithBanking();
        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->firstOrFail();

        $bankLine = $this->createBankLine($team, $banking, '2026-08-05', 'Card batch', '150.00', TransactionDirection::Debit, 'batch');
        $first = $this->createExpense($team, $banking, $category, '2026-08-05', 100_00, 'Fuel');
        $second = $this->createExpense($team, $banking, $category, '2026-08-05', 50_00, 'Parking');

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $first->id,
            'amount_cents' => 100_00,
        ])->assertRedirect();

        $this->assertSame(ReconciliationStatus::PartiallyMatched, $bankLine->fresh()->reconciliation_status);

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $second->id,
            'amount_cents' => 50_00,
        ])->assertRedirect();

        $this->assertSame(ReconciliationStatus::Matched, $bankLine->fresh()->reconciliation_status);
        $this->assertSame(2, BankingTransactionAllocation::queryWithoutTeamScope()->where('banking_transaction_id', $bankLine->id)->count());
    }

    public function test_rejects_over_allocation(): void
    {
        [, $team, , $banking] = $this->teamWithBanking();
        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->firstOrFail();

        $bankLine = $this->createBankLine($team, $banking, '2026-08-06', 'Small purchase', '20.00', TransactionDirection::Debit, 'small');
        $expense = $this->createExpense($team, $banking, $category, '2026-08-06', 50_00, 'Office');

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $expense->id,
            'amount_cents' => 50_00,
        ])->assertSessionHasErrors('amount_cents');

        $this->assertSame(0, BankingTransactionAllocation::queryWithoutTeamScope()->count());
    }

    public function test_can_exclude_personal_line_without_matching(): void
    {
        [$user, $team, , $banking] = $this->teamWithBanking();
        $bankLine = $this->createBankLine($team, $banking, '2026-08-07', 'Netflix', '199.00', TransactionDirection::Debit, 'netflix');

        $this->post(route('banking.reconciliation.exclude', $bankLine), [
            'exclusion_note' => 'Personal subscription',
        ])->assertRedirect();

        $fresh = $bankLine->fresh();
        $this->assertSame(ReconciliationStatus::Excluded, $fresh->reconciliation_status);
        $this->assertSame('Personal subscription', $fresh->exclusion_note);
        $this->assertSame($user->id, (int) $fresh->excluded_by);
        $this->assertSame(0, BankingTransactionAllocation::queryWithoutTeamScope()->count());
    }

    public function test_reset_clears_exclusion_and_allocations(): void
    {
        [, $team, , $banking] = $this->teamWithBanking();
        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->firstOrFail();
        $bankLine = $this->createBankLine($team, $banking, '2026-08-08', 'Mixed', '80.00', TransactionDirection::Debit, 'mixed');
        $expense = $this->createExpense($team, $banking, $category, '2026-08-08', 80_00, 'Stationery');

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $expense->id,
            'amount_cents' => 80_00,
        ])->assertRedirect();

        $this->post(route('banking.reconciliation.reset', $bankLine))->assertRedirect();

        $this->assertSame(ReconciliationStatus::Unreviewed, $bankLine->fresh()->reconciliation_status);
        $this->assertSame(0, BankingTransactionAllocation::queryWithoutTeamScope()->where('banking_transaction_id', $bankLine->id)->count());
    }

    public function test_viewer_can_view_but_cannot_allocate(): void
    {
        [$owner, $team, , $banking] = $this->teamWithBanking();
        EnsureTeamSystemRoles::ensureFor($team);
        $bankLine = $this->createBankLine($team, $banking, '2026-08-09', 'Rent', '5000.00', TransactionDirection::Debit, 'rent');

        $viewer = User::factory()->create();
        $team->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $this->actingTeamContext($viewer, $team);

        $this->get(route('banking.reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', false));

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => 1,
            'amount_cents' => 100,
        ])->assertForbidden();

        $this->post(route('banking.reconciliation.exclude', $bankLine))->assertForbidden();
        $this->assertNotSame($owner->id, $viewer->id);
    }

    public function test_cannot_match_another_teams_transaction(): void
    {
        [, $team, , $banking] = $this->teamWithBanking();
        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->firstOrFail();
        $bankLine = $this->createBankLine($team, $banking, '2026-08-10', 'Team A debit', '30.00', TransactionDirection::Debit, 'team-a');

        $otherUser = User::factory()->withPersonalTeam()->create();
        $otherTeam = $otherUser->currentTeam;
        $this->assertNotNull($otherTeam);
        $this->actingTeamContext($otherUser, $otherTeam);
        Account::factory()->for($otherTeam)->expense()->create(['code' => '7500', 'name' => 'General expense']);
        $otherBankGl = Account::factory()->for($otherTeam)->asset()->create(['code' => '1010', 'name' => 'Bank', 'is_system' => true]);
        Account::factory()->for($otherTeam)->asset()->create(['code' => '1020', 'name' => 'Cash on hand', 'is_system' => true]);
        Account::factory()->for($otherTeam)->liability()->create(['code' => '2000', 'name' => 'Accounts Payable', 'is_system' => true]);
        $otherBanking = BankingAccount::factory()->for($otherTeam)->create([
            'name' => 'Other bank',
            'gl_account_id' => $otherBankGl->id,
        ]);
        $foreignExpense = $this->createExpense($otherTeam, $otherBanking, Account::queryWithoutTeamScope()->where('team_id', $otherTeam->id)->where('code', '7500')->firstOrFail(), '2026-08-10', 3000, 'Foreign');

        $owner = User::query()->find($team->user_id);
        $this->assertNotNull($owner);
        $this->actingTeamContext($owner, $team);

        $this->post(route('banking.reconciliation.allocations.store', $bankLine), [
            'transaction_id' => $foreignExpense->id,
            'amount_cents' => 3000,
        ])->assertSessionHasErrors('transaction_id');
    }
}
