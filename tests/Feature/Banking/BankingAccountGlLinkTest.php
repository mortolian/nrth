<?php

namespace Tests\Feature\Banking;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankingAccountGlLinkTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_create_requires_gl_and_rejects_duplicate_link(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $bank = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1010')->firstOrFail();
        $cash = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1020')->firstOrFail();

        $this->post(route('banking.accounts.store'), [
            'name' => 'FNB Cheque',
            'currency' => 'ZAR',
            'gl_account_id' => $bank->id,
        ])->assertRedirect(route('banking.accounts.index'));

        $this->assertDatabaseHas('banking_accounts', [
            'team_id' => $team->id,
            'name' => 'FNB Cheque',
            'gl_account_id' => $bank->id,
        ]);

        $this->post(route('banking.accounts.store'), [
            'name' => 'Duplicate',
            'currency' => 'ZAR',
            'gl_account_id' => $bank->id,
        ])->assertSessionHasErrors('gl_account_id');

        $expense = Account::factory()->for($team)->expense()->create(['code' => '7500']);
        $this->post(route('banking.accounts.store'), [
            'name' => 'Bad type',
            'currency' => 'ZAR',
            'gl_account_id' => $expense->id,
        ])->assertSessionHasErrors('gl_account_id');

        $created = BankingAccount::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'FNB Cheque')->firstOrFail();
        $this->put(route('banking.accounts.update', $created), [
            'name' => 'FNB Cheque',
            'currency' => 'ZAR',
            'gl_account_id' => $cash->id,
            'is_active' => true,
        ])->assertRedirect(route('banking.accounts.index'));

        $this->assertSame($cash->id, (int) $created->fresh()->gl_account_id);
    }

    public function test_ensure_default_creates_bank_linked_to_1010(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $banking = (new EnsureDefaultBankingAccount)->execute($team);
        $bankGl = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1010')->firstOrFail();

        $this->assertSame($bankGl->id, (int) $banking->gl_account_id);
        $this->assertTrue($banking->is_active);

        $again = (new EnsureDefaultBankingAccount)->execute($team);
        $this->assertSame($banking->id, $again->id);
    }

    public function test_invoice_payment_debits_chosen_banking_gl(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $cash = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1020')->firstOrFail();
        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'Petty cash tin',
            'gl_account_id' => $cash->id,
        ]);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
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
            paymentDate: '2026-05-01',
            bankingAccountId: (int) $banking->id,
            method: PaymentMethod::Cash,
            createdBy: $user->id,
        ));

        $this->assertSame($banking->id, (int) $payment->banking_account_id);

        $debit = JournalEntry::query()
            ->where('transaction_id', $payment->transaction_id)
            ->where('type', EntryType::Debit)
            ->first();
        $this->assertNotNull($debit);
        $this->assertSame($cash->id, (int) $debit->account_id);
    }

    public function test_invoice_payment_rejects_liability_linked_banking_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $ap = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '2000')->firstOrFail();
        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'AP clearing',
            'gl_account_id' => $ap->id,
        ]);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'subtotal_cents' => 50_00,
                'vat_amount_cents' => 0,
                'total_cents' => 50_00,
                'amount_paid_cents' => 0,
            ]);

        $this->expectException(ValidationException::class);

        (new RecordPaymentAction(
            new PostTransactionAction(new LedgerService)
        ))->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 50_00,
            paymentDate: '2026-05-01',
            bankingAccountId: (int) $banking->id,
            method: PaymentMethod::Eft,
            createdBy: $user->id,
        ));
    }
}
