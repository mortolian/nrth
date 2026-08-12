<?php

namespace Tests\Feature\Vehicles;

use App\Domain\Vehicles\Enums\TripImportStatus;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\TripImport;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team, 2: Vehicle}
     */
    private function actingTeamWithVehicle(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->enableTeamModules($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $vehicle = Vehicle::factory()->for($team)->create([
            'name' => 'Fleet Corolla',
            'registration_number' => 'CA 123 GP',
            'vin' => 'JTDBR32E720123456',
            'starting_odometer_km' => 10000,
            'is_active' => true,
        ]);

        return [$user, $team, $vehicle];
    }

    private function configureAi(Team $team): void
    {
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'ai' => [
                        'enabled' => true,
                        'provider' => 'openai',
                        'api_key' => 'sk-test',
                        'model' => 'gpt-4o-mini',
                        'base_url' => null,
                    ],
                ]
            ),
        ])->save();
    }

    private function toyotaCsv(): string
    {
        return implode("\n", [
            'Name,Driver,Vehicle Description,Toyota Corolla,Vehicle Reg,CA 123 GP,VIN,JTDBR32E720123456',
            'Distance,Start Address,End Address,Start Latitude and Longitude,End Latitude and Longitude,Start Date,End Date,Time Passed,Trip Type',
            '5.0,Home,Fuel stop,"-33.9, 18.4","-33.91, 18.41",2026-08-01 08:00:00,2026-08-01 08:15:00,0:15:00,Business',
            '10.0,Fuel stop,Office,"-33.91, 18.41","-33.92, 18.42",2026-08-01 08:25:00,2026-08-01 08:50:00,0:25:00,Business',
            '20.0,Office,Home,"-33.92, 18.42","-33.9, 18.4",2026-08-01 17:00:00,2026-08-01 17:40:00,0:40:00,Personal',
        ]);
    }

    public function test_import_create_requires_ai(): void
    {
        $this->actingTeamWithVehicle();

        $this->get(route('vehicles.trips.import.create'))->assertNotFound();
    }

    public function test_import_parses_telematics_csv_merges_and_skips_duplicates(): void
    {
        [, $team, $vehicle] = $this->actingTeamWithVehicle();
        $this->configureAi($team);

        Trip::factory()->for($team)->for($vehicle)->create([
            'trip_date' => '2026-08-01',
            'started_at' => '2026-08-01 17:00:00',
            'ended_at' => '2026-08-01 17:40:00',
            'distance_km' => 20.0,
            'purpose' => 'private',
            'from_location' => 'Office',
            'to_location' => 'Home',
        ]);

        $file = UploadedFile::fake()->createWithContent('LogBook.csv', $this->toyotaCsv());

        $this->get(route('vehicles.trips.import.create'))->assertOk();

        $this->post(route('vehicles.trips.import.store'), [
            'vehicle_id' => $vehicle->id,
            'file' => $file,
        ])->assertRedirect(route('vehicles.trips.import.preview'));

        $preview = $this->get(route('vehicles.trips.import.preview'));
        $preview->assertOk();
        $preview->assertInertia(fn ($page) => $page
            ->component('Vehicles/Trips/Import/Preview')
            ->where('draft.summary.total', 2)
            ->where('draft.summary.duplicates', 1)
            ->where('draft.summary.new', 1)
            ->where('draft.parser', 'telematics')
            ->has('draft.trips', 2)
        );

        $draft = session('trip_log_import');
        $this->assertIsArray($draft);
        $newKeys = collect($draft['trips'])
            ->filter(fn (array $trip) => ! ($trip['is_duplicate'] ?? false))
            ->pluck('key')
            ->all();
        $this->assertCount(1, $newKeys);

        $this->post(route('vehicles.trips.import.confirm'), [
            'vehicle_id' => $vehicle->id,
            'keys' => $newKeys,
        ])->assertRedirect(route('vehicles.trips.index'));

        $this->assertDatabaseCount('trips', 2);
        $imported = Trip::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('from_location', 'Home')
            ->where('to_location', 'Office')
            ->first();
        $this->assertNotNull($imported);
        $this->assertSame(15.0, (float) $imported->distance_km);
        $this->assertStringContainsString('2', (string) $imported->notes);
        $this->assertNotNull($imported->trip_import_id);

        $import = TripImport::queryWithoutTeamScope()->find($imported->trip_import_id);
        $this->assertNotNull($import);
        $this->assertSame(TripImportStatus::Imported, $import->status);
        $this->assertSame(1, $import->imported_rows);
        $this->assertSame('telematics', $import->parser);

        $vehicle->refresh();
        $this->assertSame(10000.0, (float) $vehicle->starting_odometer_km);
    }

    public function test_import_can_be_undone(): void
    {
        [, $team, $vehicle] = $this->actingTeamWithVehicle();
        $this->configureAi($team);

        $file = UploadedFile::fake()->createWithContent('LogBook.csv', $this->toyotaCsv());

        $this->post(route('vehicles.trips.import.store'), [
            'vehicle_id' => $vehicle->id,
            'file' => $file,
        ])->assertRedirect(route('vehicles.trips.import.preview'));

        $draft = session('trip_log_import');
        $keys = collect($draft['trips'])->pluck('key')->all();

        $this->post(route('vehicles.trips.import.confirm'), [
            'vehicle_id' => $vehicle->id,
            'keys' => $keys,
        ])->assertRedirect(route('vehicles.trips.index'));

        $import = TripImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->first();
        $this->assertNotNull($import);
        $this->assertSame(2, Trip::queryWithoutTeamScope()->where('trip_import_id', $import->id)->count());

        $this->get(route('vehicles.trips.imports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vehicles/Trips/Import/History')
                ->has('imports.data', 1)
                ->where('imports.data.0.can_undo', true));

        $this->post(route('vehicles.trips.imports.undo', $import))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, Trip::queryWithoutTeamScope()->where('trip_import_id', $import->id)->count());
        $import->refresh();
        $this->assertSame(TripImportStatus::Undone, $import->status);
        $this->assertSame(0, $import->imported_rows);
        $this->assertNotNull($import->metadata['undone_at'] ?? null);

        $this->post(route('vehicles.trips.imports.undo', $import))
            ->assertSessionHasErrors('import');
    }

    public function test_viewer_cannot_undo_import(): void
    {
        [, $team, $vehicle] = $this->actingTeamWithVehicle();
        $this->configureAi($team);

        $file = UploadedFile::fake()->createWithContent('LogBook.csv', $this->toyotaCsv());
        $this->post(route('vehicles.trips.import.store'), [
            'vehicle_id' => $vehicle->id,
            'file' => $file,
        ]);
        $keys = collect(session('trip_log_import')['trips'])->pluck('key')->all();
        $this->post(route('vehicles.trips.import.confirm'), [
            'vehicle_id' => $vehicle->id,
            'keys' => $keys,
        ]);

        $import = TripImport::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->firstOrFail();

        EnsureTeamSystemRoles::ensureFor($team);
        $viewer = User::factory()->create();
        $team->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($viewer);

        $this->post(route('vehicles.trips.imports.undo', $import))->assertForbidden();

        $this->assertSame(TripImportStatus::Imported, $import->fresh()->status);
        $this->assertGreaterThan(0, Trip::queryWithoutTeamScope()->where('trip_import_id', $import->id)->count());
    }

    public function test_import_uses_ai_when_columns_are_unknown(): void
    {
        [, $team, $vehicle] = $this->actingTeamWithVehicle();
        $this->configureAi($team);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'vehicle_registration' => 'CA 123 GP',
                            'vehicle_vin' => null,
                            'source_segments_count' => 2,
                            'trips' => [
                                [
                                    'trip_date' => '2026-08-02',
                                    'started_at' => '2026-08-02 09:00:00',
                                    'ended_at' => '2026-08-02 09:30:00',
                                    'distance_km' => 8.5,
                                    'purpose' => 'business',
                                    'from_location' => 'A',
                                    'to_location' => 'B',
                                    'segments_merged' => 2,
                                ],
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $csv = "ColA,ColB,ColC\nfoo,bar,baz\n";
        $file = UploadedFile::fake()->createWithContent('mystery.csv', $csv);

        $this->post(route('vehicles.trips.import.store'), [
            'vehicle_id' => $vehicle->id,
            'file' => $file,
        ])->assertRedirect(route('vehicles.trips.import.preview'));

        $draft = session('trip_log_import');
        $this->assertSame('ai', $draft['parser'] ?? null);
        $this->assertCount(1, $draft['trips'] ?? []);
        $this->assertSame(8.5, (float) $draft['trips'][0]['distance_km']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions');
    }
}
