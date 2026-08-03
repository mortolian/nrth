<?php

namespace App\Domain\Vehicles\Services;

use Carbon\Carbon;
use Throwable;

/**
 * Merges consecutive GPS/telematics stop segments into single journeys.
 *
 * Fleet exports often emit a new row for every brief stop. We merge when the
 * gap between segments is short and purpose stays the same.
 */
final class TripLogConsolidator
{
    /** Max parked/stopped gap (minutes) still treated as one trip. */
    public const MAX_STOP_GAP_MINUTES = 45;

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    public function consolidate(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        $sorted = $segments;
        usort($sorted, function (array $a, array $b): int {
            $aStart = $this->parseDateTime($a['started_at'] ?? null) ?? $this->parseDateTime($a['trip_date'] ?? null);
            $bStart = $this->parseDateTime($b['started_at'] ?? null) ?? $this->parseDateTime($b['trip_date'] ?? null);
            if ($aStart === null && $bStart === null) {
                return 0;
            }
            if ($aStart === null) {
                return 1;
            }
            if ($bStart === null) {
                return -1;
            }

            return $aStart->timestamp <=> $bStart->timestamp;
        });

        $merged = [];
        $current = null;

        foreach ($sorted as $segment) {
            $normalized = $this->normalizeSegment($segment);
            if ($normalized === null) {
                continue;
            }

            if ($current === null) {
                $current = $normalized;
                continue;
            }

            if ($this->shouldMerge($current, $normalized)) {
                $current = $this->mergeInto($current, $normalized);
            } else {
                $merged[] = $current;
                $current = $normalized;
            }
        }

        if ($current !== null) {
            $merged[] = $current;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>|null
     */
    private function normalizeSegment(array $segment): ?array
    {
        $distance = $this->nullableFloat($segment['distance_km'] ?? null);
        if ($distance === null || $distance < 0) {
            return null;
        }

        $startedAt = $this->parseDateTime($segment['started_at'] ?? null);
        $endedAt = $this->parseDateTime($segment['ended_at'] ?? null);
        $tripDate = $this->nullableString($segment['trip_date'] ?? null)
            ?? $startedAt?->toDateString();

        if ($tripDate === null) {
            return null;
        }

        $purpose = $this->normalizePurpose($segment['purpose'] ?? null);
        $duration = isset($segment['duration_seconds']) && is_numeric($segment['duration_seconds'])
            ? max(0, (int) $segment['duration_seconds'])
            : null;

        if ($duration === null && $startedAt !== null && $endedAt !== null) {
            $duration = max(0, (int) $startedAt->diffInSeconds($endedAt));
        }

        $segmentsMerged = isset($segment['segments_merged']) && is_numeric($segment['segments_merged'])
            ? max(1, (int) $segment['segments_merged'])
            : 1;

        return [
            'trip_date' => $tripDate,
            'started_at' => $startedAt?->format('Y-m-d H:i:s'),
            'ended_at' => $endedAt?->format('Y-m-d H:i:s'),
            'duration_seconds' => $duration,
            'distance_km' => round($distance, 1),
            'purpose' => $purpose,
            'from_location' => $this->nullableString($segment['from_location'] ?? null),
            'to_location' => $this->nullableString($segment['to_location'] ?? null),
            'start_latitude' => $this->nullableCoord($segment['start_latitude'] ?? null, true),
            'start_longitude' => $this->nullableCoord($segment['start_longitude'] ?? null, false),
            'end_latitude' => $this->nullableCoord($segment['end_latitude'] ?? null, true),
            'end_longitude' => $this->nullableCoord($segment['end_longitude'] ?? null, false),
            'notes' => $this->nullableString($segment['notes'] ?? null),
            'segments_merged' => $segmentsMerged,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $next
     */
    private function shouldMerge(array $current, array $next): bool
    {
        if (($current['purpose'] ?? null) !== ($next['purpose'] ?? null)) {
            return false;
        }

        $currentEnd = $this->parseDateTime($current['ended_at'] ?? null);
        $nextStart = $this->parseDateTime($next['started_at'] ?? null);

        if ($currentEnd !== null && $nextStart !== null) {
            if ($nextStart->lt($currentEnd)) {
                // Overlapping / nested segments — merge.
                return true;
            }

            $gapMinutes = $currentEnd->diffInMinutes($nextStart, false);

            return $gapMinutes >= 0 && $gapMinutes <= self::MAX_STOP_GAP_MINUTES;
        }

        // Without reliable timestamps, merge only when the next start matches the current end place.
        $currentTo = $this->normalizePlace($current['to_location'] ?? null);
        $nextFrom = $this->normalizePlace($next['from_location'] ?? null);

        return $currentTo !== null
            && $nextFrom !== null
            && $currentTo === $nextFrom
            && ($current['trip_date'] ?? null) === ($next['trip_date'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private function mergeInto(array $current, array $next): array
    {
        $currentStart = $this->parseDateTime($current['started_at'] ?? null);
        $nextStart = $this->parseDateTime($next['started_at'] ?? null);
        $currentEnd = $this->parseDateTime($current['ended_at'] ?? null);
        $nextEnd = $this->parseDateTime($next['ended_at'] ?? null);

        $startedAt = $currentStart;
        if ($nextStart !== null && ($startedAt === null || $nextStart->lt($startedAt))) {
            $startedAt = $nextStart;
            $current['from_location'] = $next['from_location'] ?? $current['from_location'];
            $current['start_latitude'] = $next['start_latitude'] ?? $current['start_latitude'];
            $current['start_longitude'] = $next['start_longitude'] ?? $current['start_longitude'];
        }

        $endedAt = $currentEnd;
        if ($nextEnd !== null && ($endedAt === null || $nextEnd->gt($endedAt))) {
            $endedAt = $nextEnd;
            $current['to_location'] = $next['to_location'] ?? $current['to_location'];
            $current['end_latitude'] = $next['end_latitude'] ?? $current['end_latitude'];
            $current['end_longitude'] = $next['end_longitude'] ?? $current['end_longitude'];
        } elseif (($next['to_location'] ?? null) !== null) {
            $current['to_location'] = $next['to_location'];
            $current['end_latitude'] = $next['end_latitude'] ?? $current['end_latitude'];
            $current['end_longitude'] = $next['end_longitude'] ?? $current['end_longitude'];
        }

        $current['started_at'] = $startedAt?->format('Y-m-d H:i:s');
        $current['ended_at'] = $endedAt?->format('Y-m-d H:i:s');
        $current['trip_date'] = $startedAt?->toDateString() ?? $current['trip_date'];
        $current['distance_km'] = round((float) $current['distance_km'] + (float) $next['distance_km'], 1);
        $current['segments_merged'] = (int) $current['segments_merged'] + (int) $next['segments_merged'];

        if ($startedAt !== null && $endedAt !== null) {
            $current['duration_seconds'] = max(0, (int) $startedAt->diffInSeconds($endedAt));
        } else {
            $current['duration_seconds'] = ((int) ($current['duration_seconds'] ?? 0))
                + ((int) ($next['duration_seconds'] ?? 0));
            if ($current['duration_seconds'] === 0) {
                $current['duration_seconds'] = null;
            }
        }

        $notes = array_values(array_filter([
            $this->nullableString($current['notes'] ?? null),
            $this->nullableString($next['notes'] ?? null),
        ]));
        $current['notes'] = $notes === [] ? null : implode(' · ', array_unique($notes));

        return $current;
    }

    private function normalizePurpose(mixed $value): string
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        return match (true) {
            in_array($raw, ['private', 'personal', 'private trip', 'personal trip'], true) => 'private',
            default => 'business',
        };
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizePlace(?string $place): ?string
    {
        if ($place === null) {
            return null;
        }
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($place))) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', ' km', 'km'], ['', '', ''], $value);
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullableCoord(mixed $value, bool $latitude): ?float
    {
        $float = $this->nullableFloat($value);
        if ($float === null) {
            return null;
        }
        if ($latitude && ($float < -90 || $float > 90)) {
            return null;
        }
        if (! $latitude && ($float < -180 || $float > 180)) {
            return null;
        }

        return round($float, 7);
    }
}
