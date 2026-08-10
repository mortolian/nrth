<?php

namespace App\Domain\Vehicles\Services;

use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Models\Team;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TripLogbookPdfService
{
    /** DomPDF memory grows quickly with wide landscape tables; keep exports bounded. */
    public const MAX_TRIPS = 1500;

    public const RENDER_TIME_LIMIT_SECONDS = 90;

    public const RENDER_MEMORY_LIMIT = '256M';

    /**
     * @param  array{search: string, purpose: string, vehicle_id: int, from: string, to: string}  $filters
     * @param  Builder<Trip>  $filteredQuery
     */
    public function downloadForFilters(Team $team, array $filters, Builder $filteredQuery): Response
    {
        $this->assertSafeFilters($filters);

        $count = (clone $filteredQuery)->count();
        if ($count > self::MAX_TRIPS) {
            throw ValidationException::withMessages([
                'export' => __(
                    'Too many trips for a PDF log book (:count). Narrow the date range or filters (max :max), or export CSV instead.',
                    ['count' => number_format($count), 'max' => number_format(self::MAX_TRIPS)]
                ),
            ]);
        }

        $trips = $filteredQuery
            ->with('vehicle:id,name,registration_number,vin,make,model,year')
            ->orderBy('trip_date')
            ->orderBy('id')
            ->limit(self::MAX_TRIPS)
            ->get([
                'id',
                'team_id',
                'vehicle_id',
                'trip_date',
                'distance_km',
                'purpose',
                'from_location',
                'to_location',
                'notes',
            ]);

        $sections = $this->buildSections($trips);
        $summary = $this->buildSummary($trips);

        unset($trips);

        return $this->renderDownload($team, $filters, $sections, $summary);
    }

    /**
     * @param  array{search: string, purpose: string, vehicle_id: int, from: string, to: string}  $filters
     */
    private function assertSafeFilters(array $filters): void
    {
        $from = trim($filters['from']);
        $to = trim($filters['to']);

        if ($from === '' || $to === '') {
            throw ValidationException::withMessages([
                'export' => __('Choose both a from and to date before exporting a PDF log book.'),
            ]);
        }

        if ($from > $to) {
            throw ValidationException::withMessages([
                'export' => __('The from date must be on or before the to date.'),
            ]);
        }
    }

    /**
     * @param  array{search: string, purpose: string, vehicle_id: int, from: string, to: string}  $filters
     * @param  list<array<string, mixed>>  $sections
     * @param  array{trips: int, business_km: float, private_km: float, total_km: float, vehicles: int}  $summary
     */
    private function renderDownload(
        Team $team,
        array $filters,
        array $sections,
        array $summary,
    ): Response {
        $previousMemory = ini_get('memory_limit');
        $previousTime = (int) ini_get('max_execution_time');

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);
        }

        try {
            $pdf = Pdf::loadView('pdf.trip-logbook', [
                'issuer' => $team->issuerForInvoicingDocuments(),
                'logoSrc' => $team->logoDataUriForPdf(),
                'period' => $this->periodLabel($filters),
                'purposeLabel' => $this->purposeLabel($filters['purpose']),
                'searchLabel' => trim($filters['search']) !== '' ? $this->clip((string) $filters['search'], 80) : null,
                'sections' => $sections,
                'summary' => $summary,
                'generatedAt' => now(),
            ])
                ->setPaper('a4', 'landscape')
                ->setOptions([
                    'isRemoteEnabled' => false,
                    'isPhpEnabled' => false,
                    'defaultFont' => 'DejaVu Sans',
                    'dpi' => 96,
                ]);

            return $pdf->download($this->filename($filters));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'export' => __('Could not generate the PDF log book. Narrow the filters and try again, or export CSV instead.'),
            ]);
        } finally {
            if (function_exists('ini_set') && is_string($previousMemory) && $previousMemory !== '') {
                @ini_set('memory_limit', $previousMemory);
            }
            if (function_exists('set_time_limit') && $previousTime > 0) {
                @set_time_limit($previousTime);
            }
        }
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return list<array<string, mixed>>
     */
    private function buildSections(Collection $trips): array
    {
        $grouped = $trips->groupBy(fn (Trip $trip): int => (int) $trip->vehicle_id);
        $sections = [];

        foreach ($grouped as $vehicleTrips) {
            /** @var Trip|null $first */
            $first = $vehicleTrips->first();
            $vehicle = $first?->vehicle;
            if ($vehicle === null) {
                continue;
            }

            $rows = [];
            $businessKm = 0.0;
            $privateKm = 0.0;

            foreach ($vehicleTrips as $trip) {
                $distance = round((float) $trip->distance_km, 1);
                if ($trip->purpose === TripPurpose::Business) {
                    $businessKm += $distance;
                } else {
                    $privateKm += $distance;
                }

                $rows[] = [
                    'trip_date' => optional($trip->trip_date)->toDateString(),
                    'purpose' => $trip->purpose->value,
                    'from_location' => $this->clip($trip->from_location, 80),
                    'to_location' => $this->clip($trip->to_location, 80),
                    'distance_km' => $distance,
                    'notes' => $this->clip($trip->notes, 60),
                ];
            }

            $sections[] = [
                'vehicle' => [
                    'name' => $vehicle->name,
                    'registration_number' => $vehicle->registration_number,
                    'vin' => $vehicle->vin,
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'year' => $vehicle->year !== null ? (int) $vehicle->year : null,
                ],
                'trips' => $rows,
                'totals' => [
                    'trips' => count($rows),
                    'business_km' => round($businessKm, 1),
                    'private_km' => round($privateKm, 1),
                    'total_km' => round($businessKm + $privateKm, 1),
                ],
            ];
        }

        return $sections;
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return array{trips: int, business_km: float, private_km: float, total_km: float, vehicles: int}
     */
    private function buildSummary(Collection $trips): array
    {
        $businessKm = 0.0;
        $privateKm = 0.0;

        foreach ($trips as $trip) {
            $distance = round((float) $trip->distance_km, 1);
            if ($trip->purpose === TripPurpose::Business) {
                $businessKm += $distance;
            } else {
                $privateKm += $distance;
            }
        }

        return [
            'trips' => $trips->count(),
            'business_km' => round($businessKm, 1),
            'private_km' => round($privateKm, 1),
            'total_km' => round($businessKm + $privateKm, 1),
            'vehicles' => $trips->pluck('vehicle_id')->unique()->filter()->count(),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $filters
     */
    private function periodLabel(array $filters): string
    {
        return trim($filters['from']).' to '.trim($filters['to']);
    }

    private function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            TripPurpose::Business->value => 'Business only',
            TripPurpose::Private->value => 'Private only',
            default => 'Business and private',
        };
    }

    /**
     * @param  array{from: string, to: string}  $filters
     */
    private function filename(array $filters): string
    {
        return sprintf(
            'travel-logbook-%s-to-%s.pdf',
            trim($filters['from']),
            trim($filters['to']),
        );
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, max(1, $max - 1)).'…';
    }
}
