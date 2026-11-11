<?php

namespace App\Domain\Backup\Services;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Backup\Exceptions\IncompleteInstanceBackup;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Takeout\Support\TakeoutFilename;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Extra zip roots on top of the raw storage tree Spatie already archives.
 *
 * instance_backup/ is the same bytes the app stores (numbered media folders).
 * takeout_backup/ is a readable copy grouped by business. Restore uses only
 * instance_backup.
 */
final class InstanceBackupSupplement
{
    public function __construct(
        private readonly InstanceBackupStorageCatalog $catalog,
        private readonly InstanceBackupRestoreReadme $restoreReadme,
    ) {}

    /**
     * @return list<InstanceBackupZipEntry>
     */
    public function entries(?string $filename = null): array
    {
        $used = [];
        $entries = [];

        $entries[] = $this->readme(
            'README.md',
            $this->restoreReadme->contents($filename),
            $used,
        );

        $entries[] = $this->readme(
            InstanceBackupReadablePath::INSTANCE_ROOT.'/README.txt',
            "Raw uploaded files, stored the way the app stores them (numbered folders).\n"
            ."Restore copies this folder onto storage. takeout_backup is a readable copy and is not restored.\n",
            $used,
        );

        foreach ($this->catalog->files() as $path) {
            $name = $this->unique(
                InstanceBackupReadablePath::instanceFile($this->catalog->storageRoot(), $path),
                $used,
            );
            $bytes = filesize($path);
            if ($bytes === false) {
                throw new IncompleteInstanceBackup("Cannot read storage file [{$path}] for instance_backup.");
            }
            $entries[] = new InstanceBackupZipEntry($name, $path, null, $bytes);
        }

        $entries[] = $this->readme(
            InstanceBackupReadablePath::TAKEOUT_ROOT.'/README.txt',
            "The same uploaded files with readable names, grouped by business.\n"
            ."This folder is for people opening the zip. Restore does not copy it back onto the app.\n",
            $used,
        );

        array_push($entries, ...$this->readableEntries($used));

        return $entries;
    }

