<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Ai\AiProviderRegistry;
use App\Domain\Expenses\DTOs\ParsedExpenseReceipt;
use App\Models\Team;
use Carbon\Carbon;
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
        return $this->parseMany([$file], $team);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function parseMany(array $files, Team $team): ParsedExpenseReceipt
    {
        if (! $this->enabledFor($team)) {
            throw ValidationException::withMessages([
                'receipt' => __('AI is not configured. Add an API key in Business settings → AI.'),
            ]);
        }

        $files = array_values(array_filter($files));
        if ($files === []) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload an image or PDF to scan.'),
            ]);
        }

        $providerKey = $team->aiProvider();
        $apiKey = $team->aiApiKey();
        $model = $team->aiModel();

        try {
            $provider = $this->providers->get($providerKey);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'receipt' => __('AI provider is not supported. Choose a provider in Business settings → AI.'),
            ]);
        }

        $prompt = count($files) > 1
            ? $this->mergeExtractionPrompt()
            : $this->extractionPrompt();

        $decoded = $provider->extractStructuredJson(
            $files,
            $apiKey,
            $model,
            $prompt,
            $team->aiBaseUrl() !== '' ? $team->aiBaseUrl() : null,
        );

        return $this->mapToDto($decoded, (int) $team->id);
    }

    private function extractionPrompt(): string
    {
        return 'Extract fields from this South African tax invoice / receipt. '
            .'Return JSON only with keys: date (YYYY-MM-DD or null), supplier_name, description, '
            .'amount_incl_vat (number in ZAR — TOTAL / Amount due / Grand total the customer paid, VAT included), '
            .'amount_excl_vat (number in ZAR — subtotal before VAT only; never the paid total), '
            .'vat_amount (number in ZAR), '
            .'vat_rate (one of vat15, vat0, exempt, no_vat), reference (invoice/receipt number), '
            .'confidence (0 to 1). Use null when unknown. Prefer vat15 when 15% VAT is shown. '
            .'If the receipt only shows one money total (often labelled TOTAL), put it in amount_incl_vat '
            .'and leave amount_excl_vat null — do not copy the paid total into amount_excl_vat.';
    }

    private function mergeExtractionPrompt(): string
    {
        return 'These documents may be pages or parts of one South African tax invoice / receipt. '
            .'Combine them into a single expense. Return JSON only with keys: date (YYYY-MM-DD or null), '
            .'supplier_name, description, '
            .'amount_incl_vat (number in ZAR — TOTAL / Amount due / Grand total paid, VAT included), '
            .'amount_excl_vat (number in ZAR — subtotal before VAT for the whole expense; never the paid total), '
            .'vat_amount (number in ZAR), vat_rate (one of vat15, vat0, exempt, no_vat), '
            .'reference (invoice/receipt number), confidence (0 to 1). '
            .'Do not sum duplicate totals that appear on multiple pages. Use null when unknown. '
            .'Prefer vat15 when 15% VAT is shown. '
            .'If only one money total is shown, put it in amount_incl_vat and leave amount_excl_vat null.';
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

        [$amountExclVat, $vatAmount, $vatRate] = $this->normalizeAmounts(
            $this->nullableFloat($decoded['amount_excl_vat'] ?? null),
            $this->nullableFloat($decoded['vat_amount'] ?? null),
            $this->nullableFloat($decoded['amount_incl_vat'] ?? null),
            $vatRate,
        );

        return new ParsedExpenseReceipt(
            date: $this->normalizeDate($decoded['date'] ?? null),
            supplierName: $supplierName,
            supplierId: $supplierId,
            description: $this->nullableString($decoded['description'] ?? null),
            amountExclVat: $amountExclVat,
            vatAmount: $vatAmount,
            vatRate: $vatRate,
            reference: $this->nullableString($decoded['reference'] ?? null),
            confidence: $confidence,
        );
    }

    /**
     * Prefer the paid/gross total when present; derive excl + VAT so scan does not double-count VAT.
     *
     * @return array{0: float|null, 1: float|null, 2: string|null}
     */
    private function normalizeAmounts(
        ?float $amountExclVat,
        ?float $vatAmount,
        ?float $amountInclVat,
        ?string $vatRate,
    ): array {
        $rate = match ($vatRate) {
            'vat15' => 0.15,
            default => 0.0,
        };

        if ($amountInclVat !== null && $amountInclVat > 0) {
            if ($vatRate === null && $rate === 0.0) {
                $vatRate = 'vat15';
                $rate = 0.15;
            }

            if ($rate > 0) {
                return [...$this->splitInclusiveTotal($amountInclVat, $rate), $vatRate];
            }

            return [$amountInclVat, 0.0, $vatRate ?? 'no_vat'];
        }

        // Model sometimes puts the paid total in amount_excl_vat and the embedded VAT in vat_amount.
        if ($amountExclVat !== null && $vatAmount !== null && $rate > 0) {
            $asExclExpected = $amountExclVat * $rate;
            $asInclExpected = $amountExclVat * $rate / (1 + $rate);
            $errAsExcl = abs($vatAmount - $asExclExpected);
            $errAsIncl = abs($vatAmount - $asInclExpected);

            if ($errAsIncl + 0.02 < $errAsExcl && $errAsIncl <= 0.06) {
                return [...$this->splitInclusiveTotal($amountExclVat, $rate), $vatRate];
            }
        }

        if ($amountExclVat !== null && $vatAmount === null && $rate > 0) {
            $exclCents = (int) round($amountExclVat * 100);
            $vatCents = (int) round($exclCents * $rate);

            return [$amountExclVat, $vatCents / 100, $vatRate];
        }

        return [$amountExclVat, $vatAmount, $vatRate];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function splitInclusiveTotal(float $amountInclVat, float $rate): array
    {
        $inclCents = (int) round($amountInclVat * 100);
        $exclCents = (int) round($inclCents / (1 + $rate));
        $vatCents = $inclCents - $exclCents;

        return [$exclCents / 100, $vatCents / 100];
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
            return Carbon::parse($raw)->toDateString();
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
