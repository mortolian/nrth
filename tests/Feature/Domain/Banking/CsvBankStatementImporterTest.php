<?php

namespace Tests\Feature\Domain\Banking;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Importers\CsvBankStatementImporter;
use Tests\TestCase;

class CsvBankStatementImporterTest extends TestCase
{
    private function importer(): CsvBankStatementImporter
    {
        return $this->app->make(CsvBankStatementImporter::class);
    }

    public function test_parses_signed_amount_column(): void
    {
        $path = base_path('tests/Fixtures/bank-statements/sample-signed-amount.csv');

        $parsed = $this->importer()->parse($path, [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'amount' => 'Amount',
                'reference' => 'Reference',
            ],
            'delimiter' => ',',
            'headers' => ['Date', 'Description', 'Amount', 'Reference'],
        ]);

        $this->assertCount(3, $parsed->transactions);

        $first = $parsed->transactions[0];
        $this->assertSame('2026-01-05', $first->transactionDate);
        $this->assertSame('Grocery purchase', $first->description);
        $this->assertSame('250.50', $first->amount);
        $this->assertSame(TransactionDirection::Debit, $first->direction);
        $this->assertSame('REF001', $first->reference);

        $credit = $parsed->transactions[1];
        $this->assertSame(TransactionDirection::Credit, $credit->direction);
        $this->assertSame('15000.00', $credit->amount);
    }

    public function test_parses_debit_and_credit_columns(): void
    {
        $path = base_path('tests/Fixtures/bank-statements/sample-debit-credit.csv');

        $parsed = $this->importer()->parse($path, [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'debit' => 'Debit',
                'credit' => 'Credit',
                'reference' => 'Reference',
                'date_format' => 'd/m/Y',
            ],
            'delimiter' => ';',
            'headers' => ['Date', 'Description', 'Debit', 'Credit', 'Reference'],
        ]);

        $this->assertCount(2, $parsed->transactions);

        $debit = $parsed->transactions[0];
        $this->assertSame('2026-01-05', $debit->transactionDate);
        $this->assertSame(TransactionDirection::Debit, $debit->direction);
        $this->assertSame('125.50', $debit->amount);

        $credit = $parsed->transactions[1];
        $this->assertSame(TransactionDirection::Credit, $credit->direction);
        $this->assertSame('2500.00', $credit->amount);
    }
}
