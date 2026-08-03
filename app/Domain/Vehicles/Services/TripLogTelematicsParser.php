<?php

namespace App\Domain\Vehicles\Services;

use Carbon\Carbon;
use Throwable;

/**
 * Deterministic parser for common fleet / GPS log-book exports
 * (Toyota Fleet Management style and similar column layouts).
 */
final class TripLogTelematicsParser
{
    /**
     * @param  list<list<string>>  $rows
     * @return array{
     *     matched: bool,
     *     vehicle_registration: string|null,
     *     vehicle_vin: string|null,
     *     segments: list<array<string, mixed>>,
     *     source_segments_count: int
     * }
     */
    public function tryParse(array $rows): array
    {
        if ($rows === []) {
            return $this->emptyResult();
        }

        $meta = $this->extractVehicleMeta($rows);
        [$headerIndex, $map] = $this->findHeaderMap($rows);
        if ($headerIndex === null || $map === null) {
            return $this->emptyResult($meta);
        }

        $segments = [];
        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $segment = $this->rowToSegment($row, $map);
            if ($segment !== null) {
                $segments[] = $segment;
            }
        }

        if ($segments === []) {
            return $this->emptyResult($meta);
        }

        return [
            'matched' => true,
            'vehicle_registration' => $meta['vehicle_registration'],
            'vehicle_vin' => $meta['vehicle_vin'],
            'segments' => $segments,
            'source_segments_count' => count($segments),
        ];
    }

    /**
     * @param  array{vehicle_registration: string|null, vehicle_vin: string|null}|null  $meta
     * @return array{
     *     matched: bool,
     *     vehicle_registration: string|null,
     *     vehicle_vin: string|null,
     *     segments: list<array<string, mixed>>,
     *     source_segments_count: int
     * }
     */
    private function emptyResult(?array $meta = null): array
    {
        return [
            'matched' => false,
            'vehicle_registration' => $meta['vehicle_registration'] ?? null,
            'vehicle_vin' => $meta['vehicle_vin'] ?? null,
            'segments' => [],
            'source_segments_count' => 0,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{vehicle_registration: string|null, vehicle_vin: string|null}
     */
    private function extractVehicleMeta(array $rows): array
    {
        $registration = null;
        $vin = null;

        foreach (array_slice($rows, 0, 15) as $row) {
            // Label / value pairs across adjacent cells
            for ($i = 0; $i < count($row) - 1; $i++) {
                $label = mb_strtolower(trim($row[$i]));
                $value = trim($row[$i + 1]);
                if ($value === '') {
                    continue;
                }
                if ($registration === null && in_array($label, ['vehicle reg', 'reg', 'registration', 'registration number'], true)) {
                    $registration = $value;
                }
                if ($vin === null && $label === 'vin') {
                    $vin = strtoupper($value);
                }
            }

            $joined = implode(' ', $row);
            if ($registration === null && preg_match('/\b(?:vehicle\s*)?reg(?:istration)?\b[:\s]+([A-Z0-9 \-]{4,20}?)(?=\s+(?:VIN|Vehicle|Name|Driver)\b|$)/i', $joined, $m)) {
                $registration = trim($m[1]);
            }
            if ($vin === null && preg_match('/\bVIN\b[:\s]+([A-HJ-NPR-Z0-9]{11,17})/i', $joined, $m)) {
                $vin = strtoupper(trim($m[1]));
            }
        }

        return [
            'vehicle_registration' => $registration,
            'vehicle_vin' => $vin,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{0: int|null, 1: array<string, int>|null}
     */
    private function findHeaderMap(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $map = [];
            foreach ($row as $col => $cell) {
                $key = $this->mapHeader(trim($cell));
                if ($key !== null && ! isset($map[$key])) {
                    $map[$key] = $col;
                }
            }

            $hasDistance = isset($map['distance_km']);
            $hasRoute = isset($map['from_location']) || isset($map['to_location']);
            $hasTime = isset($map['started_at']) || isset($map['ended_at']);

            if ($hasDistance && ($hasRoute || $hasTime) && count($map) >= 3) {
                return [$index, $map];
            }
        }

        return [null, null];
    }

    private function mapHeader(string $header): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower($header)) ?? '';
        $normalized = trim($normalized, " \t\n\r\0\x0B:");

        return match (true) {
            $normalized === 'distance'
            || str_contains($normalized, 'distance (km)')
            || $normalized === 'km'
            || $normalized === 'distance km' => 'distance_km',
            $normalized === 'start address'
            || $normalized === 'from'
            || $normalized === 'from address'
            || $normalized === 'start location' => 'from_location',
            $normalized === 'end address'
            || $normalized === 'to'
            || $normalized === 'to address'
            || $normalized === 'end location' => 'to_location',
            $normalized === 'start date'
            || $normalized === 'start time'
            || $normalized === 'started at'
            || $normalized === 'start datetime' => 'started_at',
            $normalized === 'end date'
            || $normalized === 'end time'
            || $normalized === 'ended at'
            || $normalized === 'end datetime' => 'ended_at',
            $normalized === 'time passed'
            || $normalized === 'duration'
            || $normalized === 'trip duration' => 'duration_raw',
            $normalized === 'trip type'
            || $normalized === 'purpose'
            || $normalized === 'type' => 'purpose',
            $normalized === 'start latitude and longitude'
            || $normalized === 'start latlng'
            || $normalized === 'start lat/lng' => 'start_latlng',
            $normalized === 'end latitude and longitude'
            || $normalized === 'end latlng'
            || $normalized === 'end lat/lng' => 'end_latlng',
            $normalized === 'start latitude' || $normalized === 'start lat' => 'start_latitude',
            $normalized === 'start longitude' || $normalized === 'start lng' || $normalized === 'start lon' => 'start_longitude',
            $normalized === 'end latitude' || $normalized === 'end lat' => 'end_latitude',
            $normalized === 'end longitude' || $normalized === 'end lng' || $normalized === 'end lon' => 'end_longitude',
            $normalized === 'notes' || $normalized === 'comment' || $normalized === 'remarks' => 'notes',
            default => null,
        };
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function rowToSegment(array $row, array $map): ?array
    {
        $distance = $this->cellFloat($row, $map['distance_km'] ?? null);
        if ($distance === null || $distance < 0) {
            return null;
        }

        $startedAt = $this->cellDateTime($row, $map['started_at'] ?? null);
        $endedAt = $this->cellDateTime($row, $map['ended_at'] ?? null);
        if ($startedAt === null && $endedAt === null) {
            return null;
        }

        [$startLat, $startLng] = $this->resolveCoords(
            $row,
            $map,
            'start_latitude',
            'start_longitude',
            'start_latlng',
        );
        [$endLat, $endLng] = $this->resolveCoords(
            $row,
            $map,
            'end_latitude',
            'end_longitude',
            'end_latlng',
        );

        $durationSeconds = $this->parseDurationSeconds($this->cell($row, $map['duration_raw'] ?? null));
        if ($durationSeconds === null && $startedAt !== null && $endedAt !== null) {
            $durationSeconds = max(0, (int) $startedAt->diffInSeconds($endedAt));
        }

        return [
            'trip_date' => $startedAt?->toDateString() ?? $endedAt?->toDateString(),
            'started_at' => $startedAt?->format('Y-m-d H:i:s'),
            'ended_at' => $endedAt?->format('Y-m-d H:i:s'),
            'duration_seconds' => $durationSeconds,
            'distance_km' => round($distance, 1),
            'purpose' => $this->normalizePurpose($this->cell($row, $map['purpose'] ?? null)),
            'from_location' => $this->cell($row, $map['from_location'] ?? null),
            'to_location' => $this->cell($row, $map['to_location'] ?? null),
            'start_latitude' => $startLat,
            'start_longitude' => $startLng,
            'end_latitude' => $endLat,
            'end_longitude' => $endLng,
            'notes' => $this->cell($row, $map['notes'] ?? null),
            'segments_merged' => 1,
        ];
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array{0: float|null, 1: float|null}
     */
    private function resolveCoords(array $row, array $map, string $latKey, string $lngKey, string $combinedKey): array
    {
        $lat = $this->cellFloat($row, $map[$latKey] ?? null);
        $lng = $this->cellFloat($row, $map[$lngKey] ?? null);
        if ($lat !== null && $lng !== null) {
            return [$lat, $lng];
        }

        return $this->parseLatLng($this->cell($row, $map[$combinedKey] ?? null));
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function parseLatLng(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [null, null];
        }

        $raw = trim($value);

        // Toyota-style: -34,19084,22,11535 (comma decimals + comma separator)
        if (preg_match('/^(-?\d+),(\d+)\s*,\s*(-?\d+),(\d+)$/', $raw, $m)) {
            return [
                round((float) ($m[1].'.'.$m[2]), 7),
                round((float) ($m[3].'.'.$m[4]), 7),
            ];
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)/', $raw, $m)) {
            return [round((float) $m[1], 7), round((float) $m[2], 7)];
        }

        return [null, null];
    }

    private function parseDurationSeconds(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);

        if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $seconds = isset($m[3]) ? (int) $m[3] : 0;

            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }

        $number = $this->parseLocaleNumber($raw);
        if ($number !== null) {
            // Fleet "Time Passed" values are minutes (often fractional, e.g. 6,42).
            return (int) round($number * 60);
        }

        if (preg_match('/(?:(\d+)\s*h)?\s*(?:(\d+)\s*m)?\s*(?:(\d+)\s*s)?/i', $raw, $m) && ($m[1].$m[2].$m[3] !== '')) {
            return ((int) ($m[1] ?: 0) * 3600)
                + ((int) ($m[2] ?: 0) * 60)
                + (int) ($m[3] ?: 0);
        }

        return null;
    }

    private function parseLocaleNumber(string $value): ?float
    {
        $raw = trim(str_ireplace(['km', ' '], '', $value));
        if ($raw === '') {
            return null;
        }

        // 1.234,56 → 1234.56
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $raw)) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^-?\d+,\d+$/', $raw)) {
            // 53,00 / 6,42 → decimal comma
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $raw)) {
            // 1,234.56
            $raw = str_replace(',', '', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function normalizePurpose(?string $value): string
    {
        $raw = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($raw, ['private', 'personal', 'private trip', 'personal trip'], true) => 'private',
            default => 'business',
        };
    }

    /**
     * @param  list<string>  $row
     */
    private function cell(array $row, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }
        $value = trim((string) $row[$index]);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $row
     */
    private function cellFloat(array $row, ?int $index): ?float
    {
        $value = $this->cell($row, $index);
        if ($value === null) {
            return null;
        }

        return $this->parseLocaleNumber($value);
    }

    /**
     * @param  list<string>  $row
     */
    private function cellDateTime(array $row, ?int $index): ?Carbon
    {
        $value = $this->cell($row, $index);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
