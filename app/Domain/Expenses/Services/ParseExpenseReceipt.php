<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Ai\AiProviderRegistry;
use App\Domain\Expenses\DTOs\ParsedExpenseReceipt;
use App\Models\Team;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class ParseExpenseReceipt
{
    private const ALLOWED_VAT_RATES = ['vat15', 'vat0', 'exempt', 'no_vat'];

    public function __construct(
        private readonly AiProviderRegistry $providers,
    ) {}

    public function enabledFor(?Team $team): bool
    {
        return $team !== null && $team->aiEnabled();
    }

    public function parse(UploadedFile $file, Team $team): ParsedExpenseReceipt
    {
        if (! $this->enabledFor($team)) {
            throw ValidationException::withMessages([
                'receipt' => __('AI is not configured. Add an API key in Company settings → AI.'),
            ]);
        }

        $providerKey = $team->aiProvider();
        $apiKey = $team->aiApiKey();
        $model = $team->aiModel();

        try {
            $provider = $this->providers->get($providerKey);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'receipt' => __('AI provider is not supported. Choose a provider in Company settings → AI.'),
            ]);
        }

        $decoded = $provider->extractStructuredJson(
            $file,
            $apiKey,
            $model,
            $this->extractionPrompt(),
            $team->aiBaseUrl() !== '' ? $team->aiBaseUrl() : null,
        );

        return $this->mapToDto($decoded, (int) $team->id);
    }

    private function extractionPrompt(): string
    {
        return 'Extract fields from this South African tax invoice / receipt. '
            .'Return JSON only with keys: date (YYYY-MM-DD or null), supplier_name, description, '
            .'amount_excl_vat (number in ZAR, excl VAT), vat_amount (number in ZAR), '
            .'vat_rate (one of vat15, vat0, exempt, no_vat), reference (invoice/receipt number), '
            .'confidence (0 to 1). Use null when unknown. Prefer vat15 when 15% VAT is shown.';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function mapToDto(array $decoded, int $teamId): ParsedExpenseReceipt
    {
        $supplierName = $this->nullableString($decoded['supplier_name'] ?? null);
        $supplierId = $supplierName !== null ? $this->matchSupplierId($teamId, $supplierName) : null;

        $vatRate = $this->nullableString($decoded['vat_rate'] ?? null);
        if ($vatRate !== null && ! in_array($vatRate, self::ALLOWED_VAT_RATES, true)) {
            $vatRate = null;
        }

        $confidence = $decoded['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0;

        return new ParsedExpenseReceipt(
            date: $this->normalizeDate($decoded['date'] ?? null),
            supplierName: $supplierName,
            supplierId: $supplierId,
            description: $this->nullableString($decoded['description'] ?? null),
            amountExclVat: $this->nullableFloat($decoded['amount_excl_vat'] ?? null),
            vatAmount: $this->nullableFloat($decoded['vat_amount'] ?? null),
            vatRate: $vatRate,
            reference: $this->nullableString($decoded['reference'] ?? null),
            confidence: $confidence,
        );
    }

    private function matchSupplierId(int $teamId, string $name): ?int
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return null;
        }

        $suppliers = Supplier::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['id', 'name']);

        $exact = $suppliers->first(
            fn (Supplier $supplier) => mb_strtolower(trim($supplier->name)) === $needle
        );
        if ($exact !== null) {
            return (int) $exact->id;
        }

        $contains = $suppliers->first(
            fn (Supplier $supplier) => str_contains(mb_strtolower(trim($supplier->name)), $needle)
                || str_contains($needle, mb_strtolower(trim($supplier->name)))
        );

        return $contains !== null ? (int) $contains->id : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
