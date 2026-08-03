<?php

namespace App\Domain\Vehicles\Services;

use App\Domain\Vehicles\Models\Trip;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

final class TripLogDeduper
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  Collection<int, Trip>  $existing
     * @return list<array<string, mixed>>
     */
    public function mark(array $candidates, Collection $existing): array
    {
        $marked = [];
        $seenInBatch = [];

        foreach ($candidates as $candidate) {
            $duplicateReason = $this->findDuplicateReason($candidate, $existing, $seenInBatch);
            $candidate['is_duplicate'] = $duplicateReason !== null;
            $candidate['duplicate_reason'] = $duplicateReason;
            $candidate['include'] = $duplicateReason === null;
            $marked[] = $candidate;
            $seenInBatch[] = $candidate;
        }

        return $marked;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  Collection<int, Trip>  $existing
     * @param  list<array<string, mixed>>  $seenInBatch
     */
    private function findDuplicateReason(array $candidate, Collection $existing, array $seenInBatch): ?string
    {
        foreach ($seenInBatch as $other) {
            if ($this->matches($candidate, $this->arrayAsComparable($other))) {
                return __('Duplicate of another trip in this import.');
            }
        }

        foreach ($existing as $trip) {
            if ($this->matches($candidate, $this->tripAsComparable($trip))) {
                return __('Already in your log book.');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array{
     *     trip_date: string|null,
     *     started_at: string|null,
     *     distance_km: float,
     *     from_location: string|null,
     *     to_location: string|null
     * }  $other
     */
    private function matches(array $candidate, array $other): bool
    {
        $candidateDate = $this->nullableString($candidate['trip_date'] ?? null);
        $otherDate = $this->nullableString($other['trip_date'] ?? null);
        if ($candidateDate === null || $otherDate === null || $candidateDate !== $otherDate) {
            return false;
        }

        $distanceClose = abs((float) $candidate['distance_km'] - (float) $other['distance_km']) <= 0.5;
        if (! $distanceClose) {
            return false;
        }

        $fromMatch = $this->placesMatch($candidate['from_location'] ?? null, $other['from_location'] ?? null);
        $toMatch = $this->placesMatch($candidate['to_location'] ?? null, $other['to_location'] ?? null);
        if ($fromMatch && $toMatch) {
            return true;
        }

        $candidateStart = $this->parseDateTime($candidate['started_at'] ?? null);
        $otherStart = $this->parseDateTime($other['started_at'] ?? null);
        if ($candidateStart !== null && $otherStart !== null) {
            return abs($candidateStart->diffInMinutes($otherStart, false)) <= 15;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     trip_date: string|null,
     *     started_at: string|null,
     *     distance_km: float,
     *     from_location: string|null,
     *     to_location: string|null
     * }
     */
    private function arrayAsComparable(array $row): array
    {
        return [
            'trip_date' => $this->nullableString($row['trip_date'] ?? null),
            'started_at' => $this->nullableString($row['started_at'] ?? null),
            'distance_km' => (float) ($row['distance_km'] ?? 0),
            'from_location' => $this->nullableString($row['from_location'] ?? null),
            'to_location' => $this->nullableString($row['to_location'] ?? null),
        ];
    }

    /**
     * @return array{
     *     trip_date: string|null,
     *     started_at: string|null,
     *     distance_km: float,
     *     from_location: string|null,
     *     to_location: string|null
     * }
     */
    private function tripAsComparable(Trip $trip): array
    {
        return [
            'trip_date' => optional($trip->trip_date)?->toDateString(),
            'started_at' => optional($trip->started_at)?->format('Y-m-d H:i:s'),
            'distance_km' => (float) $trip->distance_km,
            'from_location' => $trip->from_location,
            'to_location' => $trip->to_location,
        ];
    }

    private function placesMatch(mixed $a, mixed $b): bool
    {
        $left = $this->normalizePlace($a);
        $right = $this->normalizePlace($b);
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left);
    }

    private function normalizePlace(mixed $place): ?string
    {
        $raw = $this->nullableString($place);
        if ($raw === null) {
            return null;
        }
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower($raw)) ?? '';

        return $normalized === '' ? null : $normalized;
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
