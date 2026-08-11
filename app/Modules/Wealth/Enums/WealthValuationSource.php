<?php

namespace App\Modules\Wealth\Enums;

enum WealthValuationSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Import => 'Import',
            self::System => 'System',
        };
    }
}
