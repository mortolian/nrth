<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\DTOs\ParsedBankStatementDTO;
use App\Domain\Banking\DTOs\ParsedTransactionDTO;
use App\Domain\Banking\Enums\ImportStatus;
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
