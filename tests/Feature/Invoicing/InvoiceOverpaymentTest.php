<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_payment_action_rejects_amount_above_amount_due(): void
    {
        [$user, $team, $invoice, $bankingAccountId] = $this->sentInvoiceWithChart();

        try {
            $this->recordPaymentAction()->execute(new RecordPaymentDTO(
                invoiceId: $invoice->id,
                teamId: $team->id,
                amountCents: 200_00,
                paymentDate: '2026-08-18',
                bankingAccountId: $bankingAccountId,
                method: PaymentMethod::Eft,
                createdBy: $user->id,
            ));
            $this->fail('Expected ValidationException for an overpayment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_cents', $exception->errors());
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, (int) $invoice->fresh()->getRawOriginal('amount_paid_cents'));
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_http_payment_greater_than_amount_due_is_rejected(): void
    {
        [$user, $team, $invoice, $bankingAccountId] = $this->sentInvoiceWithChart();

        $this->actingAsOwner($user, $team)
            ->from(route('invoicing.invoices.show', $invoice))
            ->post(route('invoicing.invoices.payments.store', $invoice), [
                'amount_cents' => 200_00,
                'payment_date' => '2026-08-18',
                'method' => PaymentMethod::Eft->value,
                'banking_account_id' => $bankingAccountId,
            ])
            ->assertRedirect(route('invoicing.invoices.show', $invoice))
            ->assertSessionHasErrors('amount_cents');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, (int) $invoice->fresh()->getRawOriginal('amount_paid_cents'));
    }

    public function test_remaining_due_after_a_partial_payment_cannot_be_exceeded(): void
    {
        [$user, $team, $invoice, $bankingAccountId] = $this->sentInvoiceWithChart();

        $this->recordPaymentAction()->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 50_00,
            paymentDate: '2026-08-18',
            bankingAccountId: $bankingAccountId,
            method: PaymentMethod::Eft,
            createdBy: $user->id,
        ));

        $this->assertSame(50_00, (int) $invoice->fresh()->getRawOriginal('amount_paid_cents'));
        $this->assertSame(InvoiceStatus::Partial, $invoice->fresh()->status);

        try {
            $this->recordPaymentAction()->execute(new RecordPaymentDTO(
                invoiceId: $invoice->id,
                teamId: $team->id,
                amountCents: 66_00,
                paymentDate: '2026-08-19',
                bankingAccountId: $bankingAccountId,
                method: PaymentMethod::Eft,
                createdBy: $user->id,
            ));
            $this->fail('Expected ValidationException when the second payment exceeds the remainder.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_cents', $exception->errors());
        }

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(50_00, (int) $invoice->fresh()->getRawOriginal('amount_paid_cents'));
    }

    /**
     * @return array{0: User, 1: Team, 2: Invoice, 3: int}
     */
    private function sentInvoiceWithChart(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsOwner($user, $team);

        Account::factory()->for($team)->create(['code' => '1010', 'type' => AccountType::Asset, 'is_system' => true]);
        Account::factory()->for($team)->create(['code' => '1100', 'type' => AccountType::Asset, 'is_system' => true]);
        Account::factory()->for($team)->create(['code' => '2100', 'type' => AccountType::Liability, 'is_system' => true]);

        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->for($team)->create([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 15_00,
            'total_cents' => 115_00,
            'amount_paid_cents' => 0,
        ]);

        $bankingAccountId = (int) (new EnsureDefaultBankingAccount)->execute($team)->id;

        return [$user, $team, $invoice, $bankingAccountId];
    }

    private function actingAsOwner(User $user, Team $team): self
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        return $this;
    }

    private function recordPaymentAction(): RecordPaymentAction
    {
        return new RecordPaymentAction(
            new PostTransactionAction(new LedgerService)
        );
    }
}
