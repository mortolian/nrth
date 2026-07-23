<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\DTOs\ParsedBankStatementDTO;
use App\Domain\Banking\DTOs\ParsedTransactionDTO;
use App\Domain\Banking\Enums\ImportStatus;
use App\Domain\Banking\Importers\CsvBankStatementImporter;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Banking\Models\BankingTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class BankingStatementImportService
{
    public function __construct(
        private readonly BankingStatementImporterRegistry $registry,
        private readonly BankingDuplicateDetector $duplicateDetector,
        private readonly CsvBankStatementImporter $csvImporter,
    ) {}

    public function storeUpload(
        int $teamId,
        BankingAccount $account,
        UploadedFile $file,
    ): BankingStatementImport {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = (string) $file->getMimeType();
        $importer = $this->registry->resolve($mimeType, $extension);
        unset($importer);

        $hash = hash_file('sha256', $file->getRealPath() ?: $file->path());
        if ($hash === false) {
            throw new \RuntimeException('Unable to hash uploaded file.');
        }

        $existing = BankingStatementImport::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('account_id', $account->id)
            ->where('file_hash', $hash)
            ->where('status', ImportStatus::Imported)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'file' => __('This exact file has already been imported for this account.'),
            ]);
        }

        $now = now();
        $storedPath = sprintf(
            'banking/%d/%d/%02d/%s',
            $account->id,
            $now->year,
            $now->month,
            $file->hashName()
        );

        Storage::disk('local')->putFileAs(
            dirname($storedPath),
            $file,
            basename($storedPath)
        );

        return BankingStatementImport::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            'account_id' => $account->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_type' => $extension,
            'mime_type' => $mimeType,
            'file_hash' => $hash,
            'status' => ImportStatus::Pending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function parseImport(BankingStatementImport $import, array $options = []): ParsedBankStatementDTO
    {
        $path = $this->absolutePath($import);
        $importer = $this->registry->resolve(
            (string) $import->mime_type,
            (string) $import->file_type
        );

        $parsed = $importer->parse($path, $options);

        $import->update([
            'status' => ImportStatus::Parsed,
            'total_rows' => count($parsed->transactions),
            'metadata' => array_merge($import->metadata ?? [], [
                'parsed' => $parsed->metadata,
                'preview' => $this->previewPayload($parsed),
            ]),
        ]);

        return $parsed;
    }

    /**
     * @return array{
     *     total: int,
     *     new: int,
     *     duplicates: int,
     *     errors: int,
     *     preview: list<array<string, mixed>>
     * }
     */
    public function summarize(BankingStatementImport $import, ParsedBankStatementDTO $parsed): array
    {
        $accountId = (int) $import->account_id;
        $keys = [];
        $preview = [];

        foreach ($parsed->transactions as $transaction) {
            $key = $this->duplicateDetector->duplicateKey(
                $accountId,
                $transaction->transactionDate,
                $transaction->amount,
                $transaction->description,
                $transaction->reference
            );
            $keys[] = $key;
            $preview[] = $this->transactionPreview($transaction, $key);
        }

        $existing = $this->duplicateDetector->existingKeysForAccount($accountId, $keys);
        $duplicates = 0;
        foreach ($keys as $key) {
            if (isset($existing[$key])) {
                $duplicates++;
            }
        }

        $parseErrors = $parsed->metadata['parse_errors'] ?? [];

        return [
            'total' => count($parsed->transactions),
            'new' => count($parsed->transactions) - $duplicates,
            'duplicates' => $duplicates,
            'errors' => is_countable($parseErrors) ? count($parseErrors) : 0,
            'preview' => array_slice($preview, 0, 50),
        ];
    }

    public function confirmImport(BankingStatementImport $import, ParsedBankStatementDTO $parsed): BankingStatementImport
    {
        return DB::transaction(function () use ($import, $parsed): BankingStatementImport {
            $account = BankingAccount::queryWithoutTeamScope()->findOrFail($import->account_id);
            $currency = $account->currency;
            $imported = 0;
            $duplicates = 0;
            $failed = 0;

            $keys = array_map(
                fn (ParsedTransactionDTO $t) => $this->duplicateDetector->duplicateKey(
                    (int) $import->account_id,
                    $t->transactionDate,
                    $t->amount,
                    $t->description,
                    $t->reference
                ),
                $parsed->transactions
            );

            $existing = $this->duplicateDetector->existingKeysForAccount((int) $import->account_id, $keys);

            foreach ($parsed->transactions as $transaction) {
                $duplicateKey = $this->duplicateDetector->duplicateKey(
                    (int) $import->account_id,
                    $transaction->transactionDate,
                    $transaction->amount,
                    $transaction->description,
                    $transaction->reference
                );

                if (isset($existing[$duplicateKey])) {
                    $duplicates++;

                    continue;
                }

                try {
                    BankingTransaction::queryWithoutTeamScope()->create([
                        'team_id' => $import->team_id,
                        'account_id' => $import->account_id,
                        'banking_statement_import_id' => $import->id,
                        'transaction_date' => $transaction->transactionDate,
                        'value_date' => $transaction->valueDate,
                        'description' => $transaction->description,
                        'reference' => $transaction->reference,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency ?? $currency,
                        'direction' => $transaction->direction,
                        'running_balance' => $transaction->runningBalance,
                        'source_hash' => $this->duplicateDetector->sourceHash($transaction),
                        'duplicate_key' => $duplicateKey,
                        'metadata' => $transaction->metadata,
                    ]);
                    $existing[$duplicateKey] = true;
                    $imported++;
                } catch (\Throwable) {
                    $failed++;
                }
            }

            $import->update([
                'status' => ImportStatus::Imported,
                'imported_rows' => $imported,
                'duplicate_rows' => $duplicates,
                'failed_rows' => $failed,
                'total_rows' => count($parsed->transactions),
            ]);

            return $import->fresh();
        });
    }

    public function absolutePath(BankingStatementImport $import): string
    {
        return Storage::disk('local')->path($import->stored_path);
    }

    /**
     * @param  list<string>  $headers
     */
    public function profileMatches(BankingAccount $account, array $headers, string $delimiter): bool
    {
        $profile = $account->csv_mapping_profile;
        if (! is_array($profile)) {
            return false;
        }

        $profileHeaders = $profile['headers'] ?? null;
        $profileDelimiter = $profile['delimiter'] ?? null;
        $mapping = $profile['mapping'] ?? null;

        if (! is_array($profileHeaders) || ! is_array($mapping) || ! is_string($profileDelimiter)) {
            return false;
        }

        if ($profileDelimiter !== $delimiter) {
            return false;
        }

        $normalizedHeaders = array_map(
            static fn (mixed $h): string => trim((string) $h),
            $headers
        );
        $normalizedProfileHeaders = array_map(
            static fn (mixed $h): string => trim((string) $h),
            $profileHeaders
        );

        if ($normalizedHeaders !== $normalizedProfileHeaders) {
            return false;
        }

        foreach ($mapping as $column) {
            if ($column === null || $column === '') {
                continue;
            }
            if (! in_array(trim((string) $column), $normalizedHeaders, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $mapping
     */
    public function saveCsvMappingProfile(
        BankingAccount $account,
        array $headers,
        string $delimiter,
        array $mapping,
    ): void {
        $account->forceFill([
            'csv_mapping_profile' => [
                'headers' => array_values(array_map(
                    static fn (mixed $h): string => trim((string) $h),
                    $headers
                )),
                'delimiter' => $delimiter,
                'mapping' => $mapping,
            ],
        ])->save();
    }

    public function undoImport(BankingStatementImport $import): BankingStatementImport
    {
        if ($import->status !== ImportStatus::Imported) {
            throw ValidationException::withMessages([
                'import' => __('Only a completed import can be undone.'),
            ]);
        }

        return DB::transaction(function () use ($import): BankingStatementImport {
            BankingTransaction::queryWithoutTeamScope()
                ->where('banking_statement_import_id', $import->id)
                ->delete();

            $activePath = $import->stored_path;
            $softDeletedPath = $this->softDeleteStoredFile($import);

            $import->update([
                'status' => ImportStatus::Undone,
                'stored_path' => $softDeletedPath,
                'imported_rows' => 0,
                'duplicate_rows' => 0,
                'failed_rows' => 0,
                'metadata' => array_merge($import->metadata ?? [], [
                    'undone_at' => now()->toIso8601String(),
                    'active_stored_path' => $activePath,
                    'file_soft_deleted_at' => now()->toIso8601String(),
                ]),
            ]);

            return $import->fresh();
        });
    }

    public function hasStoredFile(BankingStatementImport $import): bool
    {
        $path = $import->stored_path;

        return is_string($path) && $path !== '' && Storage::disk('local')->exists($path);
    }

    /**
     * Restore a soft-deleted statement file and prepare it for confirm (or map if needed).
     *
     * @return array{import: BankingStatementImport, parsed: ParsedBankStatementDTO|null, mapping_from_profile: bool}
     */
    public function reimport(BankingStatementImport $import): array
    {
        if ($import->status !== ImportStatus::Undone) {
            throw ValidationException::withMessages([
                'import' => __('Only an undone import can be re-imported from history.'),
            ]);
        }

        if (! $this->hasStoredFile($import)) {
            throw ValidationException::withMessages([
                'import' => __('The statement file is no longer available for re-import.'),
            ]);
        }

        $restoredPath = $this->restoreStoredFile($import);

        $import->update([
            'stored_path' => $restoredPath,
            'status' => ImportStatus::Pending,
            'metadata' => array_merge($import->metadata ?? [], [
                'reimported_at' => now()->toIso8601String(),
                'file_soft_deleted_at' => null,
            ]),
        ]);
        $import->refresh();

        $options = $this->parseOptionsFromMetadata($import);
        $mappingFromProfile = false;
        $account = BankingAccount::queryWithoutTeamScope()->find($import->account_id);

        if (
            empty($options['mapping'])
            && $account !== null
            && in_array($import->file_type, ['csv', 'txt'], true)
        ) {
            $preview = $this->csvImporter->preview($this->absolutePath($import));
            if ($this->profileMatches($account, $preview['headers'], $preview['delimiter'])) {
                $profile = $account->csv_mapping_profile ?? [];
                $options = [
                    'mapping' => $profile['mapping'] ?? [],
                    'delimiter' => $preview['delimiter'],
                    'headers' => $preview['headers'],
                ];
                $mappingFromProfile = true;
            }
        }

        if (in_array($import->file_type, ['csv', 'txt'], true) && empty($options['mapping'])) {
            return [
                'import' => $import->fresh(),
                'parsed' => null,
                'mapping_from_profile' => false,
            ];
        }

        $parsed = $this->parseImport($import, $options);

        return [
            'import' => $import->fresh(),
            'parsed' => $parsed,
            'mapping_from_profile' => $mappingFromProfile,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parseOptionsFromMetadata(BankingStatementImport $import): array
    {
        $metadata = $import->metadata ?? [];
        $options = [];

        if (isset($metadata['parsed']['mapping'])) {
            $options['mapping'] = $metadata['parsed']['mapping'];
        } elseif (isset($metadata['mapping'])) {
            $options['mapping'] = $metadata['mapping'];
        }
        if (isset($metadata['parsed']['delimiter'])) {
            $options['delimiter'] = $metadata['parsed']['delimiter'];
        }
        if (isset($metadata['parsed']['headers'])) {
            $options['headers'] = $metadata['parsed']['headers'];
        }

        return $options;
    }

    private function softDeleteStoredFile(BankingStatementImport $import): string
    {
        $disk = Storage::disk('local');
        $current = (string) $import->stored_path;

        if ($current === '' || ! $disk->exists($current)) {
            return $current;
        }

        if (str_contains($current, '/deleted/')) {
            return $current;
        }

        $now = now();
        $basename = basename($current);
        $deletedPath = sprintf(
            'banking/%d/deleted/%d/%02d/%s',
            $import->account_id,
            $now->year,
            $now->month,
            $basename
        );

        if ($disk->exists($deletedPath)) {
            $deletedPath = sprintf(
                'banking/%d/deleted/%d/%02d/%s_%s',
                $import->account_id,
                $now->year,
                $now->month,
                $import->id,
                $basename
            );
        }

        $disk->move($current, $deletedPath);

        return $deletedPath;
    }

    private function restoreStoredFile(BankingStatementImport $import): string
    {
        $disk = Storage::disk('local');
        $current = (string) $import->stored_path;

        if ($current === '' || ! $disk->exists($current)) {
            throw ValidationException::withMessages([
                'import' => __('The statement file is no longer available for re-import.'),
            ]);
        }

        if (! str_contains($current, '/deleted/')) {
            return $current;
        }

        $metadata = $import->metadata ?? [];
        $target = $metadata['active_stored_path'] ?? null;

        if (! is_string($target) || $target === '' || str_contains($target, '/deleted/')) {
            $now = now();
            $target = sprintf(
                'banking/%d/%d/%02d/%s',
                $import->account_id,
                $now->year,
                $now->month,
                basename($current)
            );
        }

        if ($disk->exists($target) && $target !== $current) {
            $target = sprintf(
                'banking/%d/%d/%02d/%s_%s',
                $import->account_id,
                now()->year,
                now()->month,
                $import->id,
                basename($current)
            );
        }

        $disk->move($current, $target);

        return $target;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewPayload(ParsedBankStatementDTO $parsed): array
    {
        $preview = [];
        foreach (array_slice($parsed->transactions, 0, 50) as $transaction) {
            $preview[] = $this->transactionPreview($transaction);
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPreview(ParsedTransactionDTO $transaction, ?string $duplicateKey = null): array
    {
        return [
            'transaction_date' => $transaction->transactionDate,
            'value_date' => $transaction->valueDate,
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'direction' => $transaction->direction->value,
            'running_balance' => $transaction->runningBalance,
            'duplicate_key' => $duplicateKey,
        ];
    }
}
