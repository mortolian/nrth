<?php

namespace App\Domain\Backup\Services;

use App\Domain\Takeout\Support\TakeoutFilename;

final class InstanceBackupReadablePath
{
    public const INSTANCE_ROOT = 'instance_backup';

    public const TAKEOUT_ROOT = 'takeout_backup';

    public static function instanceFile(string $storageRoot, string $absolutePath): string
    {
        $root = rtrim($storageRoot, DIRECTORY_SEPARATOR);
        $prefix = $root.DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolutePath, $prefix)
            ? substr($absolutePath, strlen($prefix))
            : basename($absolutePath);

        return self::INSTANCE_ROOT.'/'.str_replace('\\', '/', $relative);
    }

    public static function teamDirectory(string $teamName, int $teamId): string
    {
        return TakeoutFilename::sanitize($teamName, 60).'-'.$teamId;
    }

    public static function expenseReceipt(string $date, string $label, string $originalFilename): string
    {
        return $date.'_'.TakeoutFilename::sanitize($label).'_'.TakeoutFilename::sanitize($originalFilename, 80);
    }

    public static function datedDocument(string $date, string $number, string $party, string $extension): string
    {
        $ext = self::extension($extension);

        return $date.'_'.TakeoutFilename::sanitize($number).'_'.TakeoutFilename::sanitize($party).'.'.$ext;
    }

    public static function bankStatement(string $yearMonth, string $account, string $originalFilename): string
    {
        return $yearMonth.'_'.TakeoutFilename::sanitize($account).'_'.TakeoutFilename::sanitize($originalFilename, 120);
    }

    public static function extension(string $filenameOrExtension): string
    {
        $extension = str_contains($filenameOrExtension, '.')
            ? pathinfo($filenameOrExtension, PATHINFO_EXTENSION)
            : $filenameOrExtension;
        $extension = strtolower($extension);
        if (! preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            return 'bin';
        }

        return $extension;
    }
}
