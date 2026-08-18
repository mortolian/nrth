<?php

namespace App\Support\Upgrade;

use Illuminate\Database\Migrations\Migrator;

final class SchemaUpgradeStatus
{
    public function applicationVersion(): string
    {
        return (string) config('app.version', '0.0.0');
    }

    public function versionFile(): string
    {
        $path = base_path('version.txt');
        if (! is_file($path)) {
            return 'unknown';
        }

        $value = trim((string) file_get_contents($path));

        return $value !== '' ? $value : 'unknown';
    }

    /**
     * @return list<string>
     */
    public function pendingMigrationNames(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection((string) config('database.default'));

        if (! $migrator->repositoryExists()) {
            return ['(migration repository is not installed)'];
        }

        $paths = array_values(array_unique(array_merge(
            [database_path('migrations')],
            $migrator->paths(),
        )));

        $files = $migrator->getMigrationFiles($paths);
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());

        return array_values($pending);
    }
}
