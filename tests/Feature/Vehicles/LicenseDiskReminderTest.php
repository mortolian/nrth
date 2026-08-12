<?php

namespace Tests\Feature\Vehicles;

use App\Domain\Vehicles\Actions\SendLicenseDiskRemindersAction;
use App\Domain\Vehicles\Models\Vehicle;
use App\Mail\LicenseDiskReminderMailer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LicenseDiskReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_can_store_and_update_license_disk_expiry(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->enableTeamModules($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $this->post(route('vehicles.store'), [
            'name' => 'Work bakkie',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'year' => 2020,
            'registration_number' => 'ABC 123 GP',
            'vin' => null,
            'license_disk_expires_on' => '2026-09-15',
            'starting_odometer_km' => null,
            'notes' => null,
            'is_active' => true,
        ])->assertRedirect();

        $vehicle = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('name', 'Work bakkie')
            ->first();
        $this->assertNotNull($vehicle);
        $this->assertSame('2026-09-15', optional($vehicle->license_disk_expires_on)?->toDateString());

        $this->get(route('vehicles.show', $vehicle))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vehicles/Show')
                ->where('vehicle.license_disk_expires_on', '2026-09-15'));

        $vehicle->forceFill(['license_disk_reminder_sent_for' => '2026-09-15'])->save();

        $this->put(route('vehicles.update', $vehicle), [
            'name' => 'Work bakkie',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'year' => 2020,
            'registration_number' => 'ABC 123 GP',
            'vin' => null,
            'license_disk_expires_on' => '2027-09-15',
            'starting_odometer_km' => null,
            'notes' => null,
            'is_active' => true,
        ])->assertRedirect(route('vehicles.show', $vehicle));

        $vehicle->refresh();
        $this->assertSame('2027-09-15', optional($vehicle->license_disk_expires_on)?->toDateString());
        $this->assertNull($vehicle->license_disk_reminder_sent_for);
    }

    public function test_command_sends_reminder_once_within_thirty_days(): void
    {
        Mail::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $dueSoon = Vehicle::factory()->for($team)->create([
            'is_active' => true,
            'license_disk_expires_on' => now()->addDays(30)->toDateString(),
            'license_disk_reminder_sent_for' => null,
        ]);
        $alreadyReminded = Vehicle::factory()->for($team)->create([
            'is_active' => true,
            'license_disk_expires_on' => now()->addDays(20)->toDateString(),
            'license_disk_reminder_sent_for' => now()->addDays(20)->toDateString(),
        ]);
        $tooFar = Vehicle::factory()->for($team)->create([
            'is_active' => true,
            'license_disk_expires_on' => now()->addDays(45)->toDateString(),
            'license_disk_reminder_sent_for' => null,
        ]);
        $inactive = Vehicle::factory()->for($team)->create([
            'is_active' => false,
            'license_disk_expires_on' => now()->addDays(10)->toDateString(),
            'license_disk_reminder_sent_for' => null,
        ]);

        $this->artisan('vehicles:send-license-disk-reminders')
            ->assertSuccessful();

        Mail::assertQueued(LicenseDiskReminderMailer::class, function (LicenseDiskReminderMailer $mail) use ($dueSoon): bool {
            return $mail->vehicle->is($dueSoon);
        });
        Mail::assertQueued(LicenseDiskReminderMailer::class, 1);

        $this->assertSame(
            optional($dueSoon->fresh()->license_disk_expires_on)?->toDateString(),
            optional($dueSoon->fresh()->license_disk_reminder_sent_for)?->toDateString(),
        );
        $this->assertNull($tooFar->fresh()->license_disk_reminder_sent_for);
        $this->assertNull($inactive->fresh()->license_disk_reminder_sent_for);
        $this->assertSame(
            optional($alreadyReminded->license_disk_expires_on)?->toDateString(),
            optional($alreadyReminded->fresh()->license_disk_reminder_sent_for)?->toDateString(),
        );

        Mail::fake();
        $this->artisan('vehicles:send-license-disk-reminders')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_reminder_skips_users_who_disabled_preference(): void
    {
        Mail::fake();

        $user = User::factory()->withPersonalTeam()->create([
            'preferences' => array_merge(User::defaultPreferences(), [
                'notify_license_disk' => false,
            ]),
        ]);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        Vehicle::factory()->for($team)->create([
            'is_active' => true,
            'license_disk_expires_on' => now()->addDays(15)->toDateString(),
        ]);

        $result = app(SendLicenseDiskRemindersAction::class)->execute();

        $this->assertSame(0, $result['reminded']);
        $this->assertSame(0, $result['recipients']);
        Mail::assertNothingQueued();
    }

    public function test_reminder_does_not_notify_other_team_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $other = User::factory()->withPersonalTeam()->create();

        Vehicle::factory()->for($team)->create([
            'is_active' => true,
            'license_disk_expires_on' => now()->addDays(7)->toDateString(),
        ]);

        app(SendLicenseDiskRemindersAction::class)->execute();

        Mail::assertQueued(LicenseDiskReminderMailer::class, 1);
        Mail::assertQueued(LicenseDiskReminderMailer::class, function (LicenseDiskReminderMailer $mail) use ($owner, $other): bool {
            return $mail->hasTo($owner->email) && ! $mail->hasTo($other->email);
        });
    }
}
