<?php

namespace App\Support;

final class DestructiveDatabaseResetGuard
{
    /**
     * @var list<string>
     */
    private const COMMANDS = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
    ];

    /**
     * @param  list<string>  $argv
     */
    public static function shouldBlock(array $argv, string $appEnv, bool $allowOverride): bool
    {
        if ($allowOverride || $appEnv === 'testing') {
            return false;
        }

        return in_array(self::commandFromArgv($argv), self::COMMANDS, true);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function commandFromArgv(array $argv): ?string
    {
        foreach (array_slice($argv, 1) as $arg) {
            if (! is_string($arg) || $arg === '' || str_starts_with($arg, '-')) {
                continue;
            }

            return $arg;
        }

        return null;
    }

    public static function message(string $command, string $connection, string $database): string
    {
        return sprintf(
            'Blocked %s against database connection [%s] database [%s]. This command destroys data. '
            .'Set NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1 only when you intentionally want to wipe this database.',
            $command,
            $connection !== '' ? $connection : 'unknown',
            $database !== '' ? $database : 'unknown',
        );
    }
}
