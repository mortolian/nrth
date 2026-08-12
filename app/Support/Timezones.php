<?php

namespace App\Support;

final class Timezones
{
    /**
     * Preferred zones shown first in selects (still valid IANA identifiers).
     *
     * @var list<string>
     */
    private const PREFERRED = [
        'Africa/Johannesburg',
        'Africa/Windhoek',
        'Africa/Maputo',
        'Africa/Harare',
        'Africa/Gaborone',
        'Africa/Nairobi',
        'Africa/Lagos',
        'Africa/Cairo',
        'Europe/London',
        'Europe/Amsterdam',
        'Europe/Berlin',
        'Europe/Paris',
        'UTC',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Sao_Paulo',
        'Asia/Dubai',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Tokyo',
        'Australia/Sydney',
        'Pacific/Auckland',
    ];

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }

    public static function normalize(?string $timezone, string $fallback = 'UTC'): string
    {
        $tz = is_string($timezone) ? trim($timezone) : '';

        if ($tz !== '' && self::isValid($tz)) {
            return $tz;
        }

        return self::isValid($fallback) ? $fallback : 'UTC';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function selectOptions(?string $include = null): array
    {
        $identifiers = timezone_identifiers_list();
        $preferred = array_values(array_filter(
            self::PREFERRED,
            static fn (string $tz): bool => in_array($tz, $identifiers, true)
        ));

        $rest = array_values(array_diff($identifiers, $preferred));
        sort($rest);

        $ordered = [...$preferred, ...$rest];

        if (is_string($include) && $include !== '' && self::isValid($include) && ! in_array($include, $ordered, true)) {
            array_unshift($ordered, $include);
        }

        return array_map(
            static fn (string $tz): array => [
                'value' => $tz,
                'label' => $tz,
            ],
            $ordered
        );
    }
}
