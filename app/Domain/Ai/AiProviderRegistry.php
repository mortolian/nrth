<?php

namespace App\Domain\Ai;

use InvalidArgumentException;

final class AiProviderRegistry
{
    /**
     * @param  iterable<AiProvider>  $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    public function get(string $key): AiProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        throw new InvalidArgumentException("Unknown AI provider [{$key}].");
    }
}