    /**
     * @param  array<string, true>  $used
     * @return list<InstanceBackupZipEntry>
     */
    private function readableEntries(array &$used): array
    {
        $entries = [];
        $teams = Team::query()->pluck('name', 'id');

        $media = Media::query()->orderBy('id')->get();
        $transactions = $this->modelsFor($media, Transaction::class, ['id', 'team_id', 'supplier_id', 'type', 'description', 'transaction_date']);
        $invoices = $this->modelsFor($media, Invoice::class, ['id', 'team_id', 'client_id', 'number', 'issue_date']);
        $payments = $this->modelsFor($media, Payment::class, ['id', 'team_id', 'invoice_id', 'payment_date']);
        $estimates = $this->modelsFor($media, Estimate::class, ['id', 'team_id', 'client_id', 'number', 'issue_date']);

        $paymentInvoiceIds = $payments->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($paymentInvoiceIds !== []) {
            $extraInvoices = Invoice::queryWithoutTeamScope()
                ->whereIn('id', $paymentInvoiceIds)
                ->get(['id', 'team_id', 'client_id', 'number', 'issue_date'])
                ->keyBy('id');
            $invoices = $invoices->union($extraInvoices);
        }

        $supplierNames = Supplier::queryWithoutTeamScope()
            ->whereIn('id', $transactions->pluck('supplier_id')->filter()->all())
            ->pluck('name', 'id');
        $clientNames = Client::queryWithoutTeamScope()
            ->whereIn('id', $invoices->pluck('client_id')->merge($estimates->pluck('client_id'))->filter()->all())
            ->pluck('name', 'id');

        foreach ($media as $file) {
            $entries[] = $this->mediaEntry($file, $teams, $transactions, $invoices, $payments, $estimates, $supplierNames, $clientNames, $used);
        }

        $imports = BankingStatementImport::queryWithoutTeamScope()->orderBy('id')->get();
        $accountNames = BankingAccount::queryWithoutTeamScope()
            ->whereIn('id', $imports->pluck('account_id')->filter()->all())
            ->pluck('name', 'id');

        foreach ($imports as $import) {
            $entries[] = $this->statementEntry($import, $teams, $accountNames, $used);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, string>  $teams
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, Estimate>  $estimates
     * @param  Collection<int, string>  $supplierNames
     * @param  Collection<int, string>  $clientNames
     * @param  array<string, true>  $used
     */
    private function mediaEntry(
        Media $file,
        $teams,
        $transactions,
        $invoices,
        $payments,
        $estimates,
        $supplierNames,
        $clientNames,
        array &$used,
    ): InstanceBackupZipEntry {
        $source = $this->mediaSource($file);
        $teamId = null;
        $folder = 'other/'.TakeoutFilename::sanitize($file->collection_name, 40);
        $filename = TakeoutFilename::sanitize((string) $file->file_name, 80);

        if ($file->model_type === Transaction::class) {
            $transaction = $transactions->get($file->model_id);
            $teamId = $transaction?->team_id;
            $isExpense = $transaction?->type === TransactionType::Expense;
            $folder = $isExpense ? 'expense-receipts' : 'attachments';
            $label = $supplierNames->get($transaction?->supplier_id)
                ?? $transaction?->description
                ?? 'expense';
            $filename = InstanceBackupReadablePath::expenseReceipt(
                $transaction?->transaction_date?->format('Y-m-d') ?? 'unknown-date',
                (string) $label,
                (string) $file->file_name,
            );
        } elseif ($file->model_type === Invoice::class) {
            $invoice = $invoices->get($file->model_id);
            $teamId = $invoice?->team_id;
            $folder = 'invoices';
            $filename = InstanceBackupReadablePath::datedDocument(
                $invoice?->issue_date?->format('Y-m-d') ?? 'unknown-date',
                (string) ($invoice?->number ?? 'invoice'),
                (string) ($clientNames->get($invoice?->client_id) ?? 'client'),
                (string) $file->file_name,
            );
        } elseif ($file->model_type === Payment::class) {
            $payment = $payments->get($file->model_id);
            $invoice = $payment ? $invoices->get($payment->invoice_id) : null;
            $teamId = $payment?->team_id;
            $folder = 'payment-receipts';
            $filename = InstanceBackupReadablePath::datedDocument(
                $payment?->payment_date?->format('Y-m-d') ?? 'unknown-date',
                (string) ($invoice?->number ?? 'payment'),
                (string) ($clientNames->get($invoice?->client_id) ?? 'client'),
                (string) $file->file_name,
            );
        } elseif ($file->model_type === Estimate::class) {
            $estimate = $estimates->get($file->model_id);
            $teamId = $estimate?->team_id;
            $folder = 'estimates';
            $filename = InstanceBackupReadablePath::datedDocument(
                $estimate?->issue_date?->format('Y-m-d') ?? 'unknown-date',
                (string) ($estimate?->number ?? 'estimate'),
                (string) ($clientNames->get($estimate?->client_id) ?? 'client'),
                (string) $file->file_name,
            );
        } elseif ($file->model_type === Team::class) {
            $teamId = $file->model_id;
            $folder = 'logos';
        }

        $teamName = $teamId !== null ? (string) ($teams->get($teamId) ?? 'business') : 'unassigned';
        $teamDir = $teamId !== null
            ? InstanceBackupReadablePath::teamDirectory($teamName, (int) $teamId)
            : 'unassigned';
        $name = $this->unique(
            InstanceBackupReadablePath::TAKEOUT_ROOT.'/'.$teamDir.'/'.$folder.'/'.$filename,
            $used,
        );

        return new InstanceBackupZipEntry($name, $source, null, $this->sizeOf($source));
    }

    /**
     * @param  Collection<int, string>  $teams
     * @param  Collection<int, string>  $accountNames
     * @param  array<string, true>  $used
     */
    private function statementEntry(BankingStatementImport $import, $teams, $accountNames, array &$used): InstanceBackupZipEntry
    {
        $stored = (string) $import->stored_path;
        if ($stored === '' || ! Storage::disk('local')->exists($stored)) {
            throw new IncompleteInstanceBackup('Bank statement "'.$import->original_filename.'" is missing from storage, so the backup would be incomplete.');
        }

        $source = Storage::disk('local')->path($stored);
        $teamId = (int) $import->team_id;
        $teamDir = InstanceBackupReadablePath::teamDirectory((string) ($teams->get($teamId) ?? 'business'), $teamId);
        $created = $import->created_at ?? now();
        $filename = InstanceBackupReadablePath::bankStatement(
            $created->format('Y-m'),
            (string) ($accountNames->get($import->account_id) ?? 'account'),
            (string) $import->original_filename,
        );
        $name = $this->unique(
            InstanceBackupReadablePath::TAKEOUT_ROOT.'/'.$teamDir.'/bank-statements/'.$filename,
            $used,
        );

        return new InstanceBackupZipEntry($name, $source, null, $this->sizeOf($source));
    }

    /**
     * @param  list<string>  $columns
     * @return Collection<int, Model>
     */
    private function modelsFor($media, string $class, array $columns)
    {
        $ids = $media
            ->where('model_type', $class)
            ->pluck('model_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return collect();
        }

        return $class::queryWithoutTeamScope()->whereIn('id', $ids)->get($columns)->keyBy('id');
    }

    private function mediaSource(Media $file): string
    {
        $disk = Storage::disk($file->disk);
        $relative = $file->getPathRelativeToRoot();
        if (! $disk->exists($relative)) {
            throw new IncompleteInstanceBackup('Uploaded file "'.$file->file_name.'" is missing from storage, so the backup would be incomplete.');
        }

        return $disk->path($relative);
    }

    private function sizeOf(string $path): int
    {
        $bytes = filesize($path);
        if ($bytes === false) {
            throw new IncompleteInstanceBackup("Cannot read [{$path}] for the readable backup copy.");
        }

        return $bytes;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function readme(string $name, string $contents, array &$used): InstanceBackupZipEntry
    {
        $name = $this->unique($name, $used);

        return new InstanceBackupZipEntry($name, null, $contents, strlen($contents));
    }

    /**
     * @param  array<string, true>  $used
     */
    private function unique(string $name, array &$used): string
    {
        $name = str_replace('\\', '/', $name);
        if (! isset($used[$name])) {
            $used[$name] = true;

            return $name;
        }

        $directory = dirname($name);
        $filename = basename($name);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $counter = 2;
        do {
            $candidateName = $extension !== ''
                ? $basename.'-'.$counter.'.'.$extension
                : $basename.'-'.$counter;
            $candidate = ($directory === '.' ? '' : $directory.'/').$candidateName;
            $counter++;
        } while (isset($used[$candidate]));

        $used[$candidate] = true;

        return $candidate;
    }
}
