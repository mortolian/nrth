<?php

namespace App\Support\Modules;

final class ModuleCatalog
{
    public const WEALTH = 'wealth';

    public const TRAVEL = 'travel';

    public const PLANNING = 'planning';

    /**
     * @return array<string, array{label: string, description: string, default_enabled: bool, experimental: bool}>
     */
    public static function definitions(): array
    {
        return [
            self::TRAVEL => [
                'label' => 'Travel',
                'description' => 'Vehicles, trip log book, licence reminders, and trip imports.',
                'default_enabled' => false,
                'experimental' => false,
            ],
            self::PLANNING => [
                'label' => 'Planning',
                'description' => 'Category budgets, envelopes, and variance tracking.',
                'default_enabled' => false,
                'experimental' => false,
            ],
            self::WEALTH => [
                'label' => 'Wealth',
                'description' => 'Track investment accounts, savings, retirement funds, and portfolio history.',
                'default_enabled' => false,
                'experimental' => false,
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
     * @return list<array{name: string, label: string, description: string, default_enabled: bool, experimental: bool}>
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
                'experimental' => $meta['experimental'],
            ];
        }

        return $items;
    }
}
