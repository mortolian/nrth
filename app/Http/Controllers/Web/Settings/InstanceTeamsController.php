<?php

namespace App\Http\Controllers\Web\Settings;

use App\Actions\Jetstream\UpdateTeamMemberRole;
use App\Domain\Instance\Services\BusinessMembers;
use App\Domain\Instance\Services\BusinessOwners;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use App\Rules\TeamRoleKey;
use App\Support\MailDelivery;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\PermissionCatalog;
use App\Support\TeamAccess\RolePresets;
use App\Support\TeamAccess\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;

class InstanceTeamsController extends Controller
{
    public function __construct(
        private readonly BusinessOwners $businessOwners,
        private readonly BusinessMembers $businessMembers,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manageInstanceBackups');

        $teams = Team::query()
            ->with(['owner:id,name,email', 'users:id,name,email'])
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(function (Team $team): array {
                $owners = $this->businessOwners->forTeam($team);

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'personal_team' => (bool) $team->personal_team,
                    'owners_label' => $owners->pluck('name')->filter()->join(', '),
                    'owners_count' => $owners->count(),
                    'members_count' => (int) $team->users_count + ($team->owner !== null && ! $team->users->contains('id', $team->owner->id) ? 1 : 0),
                    'owners_url' => route('settings.instance.teams.show', $team),
                ];
            });

        return Inertia::render('Settings/Instance/Teams/Index', [
            'teams' => $teams,
        ]);
    }

    public function show(Request $request, Team $team): Response
    {
        Gate::authorize('manageInstanceBackups');
        EnsureTeamSystemRoles::ensureFor($team);

        $team->loadMissing(['owner', 'users', 'teamInvitations', 'teamRoles']);
        $rolesByKey = $team->teamRoles->keyBy('key');
        $owners = $this->businessOwners->forTeam($team);
        $ownerIds = $owners->pluck('id');

        $members = $team->users
            ->reject(fn (User $user): bool => $ownerIds->contains($user->id))
            ->map(function (User $user) use ($rolesByKey): array {
                $roleKey = $user->membership->role ?? RolePresets::VIEWER;
                $teamRole = $rolesByKey->get($roleKey);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_key' => $roleKey,
                    'role_label' => $teamRole?->name ?? ucfirst($roleKey),
                ];
            })
            ->values()
            ->all();

        $invitations = $team->teamInvitations->map(function ($invitation) use ($rolesByKey): array {
            $roleKey = (string) $invitation->role;

            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_key' => $roleKey,
                'role_label' => $rolesByKey->get($roleKey)?->name ?? $roleKey,
            ];
        })->values()->all();

        $roles = $team->teamRoles->map(function (TeamRole $role): array {
            $permissions = TeamAccess::effectivePermissions($role);
            $preset = RolePresets::systemRoles()[$role->key] ?? null;

            return [
                'id' => $role->id,
                'key' => $role->key,
                'name' => $preset['name'] ?? $role->name,
                'description' => $preset['description'] ?? $role->description,
                'is_system' => (bool) $role->is_system,
                'permissions' => $permissions,
                'permission_count' => count($permissions),
            ];
        })->values()->all();

        return Inertia::render('Settings/Instance/Teams/Manage', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'owners' => $owners->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'primary' => (int) $team->user_id === (int) $user->id,
            ])->values()->all(),
            'members' => $members,
            'invitations' => $invitations,
            'roles' => $roles,
            'accounts' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'permission_groups' => PermissionCatalog::groupsForUi(),
        ]);
    }

    public function members(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        return redirect()->route('settings.instance.teams.show', $team);
    }

    public function storeOwner(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $account = $this->selectedAccount($request);
        if ($account !== null) {
            $added = $this->businessOwners->add($team, $account->name, $account->email, null);
        } else {
            $validated = $this->validateOwner($request, null);
            $added = $this->businessOwners->add(
                $team,
                $validated['name'],
                $validated['email'],
                $validated['password'] ?? null,
            );
        }

        [$level, $message] = MailDelivery::ownerAssignedFlash($added->user->email, $added->noticeSent);

        return back()->with($level, $message);
    }

    public function updateOwner(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $this->validateOwner($request, $user);

        $this->businessOwners->update(
            $team,
            $user,
            $validated['name'],
            $validated['email'],
            $validated['password'] ?? null,
        );

        return back()->with('success', __('Owner saved.'));
    }

    public function destroyOwner(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $this->businessOwners->remove($team, $user);

        return back()->with('success', __('Owner removed from this business.'));
    }

    public function storeMember(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $account = $this->selectedAccount($request);
        if ($account !== null) {
            $validated = $request->validate([
                'role' => ['required', 'string', new TeamRoleKey($team)],
            ]);
            $this->businessMembers->add($team, $account->name, $account->email, null, $validated['role']);
        } else {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['nullable', 'string', Password::default(), 'confirmed'],
                'role' => ['required', 'string', new TeamRoleKey($team)],
            ]);
            $this->businessMembers->add(
                $team,
                $validated['name'],
                $validated['email'],
                $validated['password'] ?? null,
                $validated['role'],
            );
        }

        return back()->with('success', __('Member added. No invitation was sent.'));
    }

    public function updateMember(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        if (! $team->users()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'role' => __('That person is not a member of this business.'),
            ]);
        }

        app(UpdateTeamMemberRole::class)->update(
            $request->user(),
            $team,
            $user->id,
            (string) $request->input('role'),
        );

        return back()->with('success', __('Member role saved.'));
    }

    public function destroyMember(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        app(RemovesTeamMembers::class)->remove($request->user(), $team, $user);

        return back()->with('success', __('Access revoked for :name.', ['name' => $user->name]));
    }

    /**
     * @return array{name: string, email: string, password: string|null}
     */
    private function validateOwner(Request $request, ?User $user): array
    {
        $emailRules = ['required', 'string', 'email', 'max:255'];
        if ($user !== null) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($user->id);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
        ]);

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'] ?? null,
        ];
    }

    private function selectedAccount(Request $request): ?User
    {
        if (! $request->filled('user_id')) {
            return null;
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return User::query()->findOrFail($validated['user_id']);
    }
}
