<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Instance\Services\InstanceOperatorService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InstanceSettingsController extends Controller
{
    public function __construct(
        private readonly InstanceOperatorService $operators,
    ) {}

    public function edit(Request $request): Response
    {
        Gate::authorize('manageInstanceBackups');

        return Inertia::render('Settings/Instance', [
            'operators' => $this->operators->listEffectiveOperators(),
            'backup_schedule_hint' => 'Instance backups run daily at 03:00 (cleanup at 03:30). Manage runs under Backups & exports.',
            'env_break_glass_configured' => $this->operators->envOperatorEmails() !== [],
        ]);
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
            ->route('settings.instance')
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
            ->route('settings.instance')
            ->with('success', "Removed {$user->email} as an instance operator.");
    }
}
