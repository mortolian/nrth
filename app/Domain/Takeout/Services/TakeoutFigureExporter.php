<?php

namespace App\Domain\Takeout\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Takeout\DTOs\TakeoutDocumentExportResult;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Support\TakeoutSpreadsheetWriter;

final class TakeoutFigureExporter
{
    public function __construct(
        private readonly TakeoutDataCollector $collector,
        private readonly TakeoutSpreadsheetWriter $writer,
    ) {}

    public function export(string $figuresDirectory, TakeoutRun $run, ?TakeoutDocumentExportResult $documents = null): void
    {
        $documents ??= new TakeoutDocumentExportResult;

        $this->exportIncomeStatement($figuresDirectory, $run);
        $this->exportTrialBalance($figuresDirectory, $run);
        $this->exportInvoicesRegister($figuresDirectory, $run, $documents);
        $this->exportExpensesRegister($figuresDirectory, $run, $documents);
        $this->exportPaymentsRegister($figuresDirectory, $run);
        $this->exportBankTransactions($figuresDirectory, $run);
        $this->exportVatPeriods($figuresDirectory, $run);
        $this->exportContractsRegister($figuresDirectory, $run, $documents);
    }

    private function exportIncomeStatement(string $directory, TakeoutRun $run): void
    {
        $pl = $this->collector->profitAndLoss($run);
        $headers = ['section', 'account_code', 'account_name', 'amount_cents'];
        $rows = [];

        foreach ($pl['income'] as $line) {
            $rows[] = ['income', $line['code'], $line['name'], $line['amount']];
        }
        foreach ($pl['expenses'] as $line) {
            $rows[] = ['expense', $line['code'], $line['name'], $line['amount']];
        }

        $rows[] = ['total', '', 'total_income', $pl['totals']['income']];
        $rows[] = ['total', '', 'total_expenses', $pl['totals']['expenses']];
        $rows[] = ['total', '', 'net_profit', $pl['totals']['net_profit']];

        $this->writer->writePair($directory, 'income-statement', $headers, $rows);
    }

    private function exportTrialBalance(string $directory, TakeoutRun $run): void
    {
        $headers = ['account_code', 'account_name', 'account_type', 'debit_cents', 'credit_cents'];
        $rows = [];

        foreach ($this->collector->trialBalance($run) as $row) {
            $account = $row->account;
            $debit = $row->debit_total->getMinorAmount()->toInt();
            $credit = $row->credit_total->getMinorAmount()->toInt();

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $rows[] = [
                $account->code,
                $account->name,
                $account->type->value,
                $debit,
                $credit,
            ];
        }

        $this->writer->writePair($directory, 'trial-balance', $headers, $rows);
    }

    private function exportInvoicesRegister(string $directory, TakeoutRun $run, TakeoutDocumentExportResult $documents): void
    {
        $headers = [
            'id', 'number', 'status', 'issue_date', 'due_date', 'client_name',
            'currency', 'subtotal_cents', 'vat_amount_cents', 'total_cents',
            'company_currency_code', 'fx_rate_invoice_to_company', 'fx_rate_date', 'total_company_currency_cents',
            'voided_at', 'sent_at', 'pdf_filename',
        ];

        $rows = $this->collector->invoices($run)->map(function (Invoice $invoice) use ($documents): array {
            $companyCode = $invoice->company_currency_code ?? $invoice->currency;
            $companyTotal = $invoice->getRawOriginal('total_company_currency_cents') ?? $invoice->getRawOriginal('total_cents');

            return [
                $invoice->id,
                $invoice->number,
                $invoice->status->value,
                $invoice->issue_date?->toDateString(),
                $invoice->due_date?->toDateString(),
                $invoice->client?->name,
                $invoice->currency,
                (int) $invoice->getRawOriginal('subtotal_cents'),
                (int) $invoice->getRawOriginal('vat_amount_cents'),
                (int) $invoice->getRawOriginal('total_cents'),
                $companyCode,
                $invoice->fx_rate_invoice_to_company,
                $invoice->fx_rate_date?->toDateString(),
                (int) $companyTotal,
                $invoice->voided_at?->toDateTimeString(),
                $invoice->sent_at?->toDateTimeString(),
                $documents->invoicePdfFilenames[$invoice->id] ?? '',
            ];
        })->all();

        $this->writer->writePair($directory, 'invoices-register', $headers, $rows);
    }

