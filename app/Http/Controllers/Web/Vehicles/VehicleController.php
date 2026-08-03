<?php

namespace App\Http\Controllers\Web\Vehicles;

use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Services\TripOdometerEstimator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    public function __construct(
        private readonly TripOdometerEstimator $odometerEstimator,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeTeam('vehicles.view', $request);

        $teamId = (int) $request->user()->current_team_id;
        $search = trim((string) $request->string('search')->toString());
        $status = (string) $request->string('status')->toString();

        $query = Vehicle::queryWithoutTeamScope()->where('team_id', $teamId);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('registration_number', 'like', '%'.$search.'%')
                    ->orWhere('vin', 'like', '%'.$search.'%')
                    ->orWhere('make', 'like', '%'.$search.'%')
                    ->orWhere('model', 'like', '%'.$search.'%');
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $vehicles = $query
            ->withCount('trips')
            ->withMax('trips', 'trip_date')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'registration_number' => $vehicle->registration_number,
                'current_odometer_km' => $vehicle->current_odometer_km !== null
                    ? (float) $vehicle->current_odometer_km
                    : null,
                'status' => $vehicle->is_active ? 'active' : 'inactive',
                'trip_count' => (int) $vehicle->trips_count,
                'last_trip_date' => $vehicle->trips_max_trip_date
                    ? (string) $vehicle->trips_max_trip_date
                    : null,
            ]);

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles,
            'filters' => [
                'search' => $search ?: null,
                'status' => $status ?: 'all',
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('vehicles.manage', $request);

        return Inertia::render('Vehicles/Form', [
            'isEditing' => false,
            'vehicle' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);

        $payload = $this->validateVehicle($request);
        $teamId = (int) $request->user()->current_team_id;

        $vehicle = Vehicle::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
        ]);

        return to_route('vehicles.show', $vehicle)
            ->with('success', __('Vehicle created.'));
    }

    public function show(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeTeam('vehicles.view', $request);
        abort_unless($vehicle->team_id === $request->user()->current_team_id, 403);

        $teamId = (int) $vehicle->team_id;
        $yearStart = now()->startOfYear()->toDateString();

        $allTrips = Trip::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('vehicle_id', $vehicle->id)
            ->get();

        $estimates = $this->odometerEstimator->estimate(
            $vehicle,
            $this->odometerEstimator->chronological($allTrips),
        );

        $tripHistory = Trip::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('trip_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Trip $trip): array => $this->serializeTrip(
                $trip,
                $estimates[(int) $trip->id] ?? null,
            ));

        $ytdTrips = $allTrips->filter(
            fn (Trip $trip) => optional($trip->trip_date)?->toDateString() >= $yearStart
        );

        $businessKmYtd = (float) $ytdTrips
            ->filter(fn (Trip $trip) => $trip->purpose === TripPurpose::Business)
            ->sum(fn (Trip $trip) => (float) $trip->distance_km);

        $privateKmYtd = (float) $ytdTrips
            ->filter(fn (Trip $trip) => $trip->purpose === TripPurpose::Private)
            ->sum(fn (Trip $trip) => (float) $trip->distance_km);

        return Inertia::render('Vehicles/Show', [
            'vehicle' => $this->serializeVehicle($vehicle),
            'trip_history' => $tripHistory,
            'stats' => [
                'business_km_ytd' => round($businessKmYtd, 1),
                'private_km_ytd' => round($privateKmYtd, 1),
                'total_km_ytd' => round($businessKmYtd + $privateKmYtd, 1),
                'trip_count' => $allTrips->count(),
                'trip_count_ytd' => $ytdTrips->count(),
            ],
        ]);
    }

    public function edit(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeTeam('vehicles.manage', $request);
        abort_unless($vehicle->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Vehicles/Form', [
            'isEditing' => true,
            'vehicle' => $this->serializeVehicle($vehicle),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);
        abort_unless($vehicle->team_id === $request->user()->current_team_id, 403);

        $vehicle->update($this->validateVehicle($request));

        return to_route('vehicles.show', $vehicle)
            ->with('success', __('Vehicle updated.'));
    }

    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeTeam('vehicles.delete', $request);
        abort_unless($vehicle->team_id === $request->user()->current_team_id, 403);

        if (Trip::queryWithoutTeamScope()
            ->where('team_id', $vehicle->team_id)
            ->where('vehicle_id', $vehicle->id)
            ->exists()) {
            return back()->with('error', __('This vehicle has trip logs and cannot be deleted.'));
        }

        $vehicle->delete();

        return to_route('vehicles.index')
            ->with('success', __('Vehicle deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVehicle(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'registration_number' => ['required', 'string', 'max:50'],
            'vin' => ['nullable', 'string', 'max:32'],
            'current_odometer_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (array_key_exists('current_odometer_km', $validated) && $validated['current_odometer_km'] !== null) {
            $validated['current_odometer_km'] = round((float) $validated['current_odometer_km'], 1);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVehicle(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'name' => $vehicle->name,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'registration_number' => $vehicle->registration_number,
            'vin' => $vehicle->vin,
            'current_odometer_km' => $vehicle->current_odometer_km !== null
                ? (float) $vehicle->current_odometer_km
                : null,
            'notes' => $vehicle->notes,
            'is_active' => (bool) $vehicle->is_active,
        ];
    }

    /**
     * @param  array{opening_km: float|null, closing_km: float|null}|null  $estimates
     * @return array<string, mixed>
     */
    private function serializeTrip(Trip $trip, ?array $estimates = null): array
    {
        return [
            'id' => $trip->id,
            'trip_date' => optional($trip->trip_date)->toDateString(),
            'started_at' => optional($trip->started_at)?->format('Y-m-d H:i'),
            'ended_at' => optional($trip->ended_at)?->format('Y-m-d H:i'),
            'duration_seconds' => $trip->duration_seconds,
            'distance_km' => (float) $trip->distance_km,
            'purpose' => $trip->purpose->value,
            'estimated_opening_km' => $estimates['opening_km'] ?? null,
            'estimated_closing_km' => $estimates['closing_km'] ?? null,
            'from_location' => $trip->from_location,
            'to_location' => $trip->to_location,
            'notes' => $trip->notes,
        ];
    }
}
