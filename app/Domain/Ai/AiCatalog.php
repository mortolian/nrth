<?php

namespace App\Domain\Ai;

final class AiCatalog
{
    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_ANTHROPIC = 'anthropic';

    public const PROVIDER_GEMINI = 'gemini';

    public const PROVIDER_OPENROUTER = 'openrouter';

    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return [
            self::PROVIDER_OPENAI,
            self::PROVIDER_ANTHROPIC,
            self::PROVIDER_GEMINI,
            self::PROVIDER_OPENROUTER,
            self::PROVIDER_OPENAI_COMPATIBLE,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function providerOptions(): array
    {
        return [
            ['value' => self::PROVIDER_OPENAI, 'label' => 'OpenAI'],
            ['value' => self::PROVIDER_ANTHROPIC, 'label' => 'Anthropic'],
            ['value' => self::PROVIDER_GEMINI, 'label' => 'Google Gemini'],
            ['value' => self::PROVIDER_OPENROUTER, 'label' => 'OpenRouter'],
            ['value' => self::PROVIDER_OPENAI_COMPATIBLE, 'label' => 'OpenAI-compatible'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function modelsByProvider(): array
    {
        return [
            self::PROVIDER_OPENAI => [
                'gpt-4o-mini',
                'gpt-4o',
                'gpt-4.1-mini',
                'gpt-4.1',
                'gpt-4.1-nano',
            ],
            self::PROVIDER_ANTHROPIC => [
                'claude-haiku-4-5',
                'claude-sonnet-4-5',
                'claude-opus-4-5',
            ],
            self::PROVIDER_GEMINI => [
                'gemini-2.5-flash',
                'gemini-2.5-pro',
                'gemini-2.0-flash',
            ],
            self::PROVIDER_OPENROUTER => [
                'openai/gpt-4o-mini',
                'openai/gpt-4o',
                'anthropic/claude-sonnet-4.5',
                'google/gemini-2.5-flash',
            ],
            self::PROVIDER_OPENAI_COMPATIBLE => [
                'gpt-4o-mini',
                'llava',
                'llama3.2-vision',
                'moondream',
            ],
        ];
    }

    public static function defaultModel(string $provider): string
    {
        $models = self::modelsByProvider()[$provider] ?? [];

        return $models[0] ?? 'gpt-4o-mini';
    }

    public static function defaultBaseUrl(string $provider): ?string
    {
        return match ($provider) {
            self::PROVIDER_OPENAI => 'https://api.openai.com/v1',
            self::PROVIDER_OPENROUTER => 'https://openrouter.ai/api/v1',
            default => null,
        };
    }

    public static function showsBaseUrl(string $provider): bool
    {
        return in_array($provider, [
            self::PROVIDER_OPENAI_COMPATIBLE,
            self::PROVIDER_OPENROUTER,
        ], true);
    }

    public static function apiKeyOptional(string $provider): bool
    {
        return $provider === self::PROVIDER_OPENAI_COMPATIBLE;
    }

    public static function allowsCustomModel(string $provider): bool
    {
        return in_array($provider, [
            self::PROVIDER_OPENROUTER,
            self::PROVIDER_OPENAI_COMPATIBLE,
        ], true);
    }

    public static function isValidProvider(string $provider): bool
    {
        return in_array($provider, self::providers(), true);
    }

    public static function isValidModel(string $provider, string $model): bool
    {
        $model = trim($model);
        if ($model === '' || strlen($model) > 128) {
            return false;
        }

        if (! preg_match('/^[a-zA-Z0-9.\\/_:-]+$/', $model)) {
            return false;
        }

        if (self::allowsCustomModel($provider)) {
            return true;
        }

        $models = self::modelsByProvider()[$provider] ?? [];

        return in_array($model, $models, true);
    }

    /**
     * @return list<string>
     */
    public static function modelsFor(string $provider): array
    {
        return self::modelsByProvider()[$provider] ?? [];
    }
}
