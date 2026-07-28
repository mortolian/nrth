<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostInvoiceAccrualAction
{
    public function __construct(
        private readonly PostTransactionAction $postTransactionAction,
    ) {}

    public function execute(Invoice $invoice): ?Transaction
    {
        return DB::transaction(function () use ($invoice): ?Transaction {
            $invoice = Invoice::queryWithoutTeamScope()
                ->with(['lineItems', 'team'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($invoice->accrual_transaction_id !== null) {
                return Transaction::queryWithoutTeamScope()->find($invoice->accrual_transaction_id);
            }

            $team = $invoice->team ?? Team::query()->findOrFail($invoice->team_id);
            (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

            $receivable = $this->accountByCode((int) $invoice->team_id, '1100', 'Accounts Receivable');
            $vatOutput = Account::queryWithoutTeamScope()
                ->where('team_id', $invoice->team_id)
                ->where('code', '2100')
                ->first();
            $defaultIncome = $this->resolveDefaultIncomeAccount($invoice);
            $totalCents = (int) $invoice->getRawOriginal('total_cents');

            $incomeBuckets = [];
            $vatTotal = 0;
            $exclusiveTotal = 0;

            if ($invoice->lineItems->isEmpty()) {
                $vatTotal = (int) $invoice->getRawOriginal('vat_amount_cents');
                $exclusiveTotal = max(0, $totalCents - $vatTotal);
                $incomeBuckets[$defaultIncome->id] = $exclusiveTotal;
            } else {
                foreach ($invoice->lineItems as $line) {
                    /** @var InvoiceLineItem $line */
                    $exclusive = max(0, (int) $line->getRawOriginal('total_cents') - (int) $line->getRawOriginal('vat_amount_cents'));
                    $exclusiveTotal += $exclusive;
                    $vatTotal += (int) $line->getRawOriginal('vat_amount_cents');
                    $accountId = $line->income_account_id ?? $invoice->income_account_id ?? $defaultIncome->id;
                    $incomeBuckets[$accountId] = ($incomeBuckets[$accountId] ?? 0) + $exclusive;
                }
            }

            if ($totalCents <= 0) {
                return null;
            }

            $currency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));

            $transaction = Transaction::query()->create([
                'team_id' => $invoice->team_id,
                'type' => TransactionType::JournalAdjustment,
                'status' => TransactionStatus::Draft,
                'transaction_date' => optional($invoice->issue_date)?->toDateString() ?? now()->toDateString(),
                'description' => 'Invoice accrual '.$invoice->number,
                'reference' => $invoice->number,
            ]);

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $receivable->id,
                'type' => EntryType::Debit,
                'amount_cents' => $totalCents,
                'currency' => $currency,
                'description' => 'Accounts receivable for '.$invoice->number,
            ]);

            foreach ($incomeBuckets as $accountId => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $accountId,
                    'type' => EntryType::Credit,
                    'amount_cents' => $amount,
                    'currency' => $currency,
                    'description' => 'Revenue for '.$invoice->number,
                ]);
            }

            if ($vatTotal > 0 && $vatOutput !== null) {
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $vatOutput->id,
                    'type' => EntryType::Credit,
                    'amount_cents' => $vatTotal,
                    'currency' => $currency,
                    'description' => 'VAT output for '.$invoice->number,
                ]);
            }

            $posted = $this->postTransactionAction->execute($transaction->fresh(['journalEntries']));
            $invoice->forceFill(['accrual_transaction_id' => $posted->id])->save();

            return $posted;
        });
    }

    private function resolveDefaultIncomeAccount(Invoice $invoice): Account
    {
        if ($invoice->income_account_id) {
            $account = Account::queryWithoutTeamScope()
                ->where('team_id', $invoice->team_id)
                ->whereKey($invoice->income_account_id)
                ->first();
            if ($account !== null) {
                return $account;
            }
        }

        return $this->accountByCode((int) $invoice->team_id, '4000', 'Service Revenue');
    }

    private function accountByCode(int $teamId, string $code, string $label): Account
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
}
