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
     * Semver shown in the UI and compared to GitHub Releases.
     * Prefer version.txt (what git checkout / Release Please updates).
     */
    public function displayVersion(): string
    {
        $fromFile = $this->versionFile();
        if ($fromFile !== 'unknown') {
            return self::normalizeVersion($fromFile);
        }

        $fromConfig = trim($this->applicationVersion());

        return $fromConfig !== '' ? self::normalizeVersion($fromConfig) : '0.0.0';
    }

    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return '0.0.0';
        }

        return (string) preg_replace('/^[vV]/', '', $version);
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
