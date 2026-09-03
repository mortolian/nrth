<?php

namespace App\Domain\Banking\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Imported = 'imported';
    case Undone = 'undone';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Parsed => 'Previewed',
            self::Imported => 'Imported',
            self::Undone => 'Undone',
            self::Failed => 'Failed',
        };
    }

    /**
     * History collapses unconfirmed wizard states; imported and undone stay distinct.
     */
    public function historyGroup(): string
    {
        return match ($this) {
            self::Imported => 'imported',
            self::Undone => 'undone',
            default => 'not_imported',
        };
    }

    public function historyLabel(): string
    {
        return match ($this->historyGroup()) {
            'imported' => 'Imported',
            'undone' => 'Undone',
            default => 'Not imported',
        };
    }

    /**
     * @return list<self>
     */
    public static function forHistoryFilter(string $group): array
    {
        return match ($group) {
            'imported' => [self::Imported],
            'undone' => [self::Undone],
            'not_imported' => [self::Pending, self::Parsed, self::Failed],
            default => [],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function historyFilterOptions(): array
    {
        return [
            ['value' => 'imported', 'label' => 'Imported'],
            ['value' => 'not_imported', 'label' => 'Not imported'],
            ['value' => 'undone', 'label' => 'Undone'],
        ];
    }

    /**
     * Statement files that are not currently applied to the books can be removed from history.
     */
    public function canPermanentlyDelete(): bool
    {
        return $this !== self::Imported;
    }
}
