<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Exceptions\IncompleteInstanceBackup;
use App\Domain\Backup\Services\InstanceBackupArchiveVerifier;
use App\Domain\Backup\Services\InstanceBackupReadablePath;
use App\Domain\Backup\Services\InstanceBackupStorageCatalog;
use App\Domain\Backup\Services\InstanceBackupZipEntry;
use App\Domain\Backup\Services\InstanceBackupZipWriter;
use Tests\TestCase;
use ZipArchive;

class InstanceBackupReadablePathTest extends TestCase
{
    public function test_readable_names_keep_dates_parties_and_original_filenames(): void
    {
        $this->assertSame(
            'Acme-Books-4',
            InstanceBackupReadablePath::teamDirectory('Acme Books', 4),
        );
        $this->assertSame(
            '2026-06-20_Stationery-Co_receipt.pdf',
            InstanceBackupReadablePath::expenseReceipt('2026-06-20', 'Stationery Co', 'receipt.pdf'),
        );
        $this->assertSame(
            '2026-06-15_INV-2026-0099_Acme-Corp.pdf',
            InstanceBackupReadablePath::datedDocument('2026-06-15', 'INV-2026-0099', 'Acme Corp', 'INV-2026-0099.pdf'),
        );
        $this->assertSame(
            '2026-06_FNB-Cheque_june-statement.csv',
            InstanceBackupReadablePath::bankStatement('2026-06', 'FNB Cheque', 'june-statement.csv'),
        );
    }

    public function test_instance_backup_path_keeps_the_storage_relative_path(): void
    {
        $this->assertSame(
            'instance_backup/app/public/7/receipt.pdf',
            InstanceBackupReadablePath::instanceFile('/var/www/html/storage', '/var/www/html/storage/app/public/7/receipt.pdf'),
        );
    }

    public function test_writer_adds_both_roots_and_verifier_accepts_them(): void
    {
        $root = sys_get_temp_dir().'/nrth-readable-'.bin2hex(random_bytes(4));
        mkdir($root.'/app/private/1', 0777, true);
        $receipt = $root.'/app/private/1/receipt.pdf';
        file_put_contents($receipt, 'receipt-bytes');

        $zipPath = sys_get_temp_dir().'/nrth-readable-zip-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('db-dumps/database.sql', 'select 1;');
        $zip->close();

        $catalog = new InstanceBackupStorageCatalog($root, 'nrth');
        $entries = [
            new InstanceBackupZipEntry(
                InstanceBackupReadablePath::instanceFile($root, $receipt),
                $receipt,
                null,
                filesize($receipt),
            ),
            new InstanceBackupZipEntry(
                'takeout_backup/Acme-Books-1/expense-receipts/2026-06-20_Stationery-Co_receipt.pdf',
                $receipt,
                null,
                filesize($receipt),
            ),
        ];

        try {
            (new InstanceBackupZipWriter)->add($zipPath, $entries);
            (new InstanceBackupArchiveVerifier($catalog))->assertEntries($zipPath, $entries);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $this->assertIsArray($zip->statName('instance_backup/app/private/1/receipt.pdf'));
            $this->assertIsArray($zip->statName('takeout_backup/Acme-Books-1/expense-receipts/2026-06-20_Stationery-Co_receipt.pdf'));
            $zip->close();
        } finally {
            @unlink($zipPath);
            $this->deleteTree($root);
        }
    }

    public function test_verifier_rejects_a_zip_missing_the_readable_copy(): void
    {
        $zipPath = sys_get_temp_dir().'/nrth-readable-missing-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('db-dumps/database.sql', 'select 1;');
        $zip->close();

        $catalog = new InstanceBackupStorageCatalog(sys_get_temp_dir(), 'nrth');

        $this->expectException(IncompleteInstanceBackup::class);

        try {
            (new InstanceBackupArchiveVerifier($catalog))->assertEntries($zipPath, [
                new InstanceBackupZipEntry('takeout_backup/Acme-1/expense-receipts/receipt.pdf', null, 'receipt', 7),
            ]);
        } finally {
            @unlink($zipPath);
        }
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->deleteTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
