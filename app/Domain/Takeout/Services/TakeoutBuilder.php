<?php

namespace App\Domain\Takeout\Services;

use App\Domain\Takeout\Models\TakeoutRun;
use Illuminate\Support\Facades\File;
use ZipArchive;

final class TakeoutBuilder
{
    public function __construct(
        private readonly TakeoutDataCollector $collector,
        private readonly TakeoutFigureExporter $figureExporter,
        private readonly TakeoutDocumentCollector $documentCollector,
        private readonly TakeoutGapReporter $gapReporter,
    ) {}

    /**
     * @return array{storage_path: string, file_size_bytes: int, manifest: array<string, mixed>}
     */
    public function build(TakeoutRun $run): array
    {
        $rootName = sprintf(
            'nrth-takeout_%s_to_%s',
            $run->from_date->format('Y-m-d'),
            $run->to_date->format('Y-m-d'),
        );

        $workDir = storage_path('app/private/takeout-work/'.uniqid('run-', true));
        $rootDir = $workDir.'/'.$rootName;

        File::ensureDirectoryExists($rootDir.'/figures');
        File::ensureDirectoryExists($rootDir.'/documents');

        try {
            $documents = $this->documentCollector->export($rootDir.'/documents', $run);
            $this->figureExporter->export($rootDir.'/figures', $run, $documents);

            $gaps = array_merge(
                $this->gapReporter->collect($run),
                $documents->warnings,
            );
            File::put($rootDir.'/gaps.txt', $gaps === [] ? "No gaps detected.\n" : implode("\n", $gaps)."\n");

            $manifest = $this->buildManifest($run, $gaps);
            File::put($rootDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            File::put($rootDir.'/README.txt', $this->buildReadme($run, $manifest, $gaps));

            $zipRelative = 'takeouts/'.$run->download_token.'.zip';
            $zipAbsolute = storage_path('app/private/'.$zipRelative);
            File::ensureDirectoryExists(dirname($zipAbsolute));

            $this->zipDirectory($rootDir, $zipAbsolute);

            return [
                'storage_path' => $zipRelative,
                'file_size_bytes' => (int) filesize($zipAbsolute),
                'manifest' => $manifest,
            ];
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<string>  $gaps
     * @return array<string, mixed>
     */
    private function buildManifest(TakeoutRun $run, array $gaps): array
    {
        $team = $this->collector->team($run);
        $invoices = $this->collector->invoices($run);
        $expenses = $this->collector->expenses($run);

        return [
            'version' => 1,
            'team_id' => $team->id,
            'team_name' => $team->name,
            'from_date' => $run->from_date->toDateString(),
            'to_date' => $run->to_date->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'invoices' => $invoices->count(),
                'expenses' => $expenses->count(),
                'expense_receipts' => $expenses->filter(fn ($t) => (int) $t->media_count > 0)->count(),
                'bank_statement_files' => $this->collector->bankStatementImports($run)->count(),
                'contracts' => $this->collector->contracts($run)->count(),
            ],
            'warnings' => $gaps,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $gaps
     */
    private function buildReadme(TakeoutRun $run, array $manifest, array $gaps): string
    {
        $teamName = (string) ($manifest['team_name'] ?? '');
        $counts = $manifest['counts'] ?? [];

        $lines = [
            'nrth data takeout',
            '=================',
            '',
            'Company: '.$teamName,
            'Period: '.$run->from_date->toDateString().' to '.$run->to_date->toDateString(),
            'Generated: '.now()->toDateTimeString(),
            '',
            'Date conventions:',
            '- Invoices: issue_date',
            '- Expenses: transaction_date (posted only)',
            '- Bank transactions: transaction_date',
            '- Income statement: posted journal entries in period',
            '- Trial balance: as at end date ('.$run->to_date->toDateString().')',
            '',
            'Counts:',
            '- Invoices: '.($counts['invoices'] ?? 0),
            '- Expenses: '.($counts['expenses'] ?? 0),
            '- Expense receipts: '.($counts['expense_receipts'] ?? 0),
            '- Bank statement files: '.($counts['bank_statement_files'] ?? 0),
            '- Contracts: '.($counts['contracts'] ?? 0),
            '',
        ];

        if ($gaps !== []) {
            $lines[] = 'Warnings: see gaps.txt';
        } else {
            $lines[] = 'Warnings: none';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create takeout zip.');
        }

        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $baseLen = strlen(dirname($sourceDir)) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $localName = substr($path, $baseLen);
            $localName = str_replace(DIRECTORY_SEPARATOR, '/', $localName);

            if ($file->isDir()) {
                $zip->addEmptyDir($localName);
            } else {
                $zip->addFile($path, $localName);
            }
        }

        $zip->close();
    }
}
