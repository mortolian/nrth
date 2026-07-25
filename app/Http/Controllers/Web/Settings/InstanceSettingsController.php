<?php

namespace App\Http\Controllers\Web\Settings;

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
    ) {}

    public function edit(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        return redirect()->route('backups-exports.index', ['section' => 'backup']);
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
