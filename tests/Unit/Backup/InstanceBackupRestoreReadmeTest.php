<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Services\InstanceBackupRestoreReadme;
use App\Domain\Backup\Services\InstanceBackupZipEntry;
use App\Domain\Backup\Services\InstanceBackupZipWriter;
use Tests\TestCase;
use ZipArchive;

class InstanceBackupRestoreReadmeTest extends TestCase
{
    public function test_readme_explains_a_new_instance_and_names_the_zip(): void
    {
        $contents = app(InstanceBackupRestoreReadme::class)->contents('2026-11-11-03-00-01.zip');

        $this->assertStringContainsString('# nrth instance backup', $contents);
        $this->assertStringContainsString('APP_KEY', $contents);
        $this->assertStringContainsString('instance_backup', $contents);
        $this->assertStringContainsString('takeout_backup', $contents);
        $this->assertStringContainsString('2026-11-11-03-00-01.zip', $contents);
        $this->assertStringContainsString('#!/usr/bin/env bash', $contents);
        $this->assertStringContainsString('$RUNNER exec -T app unzip -o "$ZIP"', $contents);
        $this->assertStringNotContainsString('\$RUNNER', $contents);
    }

    public function test_writer_replaces_the_project_readme_at_the_zip_root(): void
    {
        $zipPath = sys_get_temp_dir().'/nrth-readme-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('README.md', '# nrth product readme');
        $zip->close();

        $contents = "# nrth instance backup\n\nRestore steps.\n";

        try {
            (new InstanceBackupZipWriter)->add($zipPath, [
                new InstanceBackupZipEntry('README.md', null, $contents, strlen($contents)),
            ]);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $this->assertSame($contents, $zip->getFromName('README.md'));
            $zip->close();
        } finally {
            @unlink($zipPath);
        }
    }
}
