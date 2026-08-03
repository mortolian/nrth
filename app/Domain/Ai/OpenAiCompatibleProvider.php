<?php

namespace App\Domain\Ai;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private readonly string $providerKey,
        private readonly OpenAiCompatibleClient $client,
    ) {
        if (! in_array($providerKey, [
            AiCatalog::PROVIDER_OPENAI,
            AiCatalog::PROVIDER_OPENROUTER,
            AiCatalog::PROVIDER_OPENAI_COMPATIBLE,
        ], true)) {
            throw new InvalidArgumentException("Unsupported OpenAI-compatible provider [{$providerKey}].");
        }
    }

    public function key(): string
    {
        return $this->providerKey;
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
        $resolvedBaseUrl = trim((string) ($baseUrl ?: AiCatalog::defaultBaseUrl($this->providerKey)));
        if ($resolvedBaseUrl === '') {
            throw new InvalidArgumentException('A base URL is required for this AI provider.');
        }

        $preferPdfFilePart = in_array($this->providerKey, [
            AiCatalog::PROVIDER_OPENAI,
            AiCatalog::PROVIDER_OPENROUTER,
        ], true);

        $useJsonResponseFormat = $this->providerKey === AiCatalog::PROVIDER_OPENAI
            || $this->providerKey === AiCatalog::PROVIDER_OPENROUTER;

        return $this->client->extractStructuredJson(
            files: $files,
            apiKey: $apiKey,
            model: $model,
            prompt: $prompt,
            baseUrl: $resolvedBaseUrl,
            providerKey: $this->providerKey,
            preferOpenAiPdfFilePart: $preferPdfFilePart,
            useJsonResponseFormat: $useJsonResponseFormat,
        );
    }

    public function completeStructuredJson(
        string $prompt,
        string $apiKey,
        string $model,
        ?string $baseUrl = null,
    ): array {
        $resolvedBaseUrl = trim((string) ($baseUrl ?: AiCatalog::defaultBaseUrl($this->providerKey)));
        if ($resolvedBaseUrl === '') {
            throw new InvalidArgumentException('A base URL is required for this AI provider.');
        }

        $useJsonResponseFormat = $this->providerKey === AiCatalog::PROVIDER_OPENAI
            || $this->providerKey === AiCatalog::PROVIDER_OPENROUTER;

        return $this->client->completeStructuredJson(
            prompt: $prompt,
            apiKey: $apiKey,
            model: $model,
            baseUrl: $resolvedBaseUrl,
            providerKey: $this->providerKey,
            useJsonResponseFormat: $useJsonResponseFormat,
        );
    }
}
