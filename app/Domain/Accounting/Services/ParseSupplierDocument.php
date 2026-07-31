<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTOs\ParsedSupplierDocument;
use App\Domain\Ai\AiProviderRegistry;
use App\Models\Team;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class ParseSupplierDocument
{
    public function __construct(
        private readonly AiProviderRegistry $providers,
    ) {}

    public function enabledFor(?Team $team): bool
    {
        return $team !== null && $team->aiEnabled();
    }

    public function parse(UploadedFile $file, Team $team): ParsedSupplierDocument
    {
        if (! $this->enabledFor($team)) {
            throw ValidationException::withMessages([
                'document' => __('AI is not configured. Add an API key in Business settings → AI.'),
            ]);
        }

        $providerKey = $team->aiProvider();
        $apiKey = $team->aiApiKey();
        $model = $team->aiModel();

        try {
            $provider = $this->providers->get($providerKey);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'document' => __('AI provider is not supported. Choose a provider in Business settings → AI.'),
            ]);
        }

        $decoded = $provider->extractStructuredJson(
            $file,
            $apiKey,
            $model,
            $this->extractionPrompt(),
            $team->aiBaseUrl() !== '' ? $team->aiBaseUrl() : null,
        );

        return $this->mapToDto($decoded);
    }

    private function extractionPrompt(): string
    {
        return 'Extract the supplier / vendor / company details from this South African tax invoice, '
            .'letterhead, statement, or business document. Return JSON only with keys: '
            .'name (legal or trading name of the supplier/vendor who issued the document — not the customer), '
            .'contact_name (person to contact, or null), '
            .'email, phone (as printed), '
            .'vat_number (SA VAT number if shown; digits only preferred, often starts with 4), '
            .'registration_number (company/CIPC registration if shown), '
            .'address (object with street, city, province, postal_code, country — use null for unknown parts), '
            .'notes (short useful extras, or null), '
            .'confidence (0 to 1). Use null when unknown. '
            .'Prefer the issuer/supplier details over the bill-to/customer details.';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function mapToDto(array $decoded): ParsedSupplierDocument
    {
        $confidence = $decoded['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0;

        $addressRaw = $decoded['address'] ?? null;
        $address = null;
        if (is_array($addressRaw)) {
            $mapped = [
                'street' => $this->nullableString($addressRaw['street'] ?? null),
                'city' => $this->nullableString($addressRaw['city'] ?? null),
                'province' => $this->nullableString($addressRaw['province'] ?? null),
                'postal_code' => $this->nullableString($addressRaw['postal_code'] ?? null),
                'country' => $this->nullableString($addressRaw['country'] ?? null),
            ];
            if (array_filter($mapped, fn ($value) => $value !== null) !== []) {
                if ($mapped['country'] === null) {
                    $mapped['country'] = 'South Africa';
                }
                $address = $mapped;
            }
        }

        return new ParsedSupplierDocument(
            name: $this->nullableString($decoded['name'] ?? null),
            contactName: $this->nullableString($decoded['contact_name'] ?? null),
            email: $this->nullableString($decoded['email'] ?? null),
            phone: $this->nullableString($decoded['phone'] ?? null),
            vatNumber: $this->normalizeVatNumber($decoded['vat_number'] ?? null),
            registrationNumber: $this->nullableString($decoded['registration_number'] ?? null),
            address: $address,
            notes: $this->nullableString($decoded['notes'] ?? null),
            confidence: $confidence,
        );
    }

    private function normalizeVatNumber(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (preg_match('/^4\d{9}$/', $digits) === 1) {
            return $digits;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
