<?php

namespace App\Http\Controllers\Web\Jetstream;

use App\Http\Controllers\Web\Settings\TeamSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Http\Controllers\Inertia\TeamController as JetstreamTeamController;
use Laravel\Jetstream\Jetstream;

class TeamController extends JetstreamTeamController
{
    /**
     * Show the unified team settings page (same Inertia page as /settings/team).
     */
    public function show(Request $request, $teamId)
    {
        $team = Jetstream::newTeamModel()->query()->findOrFail($teamId);
        $user = $request->user();
        $context = Gate::allows('manageInstanceBackups') && ! $user->belongsToTeam($team)
            ? 'instance'
            : 'business';

        return app(TeamSettingsController::class)->show($request, $team, $context);
    }
}
