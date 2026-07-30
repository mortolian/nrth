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

    public static function isDestructiveCommand(?string $command): bool
    {
        return $command !== null && in_array($command, self::COMMANDS, true);
    }

    /**
     * Only sqlite :memory: is treated as disposable for automated tests.
     */
    public static function isDisposableTestDatabase(string $connection, string $database): bool
    {
        return $connection === 'sqlite' && ($database === ':memory:' || $database === '');
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

            // `artisan test` / `phpunit` are not themselves destructive; nested
            // Artisan::call('migrate:fresh') is handled via CommandStarting.
            return $arg;
        }

        return null;
    }

    /**
     * Block destructive resets against any non-disposable database.
     *
     * APP_ENV=testing alone is not enough: misconfigured PHPUnit can still
     * point RefreshDatabase at the app Postgres. Only sqlite :memory: (or an
     * explicit override) is allowed.
     *
     * @param  list<string>  $argv
     */
    public static function shouldBlock(
        array $argv,
        string $appEnv,
        bool $allowOverride,
        string $connection = '',
        string $database = '',
    ): bool {
        return self::shouldBlockCommand(
            self::commandFromArgv($argv),
            $allowOverride,
            $connection,
            $database,
        );
    }

    public static function shouldBlockCommand(
        ?string $command,
        bool $allowOverride,
        string $connection,
        string $database,
    ): bool {
        if ($allowOverride || ! self::isDestructiveCommand($command)) {
            return false;
        }

        if (self::isDisposableTestDatabase($connection, $database)) {
            return false;
        }

        return true;
    }

    public static function message(string $command, string $connection, string $database): string
    {
        return sprintf(
            'Blocked %s against database connection [%s] database [%s]. This command destroys data. '
            .'Tests must use sqlite :memory: (see phpunit.xml). '
            .'Set NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1 only when you intentionally want to wipe this database.',
            $command,
            $connection !== '' ? $connection : 'unknown',
            $database !== '' ? $database : 'unknown',
        );
    }
}
