<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\Actions\UndoInvoicePaymentAction;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UndoInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_undo_payment_voids_ledger_and_clears_invoice_paid(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->asset()->create(['code' => '1010', 'is_system' => true]);
        Account::factory()->for($team)->asset()->create(['code' => '1100', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2100', 'is_system' => true]);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'subtotal_cents' => 100_00,
                'vat_amount_cents' => 15_00,
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
            ]);

        $record = new RecordPaymentAction(
            new PostTransactionAction(new LedgerService)
        );

        $payment = $record->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 115_00,
            paymentDate: '2026-05-01',
            method: PaymentMethod::Eft,
            createdBy: $user->id,
        ));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(115_00, (int) $invoice->getRawOriginal('amount_paid_cents'));

        $txId = (int) $payment->transaction_id;
        $this->assertNotNull($txId);

        $undo = new UndoInvoicePaymentAction(
            new VoidTransactionAction(new PostTransactionAction(new LedgerService))
        );
        $undo->execute($payment->fresh(), $team->id, 'Wrong amount');

        $this->assertFalse(Payment::queryWithoutTeamScope()->where('id', $payment->id)->exists());

        $invoice->refresh();
        $this->assertSame(0, (int) $invoice->getRawOriginal('amount_paid_cents'));
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $this->assertSame(TransactionStatus::Void, Transaction::queryWithoutTeamScope()->findOrFail($txId)->status);
    }

    public function test_undo_payment_route(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->asset()->create(['code' => '1010', 'is_system' => true]);
        Account::factory()->for($team)->asset()->create(['code' => '1100', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2100', 'is_system' => true]);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'subtotal_cents' => 100_00,
                'vat_amount_cents' => 15_00,
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
            ]);

        $payment = (new RecordPaymentAction(
            new PostTransactionAction(new LedgerService)
        ))->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 50_00,
            paymentDate: '2026-05-01',
            method: PaymentMethod::Eft,
            createdBy: $user->id,
        ));

        $this->from(route('invoicing.invoices.show', $invoice))
            ->post(route('invoicing.invoices.payments.undo', [$invoice, $payment]))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame(0, (int) $invoice->getRawOriginal('amount_paid_cents'));
    }
}
