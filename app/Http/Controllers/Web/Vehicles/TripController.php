<?php

namespace App\Http\Controllers\Web\Vehicles;

use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Services\TripOdometerEstimator;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TripController extends Controller
{
    public function __construct(
        private readonly TripOdometerEstimator $odometerEstimator,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeTeam('vehicles.view', $request);

        $teamId = (int) $request->user()->current_team_id;
        $filters = $this->filtersFromRequest($request);

        $query = $this->filteredTripsQuery($teamId, $filters)
            ->with('vehicle:id,name,registration_number,current_odometer_km');

        $trips = $query
            ->orderByDesc('trip_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $estimatesByVehicle = $this->estimatesForPage($teamId, $trips->getCollection());

        $trips->setCollection(
            $trips->getCollection()->map(
                fn (Trip $trip): array => $this->serializeTrip(
                    $trip,
                    estimates: $estimatesByVehicle[(int) $trip->vehicle_id][(int) $trip->id] ?? null,
                )
            )
        );

        return Inertia::render('Vehicles/Trips/Index', [
            'trips' => $trips,
            'vehicles' => $this->vehicleOptions($teamId),
            'filters' => [
                'search' => $filters['search'] ?: null,
                'purpose' => $filters['purpose'] ?: 'all',
                'vehicle_id' => $filters['vehicle_id'] > 0 ? $filters['vehicle_id'] : null,
                'from' => $filters['from'] ?: null,
                'to' => $filters['to'] ?: null,
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeTeam('vehicles.view', $request);

        $teamId = (int) $request->user()->current_team_id;
        $filters = $this->filtersFromRequest($request);

        $trips = $this->filteredTripsQuery($teamId, $filters)
            ->with('vehicle:id,name,registration_number,vin,current_odometer_km')
            ->orderBy('trip_date')
            ->orderBy('id')
            ->get();

        $estimatesByVehicle = $this->estimatesForPage($teamId, $trips);

        $filename = 'travel-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($trips, $estimatesByVehicle): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Date',
                'Started at',
                'Ended at',
                'Duration (minutes)',
                'Vehicle',
                'Registration',
                'VIN',
                'Purpose',
                'From',
                'To',
                'Opening odometer (km)',
                'Closing odometer (km)',
                'Distance (km)',
                'Start latitude',
                'Start longitude',
                'End latitude',
                'End longitude',
                'Notes',
            ]);

            foreach ($trips as $trip) {
                $estimate = $estimatesByVehicle[(int) $trip->vehicle_id][(int) $trip->id] ?? null;
                $durationMinutes = $trip->duration_seconds !== null
                    ? round(((int) $trip->duration_seconds) / 60, 2)
                    : null;

                fputcsv($handle, [
                    optional($trip->trip_date)->toDateString() ?? '',
                    optional($trip->started_at)?->format('Y-m-d H:i:s') ?? '',
                    optional($trip->ended_at)?->format('Y-m-d H:i:s') ?? '',
                    $durationMinutes !== null ? number_format($durationMinutes, 2, '.', '') : '',
                    $trip->vehicle?->name ?? '',
                    $trip->vehicle?->registration_number ?? '',
                    $trip->vehicle?->vin ?? '',
                    $trip->purpose->value,
                    (string) ($trip->from_location ?? ''),
                    (string) ($trip->to_location ?? ''),
                    $estimate['opening_km'] !== null ? number_format($estimate['opening_km'], 1, '.', '') : '',
                    $estimate['closing_km'] !== null ? number_format($estimate['closing_km'], 1, '.', '') : '',
                    number_format((float) $trip->distance_km, 1, '.', ''),
                    $trip->start_latitude !== null ? (string) $trip->start_latitude : '',
                    $trip->start_longitude !== null ? (string) $trip->start_longitude : '',
                    $trip->end_latitude !== null ? (string) $trip->end_latitude : '',
                    $trip->end_longitude !== null ? (string) $trip->end_longitude : '',
                    (string) ($trip->notes ?? ''),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('vehicles.manage', $request);

        $teamId = (int) $request->user()->current_team_id;
        $vehicleId = (int) $request->integer('vehicle_id');

        return Inertia::render('Vehicles/Trips/Form', [
            'isEditing' => false,
            'trip' => null,
            'vehicles' => $this->vehicleOptions($teamId, activeOnly: true),
            'prefill' => [
                'vehicle_id' => $vehicleId > 0 ? $vehicleId : null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);

        $teamId = (int) $request->user()->current_team_id;
        $payload = $this->validateTrip($request, $teamId);
        $vehicle = $this->findTeamVehicle($teamId, (int) $payload['vehicle_id']);

        Trip::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
        ]);

        $this->bumpVehicleOdometer($vehicle, (float) $payload['distance_km']);

        return to_route('vehicles.trips.index')
            ->with('success', __('Trip logged.'));
    }

    public function edit(Request $request, Trip $trip): Response
    {
        $this->authorizeTeam('vehicles.manage', $request);
        abort_unless($trip->team_id === $request->user()->current_team_id, 403);

        $teamId = (int) $trip->team_id;
        $trip->load('vehicle:id,name,registration_number,current_odometer_km');

        $estimates = $this->estimatesForPage($teamId, collect([$trip]));

        return Inertia::render('Vehicles/Trips/Form', [
            'isEditing' => true,
            'trip' => $this->serializeTrip(
                $trip,
                includeVehicleId: true,
                estimates: $estimates[(int) $trip->vehicle_id][(int) $trip->id] ?? null,
            ),
            'vehicles' => $this->vehicleOptions($teamId),
            'prefill' => null,
        ]);
    }

    public function update(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);
        abort_unless($trip->team_id === $request->user()->current_team_id, 403);

        $teamId = (int) $trip->team_id;
        $payload = $this->validateTrip($request, $teamId);
        $vehicle = $this->findTeamVehicle($teamId, (int) $payload['vehicle_id']);
        $previousDistance = (float) $trip->distance_km;
        $previousVehicleId = (int) $trip->vehicle_id;

        $trip->update($payload);

        $delta = (float) $payload['distance_km'] - $previousDistance;
        if ($previousVehicleId === (int) $vehicle->id) {
            $this->bumpVehicleOdometer($vehicle, $delta);
        } else {
            $previousVehicle = $this->findTeamVehicle($teamId, $previousVehicleId);
            $this->bumpVehicleOdometer($previousVehicle, -$previousDistance);
            $this->bumpVehicleOdometer($vehicle, (float) $payload['distance_km']);
        }

        return to_route('vehicles.trips.index')
            ->with('success', __('Trip updated.'));
    }

    public function destroy(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorizeTeam('vehicles.delete', $request);
        abort_unless($trip->team_id === $request->user()->current_team_id, 403);

        $vehicle = $this->findTeamVehicle((int) $trip->team_id, (int) $trip->vehicle_id);
        $distance = (float) $trip->distance_km;
        $trip->delete();
        $this->bumpVehicleOdometer($vehicle, -$distance);

        return back()->with('success', __('Trip deleted.'));
    }

    /**
     * @return array{search: string, purpose: string, vehicle_id: int, from: string, to: string}
     */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'search' => trim((string) $request->string('search')->toString()),
            'purpose' => (string) $request->string('purpose')->toString(),
            'vehicle_id' => (int) $request->integer('vehicle_id'),
            'from' => (string) $request->string('from')->toString(),
            'to' => (string) $request->string('to')->toString(),
        ];
    }

    /**
     * @param  array{search: string, purpose: string, vehicle_id: int, from: string, to: string}  $filters
     * @return Builder<Trip>
     */
    private function filteredTripsQuery(int $teamId, array $filters)
    {
        $query = Trip::queryWithoutTeamScope()->where('team_id', $teamId);

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('from_location', 'like', '%'.$search.'%')
                    ->orWhere('to_location', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%');
            });
        }

        if (in_array($filters['purpose'], [TripPurpose::Business->value, TripPurpose::Private->value], true)) {
            $query->where('purpose', $filters['purpose']);
        }

        if ($filters['vehicle_id'] > 0) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if ($filters['from'] !== '') {
            $query->whereDate('trip_date', '>=', $filters['from']);
        }

        if ($filters['to'] !== '') {
            $query->whereDate('trip_date', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Trip>  $pageTrips
     * @return array<int, array<int, array{opening_km: float|null, closing_km: float|null}>>
     */
    private function estimatesForPage(int $teamId, Collection $pageTrips): array
    {
        $vehicleIds = $pageTrips->pluck('vehicle_id')->unique()->filter()->map(fn ($id) => (int) $id)->all();
        if ($vehicleIds === []) {
            return [];
        }

        $vehicles = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereIn('id', $vehicleIds)
            ->get()
            ->keyBy('id');

        $allTrips = Trip::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereIn('vehicle_id', $vehicleIds)
            ->get()
            ->groupBy('vehicle_id');

        $byVehicle = [];
        foreach ($vehicleIds as $vehicleId) {
            $vehicle = $vehicles->get($vehicleId);
            if ($vehicle === null) {
                continue;
            }

            $chrono = $this->odometerEstimator->chronological($allTrips->get($vehicleId, collect()));
            $byVehicle[$vehicleId] = $this->odometerEstimator->estimate($vehicle, $chrono);
        }

        return $byVehicle;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTrip(Request $request, int $teamId): array
    {
        $validated = $request->validate([
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('team_id', $teamId),
            ],
            'trip_date' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'distance_km' => ['required', 'numeric', 'min:0', 'max:999999'],
            'purpose' => ['required', Rule::enum(TripPurpose::class)],
            'from_location' => ['nullable', 'string', 'max:255'],
            'to_location' => ['nullable', 'string', 'max:255'],
            'start_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'start_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'end_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'end_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
        ]);

        $startedAt = ! empty($validated['started_at']) ? Carbon::parse($validated['started_at']) : null;
        $endedAt = ! empty($validated['ended_at']) ? Carbon::parse($validated['ended_at']) : null;

        if ($startedAt === null && empty($validated['trip_date'])) {
            throw ValidationException::withMessages([
                'trip_date' => __('Provide a trip date or start time.'),
            ]);
        }

        $tripDate = ! empty($validated['trip_date'])
            ? Carbon::parse($validated['trip_date'])->toDateString()
            : $startedAt?->toDateString();

        $durationSeconds = isset($validated['duration_seconds']) && $validated['duration_seconds'] !== null
            ? (int) $validated['duration_seconds']
            : null;

        if ($durationSeconds === null && $startedAt !== null && $endedAt !== null) {
            $durationSeconds = max(0, (int) $startedAt->diffInSeconds($endedAt));
        }

        $validated['trip_date'] = $tripDate;
        $validated['started_at'] = $startedAt;
        $validated['ended_at'] = $endedAt;
        $validated['duration_seconds'] = $durationSeconds;
        $validated['distance_km'] = round((float) $validated['distance_km'], 1);
        $validated['start_odometer_km'] = null;
        $validated['end_odometer_km'] = null;

        foreach (['start_latitude', 'start_longitude', 'end_latitude', 'end_longitude'] as $coord) {
            if (array_key_exists($coord, $validated) && $validated[$coord] !== null) {
                $validated[$coord] = round((float) $validated[$coord], 7);
            }
        }

        if ($validated['purpose'] instanceof TripPurpose) {
            $validated['purpose'] = $validated['purpose']->value;
        }

        return $validated;
    }

    private function bumpVehicleOdometer(Vehicle $vehicle, float $deltaKm): void
    {
        if ($vehicle->current_odometer_km === null || abs($deltaKm) < 0.0001) {
            return;
        }

        $vehicle->forceFill([
            'current_odometer_km' => round(max(0, (float) $vehicle->current_odometer_km + $deltaKm), 1),
        ])->save();
    }

    private function findTeamVehicle(int $teamId, int $vehicleId): Vehicle
    {
        $vehicle = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereKey($vehicleId)
            ->first();

        abort_if($vehicle === null, 404);

        return $vehicle;
    }

    /**
     * @return list<array{id: int, name: string, registration_number: string|null, current_odometer_km: float|null, is_active: bool}>
     */
    private function vehicleOptions(int $teamId, bool $activeOnly = false): array
    {
        $query = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query
            ->get(['id', 'name', 'registration_number', 'current_odometer_km', 'is_active'])
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'registration_number' => $vehicle->registration_number,
                'current_odometer_km' => $vehicle->current_odometer_km !== null
                    ? (float) $vehicle->current_odometer_km
                    : null,
                'is_active' => (bool) $vehicle->is_active,
            ])
            ->all();
    }

    /**
     * @param  array{opening_km: float|null, closing_km: float|null}|null  $estimates
     * @return array<string, mixed>
     */
    private function serializeTrip(Trip $trip, bool $includeVehicleId = false, ?array $estimates = null): array
    {
        $row = [
            'id' => $trip->id,
            'trip_date' => optional($trip->trip_date)->toDateString(),
            'started_at' => optional($trip->started_at)?->format('Y-m-d\TH:i'),
            'ended_at' => optional($trip->ended_at)?->format('Y-m-d\TH:i'),
            'duration_seconds' => $trip->duration_seconds,
            'distance_km' => (float) $trip->distance_km,
            'purpose' => $trip->purpose->value,
            'estimated_opening_km' => $estimates['opening_km'] ?? null,
            'estimated_closing_km' => $estimates['closing_km'] ?? null,
            'from_location' => $trip->from_location,
            'to_location' => $trip->to_location,
            'start_latitude' => $trip->start_latitude !== null ? (float) $trip->start_latitude : null,
            'start_longitude' => $trip->start_longitude !== null ? (float) $trip->start_longitude : null,
            'end_latitude' => $trip->end_latitude !== null ? (float) $trip->end_latitude : null,
            'end_longitude' => $trip->end_longitude !== null ? (float) $trip->end_longitude : null,
            'notes' => $trip->notes,
            'vehicle' => $trip->relationLoaded('vehicle') && $trip->vehicle
                ? [
                    'id' => $trip->vehicle->id,
                    'name' => $trip->vehicle->name,
                    'registration_number' => $trip->vehicle->registration_number,
                ]
                : null,
        ];

        if ($includeVehicleId) {
            $row['vehicle_id'] = $trip->vehicle_id;
        }

        return $row;
    }
}
