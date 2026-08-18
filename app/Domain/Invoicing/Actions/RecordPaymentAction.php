<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPaymentAction
{
    public function __construct(
        private readonly PostTransactionAction $postTransactionAction,
    ) {}

    public function execute(RecordPaymentDTO $dto): Payment
    {
        return DB::transaction(function () use ($dto): Payment {
            $invoice = Invoice::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->with('team')
                ->findOrFail($dto->invoiceId);

            if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Draft], true)) {
                throw ValidationException::withMessages([
                    'invoice_id' => $invoice->status === InvoiceStatus::Draft
                        ? __('Cannot record payments against a draft invoice. Send it first.')
                        : __('Cannot record payments against a void invoice.'),
                ]);
            }

            $this->assertDoesNotExceedAmountDue($invoice, $dto->amountCents);

            $team = $invoice->team ?? Team::query()->findOrFail($dto->teamId);
            (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
            (new EnsureDefaultBankingAccount)->execute($team);

            if ($this->shouldPostInBusinessFunctionalCurrency($invoice)) {
                if ($dto->bankAmountBusinessCents !== null && $dto->bankAmountBusinessCents < 0) {
                    throw ValidationException::withMessages([
                        'bank_amount_business_cents' => __('Bank amount cannot be negative.'),
                    ]);
                }

                return $this->executeFunctionalCurrencyPayment($dto, $invoice);
            }

            if ($dto->bankAmountBusinessCents !== null) {
                throw ValidationException::withMessages([
                    'bank_amount_business_cents' => __('Bank amount in business currency only applies when the invoice currency differs from the business book currency and a business-currency snapshot exists.'),
                ]);
            }

            if ($dto->bookFxLossToExpense) {
                throw ValidationException::withMessages([
                    'book_fx_loss_to_expense' => __('Foreign exchange loss posting is only used for foreign-currency invoices with a business snapshot.'),
                ]);
            }

            return $this->executeInvoiceCurrencyPayment($dto, $invoice);
        });
    }

    private function shouldPostInBusinessFunctionalCurrency(Invoice $invoice): bool
    {
        $invoiceCurrency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
        $bookCurrency = Iso4217Currencies::normalize((string) (
            $invoice->business_currency_code
            ?? $invoice->team?->mergedBusinessSettings()['invoice_default_currency']
            ?? 'ZAR'
        ));
        if ($invoiceCurrency === $bookCurrency) {
            return false;
        }

        $rawTotalBusiness = $invoice->getRawOriginal('total_business_currency_cents');

        return $rawTotalBusiness !== null;
    }

    private function executeInvoiceCurrencyPayment(RecordPaymentDTO $dto, Invoice $invoice): Payment
    {
        $bankAccount = $this->resolveDepositGlAccount($dto);
        $receivableAccount = $this->getRequiredAccount($dto->teamId, '1100', 'Accounts Receivable');
        $vatOutputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $dto->teamId)
            ->where('code', '2100')
            ->first();

        $transaction = Transaction::query()->create([
            'team_id' => $dto->teamId,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Draft,
            'reference' => $dto->reference,
            'description' => 'Invoice payment '.$invoice->number,
            'transaction_date' => $dto->paymentDate,
            'created_by' => $dto->createdBy,
        ]);

        $amountCents = $dto->amountCents;

        if ($invoice->accrual_transaction_id !== null) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $bankAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => $amountCents,
                'currency' => $dto->currency,
                'description' => 'Payment received for '.$invoice->number,
            ]);

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $receivableAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $amountCents,
                'currency' => $dto->currency,
                'description' => 'Clear accounts receivable for '.$invoice->number,
            ]);

            $this->postTransactionAction->execute($transaction->fresh());

            return $this->finalizePayment($dto, $invoice, $transaction->id, null);
        }

        $vatPart = $this->calculateVatPart($invoice, $amountCents);
        $receivablePart = max(0, $amountCents - $vatPart);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bankAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => $amountCents,
            'currency' => $dto->currency,
            'description' => 'Payment received for '.$invoice->number,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $receivableAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => $receivablePart,
            'currency' => $dto->currency,
            'description' => 'Reduce accounts receivable for '.$invoice->number,
        ]);

        if ($vatPart > 0 && $vatOutputAccount !== null) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $vatOutputAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $vatPart,
                'currency' => $dto->currency,
                'description' => 'VAT output on payment for '.$invoice->number,
            ]);
        } elseif ($vatPart > 0) {
            $receivableOnly = JournalEntry::query()
                ->where('transaction_id', $transaction->id)
                ->where('account_id', $receivableAccount->id)
                ->firstOrFail();

            $receivableOnly->amount_cents = $amountCents;
            $receivableOnly->save();
        }

        $this->postTransactionAction->execute($transaction->fresh());

        return $this->finalizePayment($dto, $invoice, $transaction->id, null);
    }

    private function executeFunctionalCurrencyPayment(RecordPaymentDTO $dto, Invoice $invoice): Payment
    {
        $bookCurrency = Iso4217Currencies::normalize((string) (
            $invoice->business_currency_code
            ?? $invoice->team?->mergedBusinessSettings()['invoice_default_currency']
            ?? 'ZAR'
        ));

        $bankAccount = $this->resolveDepositGlAccount($dto);
        $receivableAccount = $this->getRequiredAccount($dto->teamId, '1100', 'Accounts Receivable');
        $vatOutputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $dto->teamId)
            ->where('code', '2100')
            ->first();

        $paymentInvoiceCents = $dto->amountCents;
        $totalInvoiceCents = max(1, (int) $invoice->getRawOriginal('total_cents'));
        $totalBusinessCents = (int) $invoice->getRawOriginal('total_business_currency_cents');

        $bookClearingBusiness = (int) round(($paymentInvoiceCents * $totalBusinessCents) / $totalInvoiceCents);
        $bankBusiness = $dto->bankAmountBusinessCents ?? $bookClearingBusiness;
        $fxDiff = $bankBusiness - $bookClearingBusiness;

        if ($fxDiff < 0 && ! $dto->bookFxLossToExpense) {
            throw ValidationException::withMessages([
                'book_fx_loss_to_expense' => __('The bank amount is below the book value of this payment. Confirm “Record foreign exchange loss to expenses” or adjust the bank amount.'),
            ]);
        }

        $hasAccrual = $invoice->accrual_transaction_id !== null;
        $vatPartBusiness = 0;
        $arPartBusiness = $bookClearingBusiness;

        if (! $hasAccrual) {
            $vatPartInvoice = $this->calculateVatPart($invoice, $paymentInvoiceCents);

            if ($vatPartInvoice > 0 && $vatOutputAccount !== null) {
                $vatPartBusiness = $paymentInvoiceCents > 0
                    ? (int) round(($vatPartInvoice * $bookClearingBusiness) / $paymentInvoiceCents)
                    : 0;
                $arPartBusiness = max(0, $bookClearingBusiness - $vatPartBusiness);
            } elseif ($vatPartInvoice > 0) {
                $vatPartBusiness = 0;
                $arPartBusiness = $bookClearingBusiness;
            }
        }

        $transaction = Transaction::query()->create([
            'team_id' => $dto->teamId,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Draft,
            'reference' => $dto->reference,
            'description' => 'Invoice payment '.$invoice->number,
            'transaction_date' => $dto->paymentDate,
            'created_by' => $dto->createdBy,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bankAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => $bankBusiness,
            'currency' => $bookCurrency,
            'description' => 'Payment received for '.$invoice->number,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $receivableAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => $arPartBusiness,
            'currency' => $bookCurrency,
            'description' => $hasAccrual
                ? 'Clear accounts receivable for '.$invoice->number
                : 'Reduce accounts receivable for '.$invoice->number,
        ]);

        if (! $hasAccrual && $vatPartBusiness > 0 && $vatOutputAccount !== null) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $vatOutputAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $vatPartBusiness,
                'currency' => $bookCurrency,
                'description' => 'VAT output on payment for '.$invoice->number,
            ]);
        }

        if ($fxDiff > 0) {
            $gainAccount = Account::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->where('code', '4950')
                ->first();
            if ($gainAccount === null) {
                throw ValidationException::withMessages([
                    'bank_amount_business_cents' => __('Missing chart account Foreign Exchange Gain (4950). Run chart setup or contact support.'),
                ]);
            }
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $gainAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $fxDiff,
                'currency' => $bookCurrency,
                'description' => 'Foreign exchange gain on '.$invoice->number,
            ]);
        } elseif ($fxDiff < 0) {
            $lossAccount = Account::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->where('code', '5900')
                ->first();
            if ($lossAccount === null) {
                throw ValidationException::withMessages([
                    'book_fx_loss_to_expense' => __('Missing chart account Foreign Exchange Loss (5900). Run chart setup or contact support.'),
                ]);
            }
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $lossAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => abs($fxDiff),
                'currency' => $bookCurrency,
                'description' => 'Foreign exchange loss on '.$invoice->number,
            ]);
        }

        $this->postTransactionAction->execute($transaction->fresh());

        return $this->finalizePayment($dto, $invoice, $transaction->id, $bankBusiness);
    }

    private function resolveDepositGlAccount(RecordPaymentDTO $dto): Account
    {
        $bankingAccount = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $dto->teamId)
            ->whereKey($dto->bankingAccountId)
            ->first();

        if ($bankingAccount === null) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('Select a valid banking account for this business.'),
            ]);
        }

        if (! $bankingAccount->is_active) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('That banking account is inactive.'),
            ]);
        }

        $gl = $bankingAccount->gl_account_id !== null
            ? Account::queryWithoutTeamScope()->whereKey($bankingAccount->gl_account_id)->first()
            : null;
        if ($gl === null || $bankingAccount->gl_account_id === null) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('Link that banking account to a ledger account first.'),
            ]);
        }

        if (! $gl->is_active) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('The linked ledger account is inactive.'),
            ]);
        }

        if ($gl->type !== AccountType::Asset) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('Invoice payments must be paid into an asset ledger account (bank or cash).'),
            ]);
        }

        return $gl;
    }

    private function finalizePayment(RecordPaymentDTO $dto, Invoice $invoice, int $transactionId, ?int $bankBusinessCents): Payment
    {
        $payment = Payment::queryWithoutTeamScope()->create([
            'team_id' => $dto->teamId,
            'invoice_id' => $invoice->id,
            'amount_cents' => $dto->amountCents,
            'currency' => $dto->currency,
            'bank_amount_business_cents' => $bankBusinessCents,
            'payment_date' => $dto->paymentDate,
            'method' => $dto->method,
            'reference' => $dto->reference,
            'notes' => $dto->notes,
            'transaction_id' => $transactionId,
            'banking_account_id' => $dto->bankingAccountId,
        ]);

        $newPaid = (int) $invoice->getRawOriginal('amount_paid_cents') + $dto->amountCents;
        $invoiceTotal = (int) $invoice->getRawOriginal('total_cents');
        $newPaidClamped = min($newPaid, $invoiceTotal);

        $invoice->amount_paid_cents = $newPaidClamped;
        $invoice->transaction_id = $invoice->transaction_id ?? $transactionId;
        $invoice->status = $newPaidClamped >= $invoiceTotal
            ? InvoiceStatus::Paid
            : InvoiceStatus::Partial;
        $invoice->paid_at = $invoice->status === InvoiceStatus::Paid ? now() : null;
        $invoice->save();

        return $payment->refresh();
    }

    private function assertDoesNotExceedAmountDue(Invoice $invoice, int $amountCents): void
    {
        $due = max(0, (int) $invoice->getRawOriginal('total_cents') - (int) $invoice->getRawOriginal('amount_paid_cents'));

        if ($amountCents > $due) {
            throw ValidationException::withMessages([
                'amount_cents' => __('Payment cannot exceed the amount due.'),
            ]);
        }
    }

    private function getRequiredAccount(int $teamId, string $code, string $label): Account
    {
        $account = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', $code)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __("Missing required chart account: {$label} ({$code})."),
            ]);
        }

        return $account;
    }

    private function calculateVatPart(Invoice $invoice, int $paymentAmountCents): int
    {
        $total = (int) $invoice->getRawOriginal('total_cents');
        $vat = (int) $invoice->getRawOriginal('vat_amount_cents');

        if ($total <= 0 || $vat <= 0) {
            return 0;
        }

        return (int) round(($paymentAmountCents * $vat) / $total);
    }
}
