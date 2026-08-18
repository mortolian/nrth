<?php

namespace App\Domain\Banking\Enums;

enum ReconciliationStatus: string
{
    case Unreviewed = 'unreviewed';
    case PartiallyMatched = 'partially_matched';
    case Matched = 'matched';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Unreviewed => 'Unreviewed',
            self::PartiallyMatched => 'Partially matched',
            self::Matched => 'Matched',
            self::Excluded => 'Excluded',
        };
    }
}
