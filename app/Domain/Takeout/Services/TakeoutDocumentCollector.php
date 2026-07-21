<?php

namespace App\Domain\Takeout\Services;

use App\Domain\Contracting\Models\Contract;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Domain\Takeout\DTOs\TakeoutDocumentExportResult;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Support\TakeoutFilename;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class TakeoutDocumentCollector
{
    public function __construct(
        private readonly TakeoutDataCollector $collector,
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function export(string $documentsDirectory, TakeoutRun $run): TakeoutDocumentExportResult
    {
        File::ensureDirectoryExists($documentsDirectory.'/invoices');
        File::ensureDirectoryExists($documentsDirectory.'/expense-receipts');
        File::ensureDirectoryExists($documentsDirectory.'/bank-statements');
        File::ensureDirectoryExists($documentsDirectory.'/contracts');

        $result = new TakeoutDocumentExportResult;

        foreach ($this->collector->invoices($run) as $invoice) {
            $filename = $this->exportInvoicePdf($documentsDirectory.'/invoices', $invoice, $result);
            if ($filename !== null) {
                $result->invoicePdfFilenames[$invoice->id] = $filename;
            }
        }

        foreach ($this->collector->expenses($run) as $expense) {
            $filename = $this->exportExpenseReceipts($documentsDirectory.'/expense-receipts', $expense, $result);
            if ($filename !== null) {
                $result->expenseReceiptFilenames[$expense->id] = $filename;
            }
        }

        foreach ($this->collector->bankStatementImports($run) as $import) {
            $this->exportBankStatement($documentsDirectory.'/bank-statements', $import, $result);
        }

        foreach ($this->collector->contracts($run) as $contract) {
            $filename = $this->exportSignedContract($documentsDirectory.'/contracts', $contract, $result);
            if ($filename !== null) {
                $result->contractSignedFilenames[$contract->id] = $filename;
            }
        }

        return $result;
    }

    private function exportInvoicePdf(string $directory, Invoice $invoice, TakeoutDocumentExportResult $result): ?string
    {
        $clientName = TakeoutFilename::sanitize($invoice->client?->name ?? 'client');
        $number = TakeoutFilename::sanitize($invoice->number);
        $targetName = sprintf(
            '%s_%s_%s.pdf',
            $invoice->issue_date?->format('Y-m-d') ?? 'unknown-date',
            $number,
            $clientName,
        );

        $media = $invoice->getFirstMedia('invoice-pdfs');
        if ($media !== null) {
            return $this->copyMediaFile($directory, $media, $targetName, $result);
        }

        if ($invoice->status === InvoiceStatus::Void) {
            return null;
        }

        try {
            $tmpPath = $this->invoicePdfService->renderToTemporaryPath($invoice);
        } catch (\Throwable $e) {
            Log::warning('Takeout: invoice PDF generation failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            $result->warnings[] = sprintf('Invoice %s — PDF could not be generated', $invoice->number);

            return null;
        }

        try {
            $dest = $this->uniquePath($directory, $targetName);
            File::copy($tmpPath, $dest);

            return basename($dest);
        } finally {
            File::delete($tmpPath);
        }
    }

    private function exportExpenseReceipts(
        string $directory,
        \App\Domain\Accounting\Models\Transaction $expense,
        TakeoutDocumentExportResult $result,
    ): ?string {
        $mediaItems = $expense->getMedia('attachments');
        if ($mediaItems->isEmpty()) {
            return null;
        }

        $totalCents = $this->collector->expenseTotalCents($expense);
        $label = TakeoutFilename::sanitize($expense->supplier?->name ?? $expense->description ?? 'expense');
        $amountLabel = 'R'.number_format($totalCents / 100, 2, '.', '');

        $exported = [];
        foreach ($mediaItems as $index => $media) {
            $extension = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'bin');
            if (! preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
                $extension = 'bin';
            }
            $suffix = $mediaItems->count() > 1 ? '_'.($index + 1) : '';
            $targetName = sprintf(
                '%s_%s_%s%s.%s',
                $expense->transaction_date?->format('Y-m-d') ?? 'unknown-date',
                $label,
                $amountLabel,
                $suffix,
                $extension,
            );

            $copied = $this->copyMediaFile($directory, $media, $targetName, $result);
            if ($copied !== null) {
                $exported[] = $copied;
            }
        }

        if ($exported === []) {
            return null;
        }

        return implode(', ', $exported);
    }

    private function exportBankStatement(
        string $directory,
        \App\Domain\Banking\Models\BankingStatementImport $import,
        TakeoutDocumentExportResult $result,
    ): ?string {
        $accountName = TakeoutFilename::sanitize($import->account?->name ?? 'account');
        $created = $import->created_at ?? now();
        $original = TakeoutFilename::sanitize($import->original_filename, 120);
        $targetName = sprintf(
            '%s-%s_%s_%s',
            $created->format('Y'),
            $created->format('m'),
            $accountName,
            $original,
        );

        $disk = Storage::disk('local');
        if (! $disk->exists($import->stored_path)) {
            $result->warnings[] = sprintf(
                'Bank statement "%s" — file missing on disk',
                $import->original_filename,
            );

            return null;
        }

        $dest = $this->uniquePath($directory, $targetName);
        File::copy($disk->path($import->stored_path), $dest);

        return basename($dest);
    }

    private function exportSignedContract(string $directory, Contract $contract, TakeoutDocumentExportResult $result): ?string
    {
        $media = $contract->getFirstMedia('signed-contract');
        if ($media === null) {
            return null;
        }

        $targetName = sprintf(
            '%s_%s_%s.pdf',
            $contract->start_date?->format('Y-m-d') ?? 'unknown-date',
            TakeoutFilename::sanitize($contract->client?->name ?? 'client'),
            TakeoutFilename::sanitize($contract->title),
        );

        return $this->copyMediaFile($directory, $media, $targetName, $result);
    }

    private function copyMediaFile(
        string $directory,
        Media $media,
        string $targetName,
        TakeoutDocumentExportResult $result,
    ): ?string {
        $disk = Storage::disk($media->disk);
        $relative = $media->getPathRelativeToRoot();

        if (! $disk->exists($relative)) {
            $result->warnings[] = sprintf('File missing on disk: %s', $media->file_name);
            Log::warning('Takeout: media file missing', [
                'media_id' => $media->id,
                'disk' => $media->disk,
                'path' => $relative,
            ]);

            return null;
        }

        $dest = $this->uniquePath($directory, $targetName);
        $source = $disk->path($relative);
        File::copy($source, $dest);

        return basename($dest);
    }

    private function uniquePath(string $directory, string $filename): string
    {
        $path = $directory.'/'.$filename;
        if (! File::exists($path)) {
            return $path;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $counter = 2;

        do {
            $candidate = $extension !== ''
                ? $basename.'-'.$counter.'.'.$extension
                : $basename.'-'.$counter;
            $path = $directory.'/'.$candidate;
            $counter++;
        } while (File::exists($path));

        return $path;
    }
}
