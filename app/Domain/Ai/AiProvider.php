<?php

namespace App\Domain\Ai;

use Illuminate\Http\UploadedFile;

interface AiProvider
{
    public function key(): string;

    /**
     * Send an image or PDF with a prompt and return decoded JSON.
     *
     * @return array<string, mixed>
     */
    public function extractStructuredJson(
        UploadedFile $file,
        string $apiKey,
        string $model,
        string $prompt,
        ?string $baseUrl = null,
    ): array;
}
