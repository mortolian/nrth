<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InstanceTeamsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manageInstanceBackups');

        $teams = Team::query()
            ->with(['owner:id,name,email'])
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'personal_team' => (bool) $team->personal_team,
                'owner_name' => $team->owner?->name,
                'owner_email' => $team->owner?->email,
                'members_count' => (int) $team->users_count + ($team->owner !== null ? 1 : 0),
                'manage_url' => route('settings.instance.teams.show', $team),
            ]);

        return Inertia::render('Settings/Instance/Teams/Index', [
            'teams' => $teams,
        ]);
    }

    public function show(Request $request, Team $team): Response
    {
        Gate::authorize('manageInstanceBackups');

        return app(TeamSettingsController::class)->show($request, $team, 'instance');
    }
}
