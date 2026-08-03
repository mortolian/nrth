<?php

namespace App\Domain\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AnthropicProvider implements AiProvider
{
    public function key(): string
    {
        return AiCatalog::PROVIDER_ANTHROPIC;
    }

    /**
     * @param  UploadedFile|list<UploadedFile>  $files
     */
    public function extractStructuredJson(
        UploadedFile|array $files,
        string $apiKey,
        string $model,
        string $prompt,
        ?string $baseUrl = null,
    ): array {
        $fileList = is_array($files) ? array_values(array_filter($files)) : [$files];
        if ($fileList === []) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload an image or PDF to scan.'),
            ]);
        }

        $content = [];
        foreach ($fileList as $file) {
            $content[] = $this->mediaBlockForFile($file);
        }
        $content[] = [
            'type' => 'text',
            'text' => $prompt.' Reply with valid JSON only, no markdown.',
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->acceptJson()
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 1024,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $content,
                        ],
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

        $text = $this->firstTextBlock($response->json('content'));
        if ($text === null) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read fields from this file. Try a clearer photo or PDF.'),
            ]);
        }

        return $this->decodeJsonObject($text);
    }

    public function completeStructuredJson(
        string $prompt,
        string $apiKey,
        string $model,
        ?string $baseUrl = null,
    ): array {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->acceptJson()
                ->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 8192,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt.' Reply with valid JSON only, no markdown.',
                        ],
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

        $text = $this->firstTextBlock($response->json('content'));
        if ($text === null) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read fields from this file. Try a clearer export or PDF.'),
            ]);
        }

        return $this->decodeJsonObject($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaBlockForFile(UploadedFile $file): array
    {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $isPdf = $mime === 'application/pdf' || str_ends_with(strtolower($file->getClientOriginalName()), '.pdf');
        $isImage = str_starts_with($mime, 'image/');

        if (! $isPdf && ! $isImage) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload an image or PDF to scan.'),
            ]);
        }

        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        if ($isPdf) {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $base64,
                ],
            ];
        }

        $mediaType = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $mime
            : 'image/jpeg';

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64,
            ],
        ];
    }

    private function firstTextBlock(mixed $content): ?string
    {
        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text = trim((string) $block['text']);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
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
