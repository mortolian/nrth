<?php

namespace App\Support;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Laravel\Jetstream\Events\TeamMemberAdded;

final class AcceptTeamInvitations
{
    /**
     * Accept all pending invitations for the user's email.
     * Skips creating ownership; switches to the first invited business.
     */
    public static function forUser(User $user): ?Team
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '') {
            return null;
        }

        $invitations = TeamInvitation::query()
            ->with('team')
            ->whereRaw('lower(email) = ?', [$email])
            ->orderBy('id')
            ->get();

        if ($invitations->isEmpty()) {
            return null;
        }

        $firstTeam = null;

        foreach ($invitations as $invitation) {
            $team = self::acceptOne($user, $invitation, switchToTeam: $firstTeam === null);
            if ($team !== null) {
                $firstTeam ??= $team;
            }
        }

        return $firstTeam;
    }

    /**
     * Accept a single invitation for an authenticated user whose email matches.
     */
    public static function acceptOne(User $user, TeamInvitation $invitation, bool $switchToTeam = true): ?Team
    {
        if (strcasecmp(trim((string) $user->email), trim((string) $invitation->email)) !== 0) {
            return null;
        }

        $team = $invitation->team;
        if ($team === null) {
            $invitation->delete();

            return null;
        }

        if (! $user->ownsTeam($team) && ! $team->hasUserWithEmail($user->email)) {
            $team->users()->attach($user->id, [
                'role' => $invitation->role,
            ]);

            TeamMemberAdded::dispatch($team, $user);
        }

        $invitation->delete();

        if ($switchToTeam) {
            $user->switchTeam($team);
        }

        // Joining via invite never requires the owner business wizard.
        self::markMemberOnboardingComplete($user->fresh(), force: true);

        return $team;
    }

    /**
     * Ensure invite-only members (or members who still own an unfinished personal
     * team) are not forced through owner business setup.
     */
    public static function settleMembership(User $user): ?Team
    {
        $joined = self::forUser($user);
        $user = $user->fresh();

        if ($joined !== null) {
            return $joined;
        }

        if ($user === null || $user->completed_onboarding_at !== null) {
            return $user?->currentTeam;
        }

        // Already a member of someone else's business — use that, skip owner wizard.
        $memberTeam = $user->teams
            ->first(fn (Team $team): bool => ! $user->ownsTeam($team));

        if ($memberTeam !== null) {
            $user->switchTeam($memberTeam);
            self::markMemberOnboardingComplete($user->fresh(), force: true);

            return $memberTeam;
        }

        return null;
    }

    public static function markMemberOnboardingComplete(User $user, bool $force = false): void
    {
        if ($user->completed_onboarding_at !== null) {
            return;
        }

        // Owner business setup is separate; invite join always completes this flag.
        if (! $force && $user->ownedTeams()->exists()) {
            return;
        }

        $user->forceFill(['completed_onboarding_at' => now()])->save();
    }
}
