<?php

namespace App\Domain\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GeminiProvider implements AiProvider
{
    public function key(): string
    {
        return AiCatalog::PROVIDER_GEMINI;
    }

    public function extractStructuredJson(
        UploadedFile $file,
        string $apiKey,
        string $model,
        string $prompt,
        ?string $baseUrl = null,
    ): array {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $isPdf = $mime === 'application/pdf' || str_ends_with(strtolower($file->getClientOriginalName()), '.pdf');
        $isImage = str_starts_with($mime, 'image/');

        if (! $isPdf && ! $isImage) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload an image or PDF to scan.'),
            ]);
        }

        if ($isImage && ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            $mime = 'image/jpeg';
        }

        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
                ->acceptJson()
                ->timeout(60)
                ->post($endpoint, [
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt.' Reply with valid JSON only, no markdown.'],
                            [
                                'inline_data' => [
                                    'mime_type' => $isPdf ? 'application/pdf' : $mime,
                                    'data' => $base64,
                                ],
                            ],
                        ],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not reach the AI service. Try again later.'),
            ]);
        }

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? __('AI request failed. Check the file and API key, then try again.');

            throw ValidationException::withMessages([
                'receipt' => is_string($message) ? $message : __('AI request failed.'),
            ]);
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read fields from this file. Try a clearer photo or PDF.'),
            ]);
        }

        return $this->decodeJsonObject($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $content): array
    {
        $trimmed = trim($content);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read fields from this file. Try a clearer photo or PDF.'),
            ]);
        }

        return $decoded;
    }
}
