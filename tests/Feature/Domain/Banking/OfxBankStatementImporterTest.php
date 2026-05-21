<?php

namespace Tests\Feature\Domain\Banking;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Importers\OfxBankStatementImporter;
use Tests\TestCase;

class OfxBankStatementImporterTest extends TestCase
{
    public function test_parses_ofx_transactions(): void
    {
        $path = base_path('tests/Fixtures/bank-statements/sample.ofx');

        $parsed = $this->app->make(OfxBankStatementImporter::class)->parse($path);

        $this->assertCount(2, $parsed->transactions);

        $debit = $parsed->transactions[0];
        $this->assertSame('2026-01-08', $debit->transactionDate);
        $this->assertSame('Coffee shop purchase', $debit->description);
        $this->assertSame('89.99', $debit->amount);
        $this->assertSame(TransactionDirection::Debit, $debit->direction);
        $this->assertSame('20260108001', $debit->reference);

        $credit = $parsed->transactions[1];
        $this->assertSame(TransactionDirection::Credit, $credit->direction);
        $this->assertSame('450.00', $credit->amount);
    }
}
