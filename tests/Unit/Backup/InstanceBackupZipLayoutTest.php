<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Services\InstanceBackupZipLayout;
use Tests\TestCase;
use ZipArchive;

class InstanceBackupZipLayoutTest extends TestCase
{
    public function test_application_tree_moves_into_instance_backup_and_takeout_stays_at_the_root(): void
    {
        $zipPath = sys_get_temp_dir().'/nrth-layout-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('composer.json', '{}');
        $zip->addFromString('storage/app/private/1/receipt.pdf', 'receipt');
        $zip->addFromString('db-dumps/pgsql.sql', 'select 1;');
        $zip->addFromString('README.md', '# product');
        $zip->addFromString('takeout_backup/Acme/expense-receipts/receipt.pdf', 'receipt');
        $zip->close();

        try {
            (new InstanceBackupZipLayout)->moveProjectTree($zipPath);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $this->assertFalse($zip->locateName('composer.json'));
            $this->assertFalse($zip->locateName('storage/app/private/1/receipt.pdf'));
            $this->assertFalse($zip->locateName('db-dumps/pgsql.sql'));
            $this->assertFalse($zip->locateName('README.md'));
            $this->assertNotFalse($zip->locateName('instance_backup/composer.json'));
            $this->assertNotFalse($zip->locateName('instance_backup/storage/app/private/1/receipt.pdf'));
            $this->assertNotFalse($zip->locateName('instance_backup/db-dumps/pgsql.sql'));
            $this->assertSame('# product', $zip->getFromName('instance_backup/README.md'));
            $this->assertNotFalse($zip->locateName('takeout_backup/Acme/expense-receipts/receipt.pdf'));
            $zip->addFromString('README.md', '# restore');
            $zip->close();

            (new InstanceBackupZipLayout)->moveProjectTree($zipPath);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $this->assertSame('# restore', $zip->getFromName('README.md'));
            $this->assertSame('# product', $zip->getFromName('instance_backup/README.md'));
            $this->assertNotFalse($zip->locateName('instance_backup/composer.json'));
            $this->assertFalse($zip->locateName('instance_backup/instance_backup/composer.json'));
            $zip->close();
        } finally {
            @unlink($zipPath);
        }
    }
}
