<?php

namespace App\Domain\Ai;

use Illuminate\Http\UploadedFile;

interface AiProvider
{
    public function key(): string;

    /**
     * Send one or more images/PDFs with a prompt and return decoded JSON.
     *
     * @param  UploadedFile|list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function extractStructuredJson(
        UploadedFile|array $files,
        string $apiKey,
        string $model,
        string $prompt,
        ?string $baseUrl = null,
    ): array;

    /**
     * Send a text-only prompt and return decoded JSON (for CSV/XLSX and other non-image inputs).
     *
     * @return array<string, mixed>
     */
    public function completeStructuredJson(
        string $prompt,
        string $apiKey,
        string $model,
        ?string $baseUrl = null,
    ): array;
}
