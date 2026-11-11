<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Exceptions\IncompleteInstanceBackup;
use App\Domain\Backup\Listeners\IncludeInstanceStorageInBackup;
use App\Domain\Backup\Services\InstanceBackupArchiveVerifier;
use App\Domain\Backup\Services\InstanceBackupStorageCatalog;
use RuntimeException;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Tasks\Backup\Manifest;
use Tests\TestCase;
use ZipArchive;

class InstanceBackupStorageCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $created = sys_get_temp_dir().'/nrth-backup-storage-'.bin2hex(random_bytes(4));
        mkdir($created.'/app/public/7', 0777, true);
        $this->root = realpath($created);
        $this->assertNotFalse($this->root);
        mkdir($this->root.'/app/private/1', 0777, true);
        mkdir($this->root.'/app/private/nrth', 0777, true);
        mkdir($this->root.'/app/backup-temp', 0777, true);
        mkdir($this->root.'/framework/cache', 0777, true);
        mkdir($this->root.'/logs', 0777, true);

        file_put_contents($this->root.'/app/public/7/Invoice_202607164978.pdf', 'receipt-bytes');
        file_put_contents($this->root.'/app/private/1/logo.png', 'logo-bytes');
        file_put_contents($this->root.'/app/private/nrth/2026-11-10.zip', 'previous-backup');
        file_put_contents($this->root.'/app/backup-temp/partial.zip', 'temp');
        file_put_contents($this->root.'/framework/cache/data.php', '<?php return [];');
        file_put_contents($this->root.'/logs/laravel.log', 'log');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_catalog_includes_uploads_and_skips_runtime_and_backup_zips(): void
    {
        $files = (new InstanceBackupStorageCatalog($this->root, 'nrth'))->files();

        $this->assertEqualsCanonicalizing([
            $this->root.'/app/public/7/Invoice_202607164978.pdf',
            $this->root.'/app/private/1/logo.png',
        ], $files);
    }

    public function test_listener_adds_storage_files_missing_from_the_manifest(): void
    {
        $catalog = new InstanceBackupStorageCatalog($this->root, 'nrth');
        $manifestPath = sys_get_temp_dir().'/nrth-backup-manifest-'.bin2hex(random_bytes(4)).'.txt';
        $manifest = Manifest::create($manifestPath);
        $manifest->addFiles($this->root.'/app/private/1/logo.png');

        (new IncludeInstanceStorageInBackup($catalog))->handle(new BackupManifestWasCreated($manifest));

        $listed = [];
        foreach ($manifest->files() as $path) {
            $listed[] = $path;
        }

        $this->assertContains($this->root.'/app/private/1/logo.png', $listed);
        $this->assertContains($this->root.'/app/public/7/Invoice_202607164978.pdf', $listed);
        $this->assertCount(2, $listed);
        @unlink($manifestPath);
    }

    public function test_verifier_rejects_a_zip_that_omits_a_receipt(): void
    {
        $catalog = new InstanceBackupStorageCatalog($this->root, 'nrth');
        $zipPath = sys_get_temp_dir().'/nrth-backup-incomplete-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('db-dumps/pgsql-nrth.sql', 'select 1;');
        $zip->close();

        $this->expectException(IncompleteInstanceBackup::class);
        $this->expectExceptionMessage('Invoice_202607164978.pdf');

        try {
            (new InstanceBackupArchiveVerifier($catalog))->assertComplete($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_verifier_accepts_a_zip_that_contains_every_storage_file(): void
    {
        $catalog = new InstanceBackupStorageCatalog($this->root, 'nrth');
        $zipPath = sys_get_temp_dir().'/nrth-backup-complete-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($catalog->files() as $path) {
            $name = $catalog->zipEntryNames($path)[0];
            $zip->addFile($path, $name);
        }
        $zip->close();

        try {
            (new InstanceBackupArchiveVerifier($catalog))->assertComplete($zipPath);
        } finally {
            @unlink($zipPath);
        }

        $this->addToAssertionCount(1);
    }

    public function test_catalog_fails_when_storage_root_is_missing(): void
    {
        $this->expectException(RuntimeException::class);

        (new InstanceBackupStorageCatalog($this->root.'/missing', 'nrth'))->files();
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.'/'.$item;
            if (is_dir($child) && ! is_link($child)) {
                $this->deleteTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
