<?php

namespace App\Domain\Backup\Services;

final class InstanceBackupZipEntry
{
    public function __construct(
        public string $name,
        public ?string $source = null,
        public ?string $contents = null,
        public ?int $bytes = null,
    ) {}
}
