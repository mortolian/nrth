<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Invoicing\Actions\PostInvoiceAccrualAction;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\Actions\RepairForeignInvoiceLedgerAction;
use App\Domain\Invoicing\Actions\UndoInvoicePaymentAction;
use App\Domain\Invoicing\Actions\VoidInvoiceAction;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ForeignCurrencyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    private function seedChart(Team $team): void
    {
        Account::factory()->for($team)->asset()->create(['code' => '1010', 'is_system' => true]);
        Account::factory()->for($team)->asset()->create(['code' => '1100', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2100', 'is_system' => true]);
        Account::factory()->for($team)->income()->create(['code' => '4000', 'is_system' => true]);
        Account::factory()->for($team)->income()->create(['code' => '4950', 'is_system' => true]);
        Account::factory()->for($team)->expense()->create(['code' => '5900', 'is_system' => true]);
    }

    public function test_foreign_invoice_accrual_posts_book_currency_not_invoice_currency(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Draft,
            'currency' => 'EUR',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => '18',
            'fx_rate_date' => '2026-04-25',
            'subtotal_cents' => 5247_00,
            'vat_amount_cents' => 0,
            'total_cents' => 5247_00,
            'total_business_currency_cents' => 94446_00,
            'amount_paid_cents' => 0,
        ]);

        InvoiceLineItem::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price_cents' => 5247_00,
            'vat_rate' => 0,
            'vat_amount_cents' => 0,
            'total_cents' => 5247_00,
            'sort_order' => 0,
        ]);

        $posted = app(PostInvoiceAccrualAction::class)->execute($invoice->fresh(['lineItems', 'team']));
        $this->assertNotNull($posted);

        $ar = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1100')->firstOrFail();
        $arLine = JournalEntry::query()
            ->where('transaction_id', $posted->id)
            ->where('account_id', $ar->id)
            ->where('type', EntryType::Debit)
            ->firstOrFail();

        $this->assertSame('ZAR', $arLine->getRawOriginal('currency'));
        $this->assertSame(94446_00, (int) $arLine->getRawOriginal('amount_cents'));
        $this->assertNotSame(5247_00, (int) $arLine->getRawOriginal('amount_cents'));
    }

    public function test_foreign_invoice_accrual_requires_fx_snapshot(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Draft,
            'currency' => 'EUR',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => null,
            'total_cents' => 100_00,
            'total_business_currency_cents' => null,
            'vat_amount_cents' => 0,
            'subtotal_cents' => 100_00,
            'amount_paid_cents' => 0,
        ]);

        $this->expectException(ValidationException::class);
        app(PostInvoiceAccrualAction::class)->execute($invoice->fresh(['team']));
    }

    public function test_fx_payment_at_book_rate_clears_ar_to_zero(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Draft,
            'currency' => 'USD',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => '18',
            'fx_rate_date' => '2026-04-25',
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 0,
            'total_cents' => 100_00,
            'total_business_currency_cents' => 1800_00,
            'amount_paid_cents' => 0,
        ]);

        app(PostInvoiceAccrualAction::class)->execute($invoice->fresh(['lineItems', 'team']));
        $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();

        $payment = app(RecordPaymentAction::class)->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 100_00,
            paymentDate: '2026-04-26',
            bankingAccountId: (int) (new EnsureDefaultBankingAccount)->execute($team)->id,
            method: PaymentMethod::Eft,
            currency: 'USD',
            createdBy: $user->id,
            bankAmountBusinessCents: 1800_00,
        ));

        $ar = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1100')->firstOrFail();
        $balance = (new LedgerService)->getBalance($ar);
        $this->assertSame(0, $balance->getMinorAmount()->toInt());
        $this->assertSame('ZAR', $balance->getCurrency()->getCurrencyCode());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotNull($payment->transaction_id);
    }

    public function test_fx_payment_below_book_books_loss_and_undo_reverses(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Draft,
            'currency' => 'USD',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => '18',
            'fx_rate_date' => '2026-04-25',
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 0,
            'total_cents' => 100_00,
            'total_business_currency_cents' => 1800_00,
            'amount_paid_cents' => 0,
        ]);

        app(PostInvoiceAccrualAction::class)->execute($invoice->fresh(['lineItems', 'team']));
        $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();

        $payment = app(RecordPaymentAction::class)->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 100_00,
            paymentDate: '2026-04-26',
            bankingAccountId: (int) (new EnsureDefaultBankingAccount)->execute($team)->id,
            method: PaymentMethod::Eft,
            currency: 'USD',
            createdBy: $user->id,
            bankAmountBusinessCents: 1700_00,
            bookFxLossToExpense: true,
        ));

        $txId = (int) $payment->transaction_id;
        $undo = new UndoInvoicePaymentAction(
            new VoidTransactionAction(new PostTransactionAction(new LedgerService))
        );
        $undo->execute($payment->fresh(), $team->id, 'Undo FX payment');

        $this->assertSame(TransactionStatus::Void, Transaction::queryWithoutTeamScope()->findOrFail($txId)->status);
        $this->assertSame(0, (int) $invoice->fresh()->getRawOriginal('amount_paid_cents'));

        $ar = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1100')->firstOrFail();
        $balance = (new LedgerService)->getBalance($ar);
        $this->assertSame(1800_00, $balance->getMinorAmount()->toInt());
    }

    public function test_void_invoice_voids_accrual_transaction(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);
        $client = Client::factory()->for($team)->create();

        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Draft,
            'currency' => 'ZAR',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => '1',
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 0,
            'total_cents' => 100_00,
            'total_business_currency_cents' => 100_00,
            'amount_paid_cents' => 0,
        ]);

        $accrual = app(PostInvoiceAccrualAction::class)->execute($invoice->fresh(['lineItems', 'team']));
        $this->assertNotNull($accrual);
        $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();

        $voided = app(VoidInvoiceAction::class)->execute($invoice->fresh(), 'Cancelled');
        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertSame(TransactionStatus::Void, $accrual->fresh()->status);
    }

    public function test_foreign_payment_without_snapshot_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'currency' => 'EUR',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => null,
            'total_business_currency_cents' => null,
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 0,
            'total_cents' => 100_00,
            'amount_paid_cents' => 0,
        ]);

        $this->expectException(ValidationException::class);
        app(RecordPaymentAction::class)->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 100_00,
            paymentDate: '2026-04-26',
            bankingAccountId: (int) (new EnsureDefaultBankingAccount)->execute($team)->id,
            method: PaymentMethod::Eft,
            currency: 'EUR',
            createdBy: $user->id,
        ));
    }

    public function test_repair_command_dry_run_and_apply_rebuilds_mixed_currency_accrual(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $this->seedChart($team);

        $ar = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '1100')->firstOrFail();
        $income = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '4000')->firstOrFail();

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Sent,
            'currency' => 'EUR',
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => '18',
            'fx_rate_date' => '2026-04-25',
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 0,
            'total_cents' => 100_00,
            'total_business_currency_cents' => 1800_00,
            'amount_paid_cents' => 0,
        ]);

        // Simulate legacy bug: accrual posted in invoice currency.
        $legacy = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::JournalAdjustment,
            'status' => TransactionStatus::Draft,
            'transaction_date' => '2026-04-25',
            'description' => 'Invoice accrual '.$invoice->number,
            'reference' => $invoice->number,
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $legacy->id,
            'account_id' => $ar->id,
            'type' => EntryType::Debit,
            'amount_cents' => 100_00,
            'currency' => 'EUR',
            'description' => 'AR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $legacy->id,
            'account_id' => $income->id,
            'type' => EntryType::Credit,
            'amount_cents' => 100_00,
            'currency' => 'EUR',
            'description' => 'Revenue',
        ]);
        (new PostTransactionAction(new LedgerService))->execute($legacy->fresh(['journalEntries']));
        $invoice->forceFill(['accrual_transaction_id' => $legacy->id])->save();

        $action = app(RepairForeignInvoiceLedgerAction::class);

        $dry = $action->execute($team, true);
        $this->assertNotEmpty($dry);
        $this->assertSame('would_repair', $dry[0]['status']);
        $this->assertSame(TransactionStatus::Posted, $legacy->fresh()->status);

        $this->artisan('invoicing:repair-foreign-ledger', [
            '--team' => $team->id,
            '--apply' => true,
        ])->assertSuccessful();

        $invoice->refresh();
        $this->assertNotNull($invoice->accrual_transaction_id);
        $this->assertNotSame($legacy->id, $invoice->accrual_transaction_id);
        $this->assertSame(TransactionStatus::Void, $legacy->fresh()->status);

        $newArLine = JournalEntry::query()
            ->where('transaction_id', $invoice->accrual_transaction_id)
            ->where('account_id', $ar->id)
            ->where('type', EntryType::Debit)
            ->firstOrFail();
        $this->assertSame('ZAR', $newArLine->getRawOriginal('currency'));
        $this->assertSame(1800_00, (int) $newArLine->getRawOriginal('amount_cents'));
    }
}
