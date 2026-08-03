<?php

namespace Tests\Feature\Vehicles;

use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTripTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_vehicle_crud_and_show_stats(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $this->get(route('vehicles.index'))->assertOk();
        $this->get(route('vehicles.create'))->assertOk();

        $this->post(route('vehicles.store'), [
            'name' => '',
            'make' => null,
            'model' => null,
            'year' => null,
            'registration_number' => '',
            'vin' => null,
            'starting_odometer_km' => null,
            'notes' => null,
            'is_active' => true,
        ])->assertSessionHasErrors(['name', 'registration_number']);

        $this->post(route('vehicles.store'), [
            'name' => 'Work bakkie',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'year' => 2020,
            'registration_number' => 'ABC 123 GP',
            'vin' => 'AHTJB3DD804533836',
            'starting_odometer_km' => 45000,
            'notes' => null,
            'is_active' => true,
        ])->assertRedirect();

        $vehicle = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('name', 'Work bakkie')
            ->first();
        $this->assertNotNull($vehicle);
        $this->assertSame('AHTJB3DD804533836', $vehicle->vin);

        Trip::factory()->forVehicle($vehicle)->business()->create([
            'trip_date' => now()->toDateString(),
            'distance_km' => 42.5,
        ]);
        Trip::factory()->forVehicle($vehicle)->private()->create([
            'trip_date' => now()->toDateString(),
            'distance_km' => 10,
        ]);

        $this->get(route('vehicles.show', $vehicle))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vehicles/Show')
                ->where('stats.business_km_ytd', 42.5)
                ->where('stats.private_km_ytd', 10)
                ->where('stats.trip_count', 2));

        $this->put(route('vehicles.update', $vehicle), [
            'name' => 'Work Hilux',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'year' => 2020,
            'registration_number' => 'ABC 123 GP',
            'vin' => 'AHTJB3DD804533836',
            'starting_odometer_km' => 45052.5,
            'notes' => 'Primary vehicle',
            'is_active' => true,
        ])->assertRedirect(route('vehicles.show', $vehicle));

        $this->assertSame('Work Hilux', $vehicle->fresh()->name);

        $this->delete(route('vehicles.destroy', $vehicle))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(Vehicle::queryWithoutTeamScope()->find($vehicle->id));
    }

    public function test_trip_log_create_update_delete_estimates_odometer_and_exports(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $vehicle = Vehicle::factory()->for($team)->create([
            'starting_odometer_km' => 10000,
        ]);

        $this->get(route('vehicles.trips.index'))->assertOk();
        $this->get(route('vehicles.trips.create'))->assertOk();

        $this->post(route('vehicles.trips.store'), [
            'vehicle_id' => $vehicle->id,
            'trip_date' => now()->toDateString(),
            'purpose' => TripPurpose::Business->value,
            'distance_km' => null,
            'from_location' => 'Home',
            'to_location' => 'Client site',
        ])->assertSessionHasErrors(['distance_km']);

        $this->post(route('vehicles.trips.store'), [
            'vehicle_id' => $vehicle->id,
            'trip_date' => now()->toDateString(),
            'started_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ended_at' => now()->format('Y-m-d H:i:s'),
            'distance_km' => 25,
            'purpose' => TripPurpose::Business->value,
            'from_location' => 'Home',
            'to_location' => 'Client site',
            'start_latitude' => -34.19076,
            'start_longitude' => 22.11538,
            'end_latitude' => -34.18434,
            'end_longitude' => 22.11477,
            'notes' => 'Site visit',
        ])->assertRedirect(route('vehicles.trips.index'));

        $trip = Trip::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('vehicle_id', $vehicle->id)
            ->first();
        $this->assertNotNull($trip);
        $this->assertSame(25.0, (float) $trip->distance_km);
        $this->assertNull($trip->start_odometer_km);
        $this->assertSame(10000.0, (float) $vehicle->fresh()->starting_odometer_km);

        $this->get(route('vehicles.trips.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vehicles/Trips/Index')
                ->where('trips.data.0.estimated_opening_km', 10000)
                ->where('trips.data.0.estimated_closing_km', 10025));

        $this->put(route('vehicles.trips.update', $trip), [
            'vehicle_id' => $vehicle->id,
            'trip_date' => now()->toDateString(),
            'distance_km' => 40,
            'purpose' => TripPurpose::Private->value,
            'from_location' => 'Home',
            'to_location' => 'Shop',
            'notes' => null,
        ])->assertRedirect(route('vehicles.trips.index'));

        $this->assertSame(TripPurpose::Private, $trip->fresh()->purpose);
        $this->assertSame(40.0, (float) $trip->fresh()->distance_km);
        $this->assertSame(10000.0, (float) $vehicle->fresh()->starting_odometer_km);

        $this->post(route('vehicles.trips.toggle-purpose', $trip))
            ->assertRedirect();
        $this->assertSame(TripPurpose::Business, $trip->fresh()->purpose);

        $this->post(route('vehicles.trips.toggle-purpose', $trip))
            ->assertRedirect();
        $this->assertSame(TripPurpose::Private, $trip->fresh()->purpose);

        $export = $this->get(route('vehicles.trips.export', [
            'vehicle_id' => $vehicle->id,
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]));
        $export->assertOk();
        $this->assertStringContainsString('Opening odometer (km)', $export->streamedContent());
        $this->assertStringContainsString('10000.0', $export->streamedContent());
        $this->assertStringContainsString('10040.0', $export->streamedContent());

        $this->delete(route('vehicles.trips.destroy', $trip))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(Trip::queryWithoutTeamScope()->find($trip->id));
        $this->assertSame(10000.0, (float) $vehicle->fresh()->starting_odometer_km);
    }

    public function test_other_team_cannot_view_vehicle_or_trip(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $this->assertNotNull($owner->currentTeam);
        $this->assertNotNull($other->currentTeam);

        $vehicle = Vehicle::factory()->for($owner->currentTeam)->create();
        $trip = Trip::factory()->forVehicle($vehicle)->create();

        $this->actingTeamContext($other, $other->currentTeam);

        $this->get(route('vehicles.show', $vehicle))->assertNotFound();
        $this->get(route('vehicles.trips.edit', $trip))->assertNotFound();
    }

    public function test_vehicle_can_be_deleted_without_trips(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $vehicle = Vehicle::factory()->for($team)->create();

        $this->delete(route('vehicles.destroy', $vehicle))
            ->assertRedirect(route('vehicles.index'));

        $this->assertNull(Vehicle::queryWithoutTeamScope()->find($vehicle->id));
    }
}
