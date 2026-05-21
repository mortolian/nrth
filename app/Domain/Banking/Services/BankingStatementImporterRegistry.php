<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Contracts\BankingStatementImporter;
use Illuminate\Validation\ValidationException;

final class BankingStatementImporterRegistry
{
    /** @var list<BankingStatementImporter> */
    private array $importers;

    public function __construct(BankingStatementImporter ...$importers)
    {
        $this->importers = $importers;
    }

    public function resolve(string $mimeType, string $extension): BankingStatementImporter
    {
        $extension = strtolower(ltrim($extension, '.'));

        foreach ($this->importers as $importer) {
            if ($importer->supports($mimeType, $extension)) {
                return $importer;
            }
        }

        throw ValidationException::withMessages([
            'file' => __('Unsupported file type. Allowed types: CSV, TXT, OFX.'),
        ]);
    }
}
