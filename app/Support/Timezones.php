<?php

namespace App\Support;

final class Timezones
{
    /**
     * Curated IANA zones for selects. AppSelect renders every option eagerly,
     * so we must not dump timezone_identifiers_list() (~400+) into the page.
     *
     * @var list<string>
     */
    private const PREFERRED = [
        'Africa/Johannesburg',
        'Africa/Windhoek',
        'Africa/Maputo',
        'Africa/Harare',
        'Africa/Gaborone',
        'Africa/Lusaka',
        'Africa/Blantyre',
        'Africa/Nairobi',
        'Africa/Lagos',
        'Africa/Accra',
        'Africa/Cairo',
        'Africa/Casablanca',
        'Europe/London',
        'Europe/Dublin',
        'Europe/Amsterdam',
        'Europe/Berlin',
        'Europe/Paris',
        'Europe/Madrid',
        'Europe/Rome',
        'Europe/Zurich',
        'Europe/Stockholm',
        'UTC',
        'America/New_York',
        'America/Toronto',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Sao_Paulo',
        'America/Mexico_City',
        'Asia/Dubai',
        'Asia/Kolkata',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Shanghai',
        'Asia/Tokyo',
        'Asia/Seoul',
        'Australia/Perth',
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
     * Compact select options (preferred zones + optional current value).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function selectOptions(?string $include = null): array
    {
        $identifiers = timezone_identifiers_list();
        $ordered = array_values(array_filter(
            self::PREFERRED,
            static fn (string $tz): bool => in_array($tz, $identifiers, true)
        ));

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
