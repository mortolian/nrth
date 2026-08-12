<?php

namespace Tests\Feature\Vehicles;

use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Services\TripLogbookPdfService;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehicleTripTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $this->enableTeamModules($team);
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

    public function test_trip_log_create_update_delete_and_exports(): void
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
                ->where('trips.data.0.distance_km', 25)
                ->where('trips.data.0.purpose', TripPurpose::Business->value));

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
        $this->assertStringContainsString('Distance (km)', $export->streamedContent());
        $this->assertStringContainsString('40.0', $export->streamedContent());
        $this->assertStringNotContainsString('Opening odometer', $export->streamedContent());

        $pdf = $this->get(route('vehicles.trips.export-pdf', [
            'vehicle_id' => $vehicle->id,
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertGreaterThan(1000, strlen($pdf->getContent()));

        $this->from(route('vehicles.trips.index'))
            ->get(route('vehicles.trips.export-pdf', ['vehicle_id' => $vehicle->id]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->delete(route('vehicles.trips.destroy', $trip))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(Trip::queryWithoutTeamScope()->find($trip->id));
        $this->assertSame(10000.0, (float) $vehicle->fresh()->starting_odometer_km);
    }

    public function test_trip_index_search_matches_route_locations_case_insensitively(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $vehicle = Vehicle::factory()->for($team)->create();
        $match = Trip::factory()->forVehicle($vehicle)->create([
            'from_location' => 'Cape Town CBD',
            'to_location' => 'Stellenbosch Office',
            'notes' => 'Quarterly review',
        ]);
        Trip::factory()->forVehicle($vehicle)->create([
            'from_location' => 'Home',
            'to_location' => 'Gym',
            'notes' => null,
        ]);

        $this->get(route('vehicles.trips.index', ['search' => 'stellenbosch']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vehicles/Trips/Index')
                ->has('trips.data', 1)
                ->where('trips.data.0.id', $match->id));

        $this->get(route('vehicles.trips.index', ['search' => 'Cape Town CBD → Stellenbosch Office']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('trips.data', 1)
                ->where('trips.data.0.id', $match->id));

        $this->get(route('vehicles.trips.index', ['search' => 'quarterly']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('trips.data', 1)
                ->where('trips.data.0.id', $match->id));
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

    public function test_bulk_delete_removes_selected_team_trips_only(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $other = User::factory()->withPersonalTeam()->create();
        $this->assertNotNull($other->currentTeam);

        $vehicle = Vehicle::factory()->for($team)->create();
        $keep = Trip::factory()->forVehicle($vehicle)->create();
        $removeA = Trip::factory()->forVehicle($vehicle)->create();
        $removeB = Trip::factory()->forVehicle($vehicle)->create();

        $otherVehicle = Vehicle::factory()->for($other->currentTeam)->create();
        $foreign = Trip::factory()->forVehicle($otherVehicle)->create();

        $this->delete(route('vehicles.trips.bulk-destroy'), [
            'ids' => [$removeA->id, $removeB->id, $foreign->id],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull(Trip::queryWithoutTeamScope()->find($keep->id));
        $this->assertNull(Trip::queryWithoutTeamScope()->find($removeA->id));
        $this->assertNull(Trip::queryWithoutTeamScope()->find($removeB->id));
        $this->assertNotNull(Trip::queryWithoutTeamScope()->find($foreign->id));
    }

    public function test_viewer_cannot_bulk_delete_trips(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);
        EnsureTeamSystemRoles::ensureFor($team);
        $this->enableTeamModules($team);

        $vehicle = Vehicle::factory()->for($team)->create();
        $trip = Trip::factory()->forVehicle($vehicle)->create();

        $viewer = User::factory()->create();
        $team->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($viewer);

        $this->delete(route('vehicles.trips.bulk-destroy'), [
            'ids' => [$trip->id],
        ])->assertForbidden();

        $this->assertNotNull(Trip::queryWithoutTeamScope()->find($trip->id));
    }

    public function test_pdf_export_rejects_when_too_many_trips(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $vehicle = Vehicle::factory()->for($team)->create([
            'starting_odometer_km' => 1000,
        ]);

        $from = now()->subDays(10)->toDateString();
        $to = now()->toDateString();
        $limit = TripLogbookPdfService::MAX_TRIPS;

        $rows = [];
        for ($i = 0; $i < $limit + 1; $i++) {
            $rows[] = [
                'team_id' => $team->id,
                'vehicle_id' => $vehicle->id,
                'trip_date' => $from,
                'started_at' => null,
                'ended_at' => null,
                'duration_seconds' => null,
                'distance_km' => 1.0,
                'purpose' => TripPurpose::Business->value,
                'start_odometer_km' => null,
                'end_odometer_km' => null,
                'from_location' => 'A',
                'to_location' => 'B',
                'start_latitude' => null,
                'start_longitude' => null,
                'end_latitude' => null,
                'end_longitude' => null,
                'notes' => null,
                'trip_import_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('trips')->insert($chunk);
        }

        $this->from(route('vehicles.trips.index', [
            'vehicle_id' => $vehicle->id,
            'from' => $from,
            'to' => $to,
        ]))
            ->get(route('vehicles.trips.export-pdf', [
                'vehicle_id' => $vehicle->id,
                'from' => $from,
                'to' => $to,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
