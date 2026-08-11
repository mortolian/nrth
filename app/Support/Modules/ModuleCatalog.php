<?php

namespace App\Support\Modules;

final class ModuleCatalog
{
    public const WEALTH = 'wealth';

    /**
     * @return array<string, array{label: string, description: string, default_enabled: bool}>
     */
    public static function definitions(): array
    {
        return [
            self::WEALTH => [
                'label' => 'Wealth',
                'description' => 'Track net worth, investments, and related personal-finance views. Coming soon — this is a placeholder.',
                'default_enabled' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    public static function isValid(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    public static function defaultEnabled(string $name): bool
    {
        return (bool) (self::definitions()[$name]['default_enabled'] ?? false);
    }

    /**
     * @return list<array{name: string, label: string, description: string, default_enabled: bool}>
     */
    public static function forUi(): array
    {
        $items = [];

        foreach (self::definitions() as $name => $meta) {
            $items[] = [
                'name' => $name,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'default_enabled' => $meta['default_enabled'],
            ];
        }

        return $items;
    }
}
