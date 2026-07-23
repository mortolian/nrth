<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared OpenAI Chat Completions client used by OpenAI, OpenRouter, Ollama, and other compatible APIs.
 */
final class OpenAiCompatibleClient
{
    /**
     * @param  UploadedFile|list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function extractStructuredJson(
        UploadedFile|array $files,
        string $apiKey,
        string $model,
        string $prompt,
        string $baseUrl,
        string $providerKey,
        bool $preferOpenAiPdfFilePart = false,
        bool $useJsonResponseFormat = true,
    ): array {
        $fileList = $this->normalizeFiles($files);

        $userContent = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];

        foreach ($fileList as $file) {
            $userContent[] = $this->contentPartForFile($file, $preferOpenAiPdfFilePart);
        }

        $endpoint = rtrim($baseUrl, '/').'/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You extract structured data from documents. Reply with valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
        ];

        if ($useJsonResponseFormat) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $request = $this->http($apiKey, $providerKey);
            $response = $request->post($endpoint, $payload);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'receipt' => __('Could not reach the AI service. Try again later.'),
            ]);
        }

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? __('AI request failed. Check the endpoint, model, and API key, then try again.');

            throw ValidationException::withMessages([
                'receipt' => is_string($message) ? $message : __('AI request failed.'),
            ]);
        }

        $content = $response->json('choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)->map(function ($part) {
                if (is_string($part)) {
                    return $part;
                }
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    return $part['text'];
                }

                return '';
            })->implode('');
        }

        if (! is_string($content) || trim($content) === '') {
            throw ValidationException::withMessages([
                'receipt' => __('Could not read fields from this file. Try a clearer photo or PDF.'),
            ]);
        }

        return $this->decodeJsonObject($content);
    }

    /**
     * @param  UploadedFile|list<UploadedFile>  $files
     * @return list<UploadedFile>
     */
    private function normalizeFiles(UploadedFile|array $files): array
    {
        $list = is_array($files) ? array_values(array_filter($files)) : [$files];
        if ($list === []) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload an image or PDF to scan.'),
            ]);
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function contentPartForFile(UploadedFile $file, bool $preferOpenAiPdfFilePart): array
    {
        $mime = (string) ($file->getMimeType() ?: '');
        $isPdf = $mime === 'application/pdf' || str_ends_with(strtolower($file->getClientOriginalName()), '.pdf');
        $isImage = str_starts_with($mime, 'image/');

        if (! $isPdf && ! $isImage) {
            throw ValidationException::withMessages([
                'receipt' => __('Upload an image or PDF to scan.'),
            ]);
        }

        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));
        $dataUrl = ($isPdf ? 'data:application/pdf;base64,' : 'data:'.$mime.';base64,').$base64;

        if ($isPdf && $preferOpenAiPdfFilePart) {
            return [
                'type' => 'file',
                'file' => [
                    'filename' => $file->getClientOriginalName() ?: 'document.pdf',
                    'file_data' => $dataUrl,
                ],
            ];
        }

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $dataUrl,
            ],
        ];
    }

    private function http(string $apiKey, string $providerKey): PendingRequest
    {
        $request = Http::acceptJson()->timeout(120);

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        if ($providerKey === AiCatalog::PROVIDER_OPENROUTER) {
            $request = $request->withHeaders([
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => (string) config('app.name', 'nrth'),
            ]);
        }

        return $request;
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