    private function exportExpensesRegister(string $directory, TakeoutRun $run, TakeoutDocumentExportResult $documents): void
    {
        $headers = [
            'id', 'transaction_date', 'reference', 'description', 'supplier_name',
            'category_account_code', 'category_account_name',
            'amount_excl_vat_cents', 'vat_amount_cents', 'total_cents', 'currency',
            'has_receipt', 'receipt_filename',
        ];

        $rows = $this->collector->expenses($run)->map(function ($transaction) use ($documents): array {
            $totalCents = $this->collector->expenseTotalCents($transaction);
            $vatCents = $this->collector->expenseVatCents($transaction);
            $categoryLine = $transaction->journalEntries
                ->first(fn ($line) => $line->account?->type === AccountType::Expense && $line->type === EntryType::Debit);

            return [
                $transaction->id,
                $transaction->transaction_date?->toDateString(),
                $transaction->reference,
                $transaction->description,
                $transaction->supplier?->name,
                $categoryLine?->account?->code,
                $categoryLine?->account?->name,
                max(0, $totalCents - $vatCents),
                $vatCents,
                $totalCents,
                $categoryLine?->currency ?? 'ZAR',
                $transaction->media_count > 0 ? 'yes' : 'no',
                $documents->expenseReceiptFilenames[$transaction->id] ?? '',
            ];
        })->all();

        $this->writer->writePair($directory, 'expenses-register', $headers, $rows);
    }

    private function exportPaymentsRegister(string $directory, TakeoutRun $run): void
    {
        $headers = [
            'id', 'payment_date', 'invoice_number', 'client_name',
            'amount_cents', 'currency', 'bank_amount_company_cents', 'method', 'reference',
        ];

        $rows = $this->collector->payments($run)->map(fn ($payment): array => [
            $payment->id,
            $payment->payment_date?->toDateString(),
            $payment->invoice?->number,
            $payment->invoice?->client?->name,
            (int) $payment->getRawOriginal('amount_cents'),
            $payment->currency,
            $payment->bank_amount_company_cents,
            $payment->method->value,
            $payment->reference,
        ])->all();

        $this->writer->writePair($directory, 'payments-register', $headers, $rows);
    }

    private function exportBankTransactions(string $directory, TakeoutRun $run): void
    {
        $headers = [
            'transaction_date', 'account_name', 'description', 'reference',
            'amount', 'currency', 'direction', 'import_original_filename',
        ];

        $importsById = $this->collector->bankStatementImports($run)->keyBy('id');

        $rows = $this->collector->bankTransactions($run)->map(function ($txn) use ($importsById): array {
            $import = $txn->banking_statement_import_id
                ? $importsById->get($txn->banking_statement_import_id)
                : null;

            return [
                $txn->transaction_date?->toDateString(),
                $txn->account?->name,
                $txn->description,
                $txn->reference,
                $txn->amount,
                $txn->currency,
                $txn->direction instanceof \BackedEnum ? $txn->direction->value : $txn->direction,
                $import?->original_filename,
            ];
        })->all();

        $this->writer->writePair($directory, 'bank-transactions', $headers, $rows);
    }

    private function exportVatPeriods(string $directory, TakeoutRun $run): void
    {
        $headers = [
            'period_start', 'period_end', 'status', 'output_vat_cents', 'input_vat_cents', 'net_vat_cents',
        ];

        $rows = $this->collector->vatPeriods($run)->map(function ($period): array {
            $amounts = $this->collector->vatPeriodAmounts($period);

            return [
                $period->period_start?->toDateString(),
                $period->period_end?->toDateString(),
                $period->status->value,
                $amounts['output_vat_cents'],
                $amounts['input_vat_cents'],
                $amounts['net_vat_cents'],
            ];
        })->all();

        $this->writer->writePair($directory, 'vat-periods', $headers, $rows);
    }

    private function exportContractsRegister(string $directory, TakeoutRun $run, TakeoutDocumentExportResult $documents): void
    {
        $headers = [
            'id', 'title', 'client_name', 'status', 'billing_type',
            'start_date', 'end_date', 'contract_value_cents', 'has_signed_file', 'signed_filename',
        ];

        $rows = $this->collector->contracts($run)->map(fn ($contract): array => [
            $contract->id,
            $contract->title,
            $contract->client?->name,
            $contract->status,
            $contract->billing_type,
            $contract->start_date?->toDateString(),
            $contract->end_date?->toDateString(),
            (int) $contract->contract_value_cents,
            $contract->getFirstMedia('signed-contract') !== null ? 'yes' : 'no',
            $documents->contractSignedFilenames[$contract->id] ?? '',
        ])->all();

        $this->writer->writePair($directory, 'contracts-register', $headers, $rows);
    }
}
