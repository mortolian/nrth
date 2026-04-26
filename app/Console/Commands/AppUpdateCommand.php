<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class AppUpdateCommand extends Command
{
    protected $signature = 'app:update';

    protected $description = 'Maintenance mode, migrate, rebuild caches, signal queue workers, then bring the application back online.';

    public function handle(): int
    {
        $this->components->info(config('app.name').' application update');
        $this->newLine();

        try {
            $this->components->task('Enabling maintenance mode', function (): void {
                Artisan::call('down', ['--render' => 'errors::503', '--ansi' => true]);
            });

            $this->components->task('Running pending migrations', function (): void {
                Artisan::call('migrate', ['--force' => true, '--ansi' => true]);
                if (Artisan::output() !== '') {
                    $this->output->write(Artisan::output());
                }
            });

            $this->components->task('Clearing config, route, and view caches', function (): void {
                Artisan::call('config:clear', ['--ansi' => true]);
                Artisan::call('route:clear', ['--ansi' => true]);
                Artisan::call('view:clear', ['--ansi' => true]);
            });

            $this->components->task('Rebuilding config, route, and view caches', function (): void {
                Artisan::call('config:cache', ['--ansi' => true]);
                Artisan::call('route:cache', ['--ansi' => true]);
                Artisan::call('view:cache', ['--ansi' => true]);
            });

            $this->components->task('Signaling queue workers to restart', function (): void {
                Artisan::call('queue:restart', ['--ansi' => true]);
            });

            $this->components->task('Terminating Horizon (if running)', function (): void {
                try {
                    Artisan::call('horizon:terminate', ['--ansi' => true]);
                } catch (Throwable $e) {
                    $this->components->warn('Horizon: '.$e->getMessage());
                }
            });
        } finally {
            $this->newLine();
            $this->components->task('Disabling maintenance mode', function (): void {
                Artisan::call('up', ['--ansi' => true]);
            });
        }

        $this->newLine(2);
        $version = (string) config('app.version', '0.0.0');
        $this->components->success("Update finished. Application version: {$version}");
        $this->newLine();
        $this->components->info('Changelog summary');
        $this->line($this->changelogSummaryForCurrentVersion());
        $this->newLine();

        return self::SUCCESS;
    }

    private function changelogSummaryForCurrentVersion(): string
    {
        $path = base_path('CHANGELOG.md');

        if (! is_file($path)) {
            return '  No CHANGELOG.md file was found in the project root.';
        }

        $content = (string) file_get_contents($path);
        $version = preg_quote((string) config('app.version', '0.0.0'), '/');

        if (preg_match('/## \['.$version.'\][^\n]*\R(.*?)(?=\R## |\z)/s', $content, $matches)) {
            $body = trim($matches[1]);

            return $body !== '' ? $this->indentBlock($body) : '  (No notes under this version heading.)';
        }

        if (preg_match('/## \[[^\]]+\][^\n]*\R(.*?)(?=\R## |\z)/s', $content, $fallback)) {
            $body = trim($fallback[1]);

            return $body !== ''
                ? $this->indentBlock($body)
                : '  (CHANGELOG.md has no body under the first release section.)';
        }

        return '  CHANGELOG.md could not be parsed for release notes.';
    }

    private function indentBlock(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => '  '.$line)
            ->implode("\n");
    }
}
