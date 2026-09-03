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
     * Statement files that are not currently applied to the books can be removed from history.
     */
    public function canPermanentlyDelete(): bool
    {
        return $this !== self::Imported;
    }
}
