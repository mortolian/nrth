<?php

namespace App\Domain\Instance\Services;

use App\Mail\BusinessOwnerAssignedMail;
use App\Models\Team;
use App\Models\User;
use App\Support\AssignFallbackTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BusinessOwners
{
    /**
     * Primary owner first, then additional owners.
     *
     * @return Collection<int, User>
     */
    public function forTeam(Team $team): Collection
    {
        $team->loadMissing(['owner', 'users']);

        $owners = collect();
        if ($team->owner !== null) {
            $owners->push($team->owner);
        }

        foreach ($team->users as $user) {
            if (($user->membership->role ?? null) !== 'owner') {
                continue;
            }
            if ($team->owner !== null && $user->id === $team->owner->id) {
                continue;
            }
            $owners->push($user);
        }

        return $owners->values();
    }

    /**
     * Create or attach an owner and email them a notice. Nothing to accept.
     */
    public function add(Team $team, string $name, string $email, ?string $password): AddedOwner
    {
        $email = trim($email);
        $existing = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();
        $newAccount = $existing === null;

        if ($existing === null) {
            if (! filled($password)) {
                throw ValidationException::withMessages([
                    'password' => __('Choose a password for this owner.'),
                ]);
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'completed_onboarding_at' => now(),
            ])->save();
        } else {
            if ($existing->isBusinessOwner($team)) {
                throw ValidationException::withMessages([
                    'email' => __('That person is already an owner of this business.'),
                ]);
            }

            $user = $existing;
        }

        $this->attach($team, $user);

        return new AddedOwner($user, $this->sendAssignedNotice($team, $user, $newAccount));
    }

    private function sendAssignedNotice(Team $team, User $user, bool $newAccount): bool
    {
        try {
            Mail::to($user->email)->send(new BusinessOwnerAssignedMail($team, $user, $newAccount));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function update(Team $team, User $user, string $name, string $email, ?string $password): void
    {
        if (! $user->isBusinessOwner($team)) {
            throw ValidationException::withMessages([
                'email' => __('That person is not an owner of this business.'),
            ]);
        }

        $emailChanged = strcasecmp($user->email, trim($email)) !== 0;
        $user->name = $name;
        $user->email = trim($email);
        if ($emailChanged) {
            $user->email_verified_at = now();
        }
        if (filled($password)) {
            $user->password = $password;
        }
        $user->save();
    }

    public function remove(Team $team, User $user): void
    {
        $owners = $this->forTeam($team);
        if (! $owners->contains(fn (User $owner): bool => $owner->id === $user->id)) {
            throw ValidationException::withMessages([
                'owner' => __('That person is not an owner of this business.'),
            ]);
        }

        if ($owners->count() < 2) {
            throw ValidationException::withMessages([
                'owner' => __('A business needs at least one owner.'),
            ]);
        }

        if ((int) $team->user_id === (int) $user->id) {
            $next = $owners->first(fn (User $owner): bool => $owner->id !== $user->id);
            $team->forceFill(['user_id' => $next->id])->save();
            $team->users()->detach($next->id);
        }

        $team->users()->detach($user->id);
        $team->unsetRelation('users');
        $team->unsetRelation('owner');

        if ((int) $user->current_team_id === (int) $team->id) {
            $user->forceFill(['current_team_id' => null])->save();
            AssignFallbackTeam::for($user);
        }
    }

    private function attach(Team $team, User $user): void
    {
        if ($team->user_id === null) {
            $team->forceFill(['user_id' => $user->id])->save();
        } elseif ((int) $team->user_id !== (int) $user->id) {
            $onTeam = $team->users()->where('users.id', $user->id)->exists();
            if ($onTeam) {
                $team->users()->updateExistingPivot($user->id, ['role' => 'owner']);
            } else {
                $team->users()->attach($user->id, ['role' => 'owner']);
            }
        }

        $user->refresh();
        if ($user->current_team_id === null) {
            $user->switchTeam($team->fresh());
        }
    }
}
