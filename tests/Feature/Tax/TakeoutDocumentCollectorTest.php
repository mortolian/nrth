<?php

namespace Tests\Feature\Tax;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Contracting\Models\Contract;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class TakeoutDocumentCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_takeout_zip_includes_documents_for_invoices_receipts_bank_and_contracts(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $client = Client::factory()->for($team)->create(['name' => 'Acme Corp']);
        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-06-15',
            'number' => 'INV-2026-0099',
        ]);

        $invoicePdf = storage_path('app/private/tmp-invoice.pdf');
        File::ensureDirectoryExists(dirname($invoicePdf));
        File::put($invoicePdf, '%PDF-1.4 invoice');
        $invoice->addMedia($invoicePdf)->usingFileName('INV-2026-0099.pdf')->toMediaCollection('invoice-pdfs');
        File::delete($invoicePdf);

        $category = Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General']);
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Stationery Co']);
        $expense = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => $supplier->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'transaction_date' => '2026-06-20',
            'description' => 'Paper',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $expense->id,
            'account_id' => $category->id,
            'type' => EntryType::Debit,
            'amount_cents' => 200_00,
            'currency' => 'ZAR',
        ]);

        $receiptPath = storage_path('app/private/tmp-receipt.pdf');
        File::put($receiptPath, '%PDF-1.4 receipt');
        $expense->addMedia($receiptPath)->usingFileName('receipt.pdf')->toMediaCollection('attachments');
        File::delete($receiptPath);

        $bankAccount = BankingAccount::factory()->for($team)->create(['name' => 'FNB Cheque']);
        $storedPath = 'banking/1/2026/06/statement.csv';
        Storage::disk('local')->put($storedPath, "Date,Amount\n2026-06-10,-100");
        $import = BankingStatementImport::factory()->for($team)->for($bankAccount, 'account')->create([
            'stored_path' => $storedPath,
            'original_filename' => 'june-statement.csv',
        ]);
        BankingTransaction::factory()->for($team)->for($bankAccount, 'account')->create([
            'banking_statement_import_id' => $import->id,
            'transaction_date' => '2026-06-10',
            'duplicate_key' => hash('sha256', 'unique-'.uniqid()),
        ]);

        $contract = Contract::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'title' => 'Master Agreement',
            'status' => 'active',
            'billing_type' => 'fixed',
            'start_date' => '2026-01-01',
            'contract_value_cents' => 100_000_00,
        ]);
        $contractPdf = storage_path('app/private/tmp-contract.pdf');
        File::put($contractPdf, '%PDF-1.4 contract');
        $contract->addMedia($contractPdf)->usingFileName('msa.pdf')->toMediaCollection('signed-contract');
        File::delete($contractPdf);

        $run = TakeoutRun::factory()->for($team)->create([
            'requested_by' => $user->id,
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-30',
        ]);

        (new GenerateTakeoutJob($run->id))->handle(app(\App\Domain\Takeout\Services\TakeoutBuilder::class));

        $run->refresh();
        $zipPath = storage_path('app/private/'.$run->storage_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        $root = 'nrth-takeout_2026-06-01_to_2026-06-30';
        $this->assertNotFalse($zip->locateName($root.'/documents/invoices/2026-06-15_INV-2026-0099_Acme-Corp.pdf'));
        $this->assertNotFalse($zip->locateName($root.'/documents/expense-receipts/2026-06-20_Stationery-Co_R200.00.pdf'));
        $this->assertTrue($this->bankStatementInZip($zip, $root));
        $this->assertNotFalse($zip->locateName($root.'/documents/contracts/2026-01-01_Acme-Corp_Master-Agreement.pdf'));

        $zip->close();
    }

    private function bankStatementInZip(ZipArchive $zip, string $root): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_starts_with($name, $root.'/documents/bank-statements/')) {
                return true;
            }
        }

        return false;
    }
}
