<?php

namespace App\Support;

use Illuminate\Http\Request;

final class PageSize
{
    /** @var list<int> */
    public const CHOICES = [10, 15, 25, 50, 100];

    public static function resolve(Request $request, int $default = 15): int
    {
        return self::resolveValue($request->input('per_page'), $default);
    }

    public static function resolveValue(mixed $value, int $default = 15): int
    {
        $requested = (int) $value;
        if (in_array($requested, self::CHOICES, true)) {
            return $requested;
        }

        return in_array($default, self::CHOICES, true) ? $default : 15;
    }
}
