<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Instance\Services\InstanceOperatorService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InstanceSettingsController extends Controller
{
    public function __construct(
        private readonly InstanceOperatorService $operators,
        private readonly InstanceBackupRetentionSettings $backupRetention,
    ) {}

    public function edit(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        return redirect()->route('backups-exports.index', ['section' => 'backup']);
    }

    public function updateBackupRetention(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'keep_all_backups_for_days' => ['required', 'integer', 'min:1', 'max:90'],
            'keep_daily_backups_for_days' => ['required', 'integer', 'min:0', 'max:90'],
            'keep_weekly_backups_for_weeks' => ['required', 'integer', 'min:0', 'max:104'],
            'keep_monthly_backups_for_months' => ['required', 'integer', 'min:0', 'max:60'],
            'keep_yearly_backups_for_years' => ['required', 'integer', 'min:0', 'max:20'],
            'delete_oldest_backups_when_using_more_megabytes_than' => [
                'nullable',
                'integer',
                'min:100',
                'max:200000',
            ],
        ]);

        $this->backupRetention->update($validated);

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'action' => 'instance_backup_retention_updated',
                'settings' => $validated,
            ])
            ->log('Updated instance backup retention');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', 'Backup retention settings saved.');
    }

    public function addOperator(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $this->operators->addByEmail($validated['email']);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['action' => 'instance_operator_added', 'email' => $user->email])
            ->log('Added instance operator');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', "Added {$user->email} as an instance operator.");
    }

    public function removeOperator(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $this->operators->remove($request->user(), $user);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['action' => 'instance_operator_removed', 'email' => $user->email])
            ->log('Removed instance operator');

        return redirect()
            ->route('backups-exports.index', ['section' => 'backup'])
            ->with('success', "Removed {$user->email} as an instance operator.");
    }
}
