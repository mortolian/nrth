<?php

namespace App\Domain\Banking\Support;

use Carbon\Carbon;

final class DateParser
{
    /** @var list<string> */
    private const FORMATS = [
        'Y-m-d',
        'd/m/Y',
        'd-m-Y',
        'm/d/Y',
        'd.m.Y',
        'Y/m/d',
        'd M Y',
        'M d, Y',
    ];

    public function parse(?string $value, ?string $preferredFormat = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($preferredFormat !== null && $preferredFormat !== '') {
            try {
                return Carbon::createFromFormat($preferredFormat, $value)->toDateString();
            } catch (\Throwable) {
                // fall through
            }
        }

        foreach (self::FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->toDateString();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
