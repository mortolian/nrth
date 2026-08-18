<?php

namespace App\Support\Upgrade;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * From this cutoff forward, schema changes on existing installs must be additive
 * (new tables/columns/indexes). Destructive up() changes need an expand/contract
 * pair and an explicit breaking note — do not sneak drop/rename into a normal migrate.
 */
final class AdditiveMigrationPolicy
{
    public const CUTOFF_DATE = '2026_08_18';

    /**
     * @return list<string>
     */
    public static function forbiddenUpFragments(): array
    {
        return [
            '->dropColumn(',
            '->renameColumn(',
            'Schema::drop(',
            'Schema::dropIfExists(',
        ];
    }

    /**
     * @return list<string>
     */
    public static function migrationDirectories(): array
    {
        return [
            database_path('migrations'),
            base_path('app/Modules'),
        ];
    }

    /**
     * @return list<array{path: string, basename: string}>
     */
    public function migrationsAtOrAfterCutoff(): array
    {
        $found = [];

        foreach (self::migrationDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $basename = $file->getBasename();
                if (! preg_match('/^(\d{4}_\d{2}_\d{2})_/', $basename, $matches)) {
                    continue;
                }

                if ($matches[1] < self::CUTOFF_DATE) {
                    continue;
                }

                $found[] = [
                    'path' => $file->getPathname(),
                    'basename' => $basename,
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => strcmp($a['basename'], $b['basename']));

        return $found;
    }

    /**
     * @return list<string>
     */
    public function violationsIn(string $contents): array
    {
        $upBody = $this->extractUpBody($contents);
        if ($upBody === null) {
            return ['could not extract up() body'];
        }

        $violations = [];
        foreach (self::forbiddenUpFragments() as $fragment) {
            if (str_contains($upBody, $fragment)) {
                $violations[] = $fragment;
            }
        }

        return $violations;
    }

    public function extractUpBody(string $contents): ?string
    {
        if (preg_match('/public function up\(\)(?:: void)?\s*\{(.*)\n\s*public function down\(/s', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
