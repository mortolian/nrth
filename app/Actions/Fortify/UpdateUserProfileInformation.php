<?php

namespace App\Actions\Fortify;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->emailIsChanging($user, $input)),
                'nullable',
                'current_password',
            ],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        if ($this->emailIsChanging($user, $input)) {
            $newEmail = trim((string) $input['email']);
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                'email_verified_at' => $this->newEmailMayBeTrusted($newEmail) ? now() : null,
            ])->save();

            return;
        }

        $user->forceFill([
            'name' => $input['name'],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function emailIsChanging(User $user, array $input): bool
    {
        return strcasecmp(trim((string) ($input['email'] ?? '')), trim((string) $user->email)) !== 0;
    }

    /**
     * Password-confirmed email changes re-trust the mailbox unless the new address
     * would auto-join a pending invitation or match NRTH_OPERATOR_EMAILS.
     */
    private function newEmailMayBeTrusted(string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        /** @var list<string> $operatorEmails */
        $operatorEmails = config('nrth.operator_emails', []);
        if (in_array($normalized, $operatorEmails, true)) {
            return false;
        }

        return ! TeamInvitation::query()
            ->whereRaw('lower(email) = ?', [$normalized])
            ->exists();
    }
}
