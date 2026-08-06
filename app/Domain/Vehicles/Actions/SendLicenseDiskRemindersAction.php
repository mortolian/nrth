<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Vehicles\Models\Vehicle;
use App\Mail\LicenseDiskReminderMailer;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SendLicenseDiskRemindersAction
{
    /**
     * Remind team members about licence discs expiring within the next month.
     *
     * @return array{reminded: int, recipients: int}
     */
    public function execute(?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->startOfDay();
        $windowEnd = $today->copy()->addDays(30);

        $vehicles = Vehicle::queryWithoutTeamScope()
            ->with('team')
            ->where('is_active', true)
            ->whereNotNull('license_disk_expires_on')
            ->whereDate('license_disk_expires_on', '>=', $today->toDateString())
            ->whereDate('license_disk_expires_on', '<=', $windowEnd->toDateString())
            ->where(function ($query): void {
                $query->whereNull('license_disk_reminder_sent_for')
                    ->orWhereColumn('license_disk_reminder_sent_for', '!=', 'license_disk_expires_on');
            })
            ->get();

        $reminded = 0;
        $recipients = 0;

        foreach ($vehicles as $vehicle) {
            $team = $vehicle->team;
            if ($team === null) {
                continue;
            }

            $users = $this->recipientsFor($team);
            if ($users->isEmpty()) {
                continue;
            }

            foreach ($users as $user) {
                Mail::to($user->email)->queue(new LicenseDiskReminderMailer($vehicle));
                $recipients++;
            }

            $vehicle->forceFill([
                'license_disk_reminder_sent_for' => $vehicle->license_disk_expires_on?->toDateString(),
            ])->save();
            $reminded++;
        }

        return [
            'reminded' => $reminded,
            'recipients' => $recipients,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(Team $team): Collection
    {
        $team->loadMissing(['owner', 'users']);

        return collect([$team->owner])
            ->merge($team->users)
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->filter(function (User $user) use ($team): bool {
                if (! $user->canOnTeam('vehicles.view', $team)) {
                    return false;
                }

                return (bool) ($user->mergedPreferences()['notify_license_disk'] ?? true);
            })
            ->values();
    }
}
