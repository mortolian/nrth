<?php

namespace Tests\Feature\Backup;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Backup\Services\InstanceBackupReadablePath;
use App\Domain\Backup\Services\InstanceBackupStorageCatalog;
use App\Domain\Backup\Services\InstanceBackupSupplement;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstanceBackupSupplementTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_include_raw_and_readable_copies_of_the_same_files(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['name' => 'Acme Books'])->save();

        $client = Client::factory()->for($team)->create(['name' => 'Acme Corp']);
        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'issue_date' => '2026-06-15',
            'number' => 'INV-2026-0099',
        ]);
        $invoicePdf = storage_path('framework/testing/tmp-invoice.pdf');
        File::ensureDirectoryExists(dirname($invoicePdf));
        File::put($invoicePdf, '%PDF-1.4 invoice');
        $invoice->addMedia($invoicePdf)->usingFileName('INV-2026-0099.pdf')->toMediaCollection('invoice-pdfs');
        File::delete($invoicePdf);

        $supplier = Supplier::factory()->for($team)->create(['name' => 'Stationery Co']);
        $expense = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'supplier_id' => $supplier->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'transaction_date' => '2026-06-20',
            'description' => 'Paper',
        ]);
        $receiptPath = storage_path('framework/testing/tmp-receipt.pdf');
        File::put($receiptPath, '%PDF-1.4 receipt');
        $expense->addMedia($receiptPath)->usingFileName('receipt.pdf')->toMediaCollection('attachments');
        File::delete($receiptPath);

        $bankAccount = BankingAccount::factory()->for($team)->create(['name' => 'FNB Cheque']);
        Storage::disk('local')->put('banking/1/2026/06/statement.csv', "Date,Amount\n");
        $import = BankingStatementImport::factory()->for($team)->for($bankAccount, 'account')->create([
            'stored_path' => 'banking/1/2026/06/statement.csv',
            'original_filename' => 'june-statement.csv',
        ]);
        $import->forceFill(['created_at' => '2026-06-21 10:00:00'])->save();

        $names = array_map(
            fn ($entry) => $entry->name,
            app(InstanceBackupSupplement::class)->entries(),
        );
        $nameSet = array_flip($names);

        $teamDir = InstanceBackupReadablePath::teamDirectory('Acme Books', $team->id);
        $this->assertContains('README.md', $names);
        $readme = collect(app(InstanceBackupSupplement::class)->entries())
            ->first(fn ($entry) => $entry->name === 'README.md');
        $this->assertNotNull($readme);
        $this->assertStringContainsString('APP_KEY', (string) $readme->contents);
        $this->assertStringContainsString('instance_backup', (string) $readme->contents);
        $this->assertStringContainsString('takeout_backup', (string) $readme->contents);
        $this->assertStringContainsString('db-dumps', (string) $readme->contents);
        $this->assertContains('instance_backup/README.txt', $names);
        $this->assertContains('takeout_backup/README.txt', $names);
        $this->assertContains(
            'takeout_backup/'.$teamDir.'/expense-receipts/2026-06-20_Stationery-Co_receipt.pdf',
            $names,
        );
        $this->assertContains(
            'takeout_backup/'.$teamDir.'/invoices/2026-06-15_INV-2026-0099_Acme-Corp.pdf',
            $names,
        );
        $this->assertContains(
            'takeout_backup/'.$teamDir.'/bank-statements/2026-06_FNB-Cheque_june-statement.csv',
            $names,
        );
        $catalog = app(InstanceBackupStorageCatalog::class);
        foreach ($catalog->files() as $path) {
            $expected = InstanceBackupReadablePath::instanceFile($catalog->storageRoot(), $path);
            $this->assertArrayHasKey($expected, $nameSet);
        }
    }
}
