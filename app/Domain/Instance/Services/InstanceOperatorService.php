<?php

namespace App\Domain\Instance\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InstanceOperatorService
{
    /**
     * @return list<array{id: int|null, name: string|null, email: string, source: string, can_remove: bool}>
     */
    public function listEffectiveOperators(): array
    {
        $byEmail = [];

        foreach ($this->databaseOperators() as $user) {
            $email = strtolower((string) $user->email);
            $byEmail[$email] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'source' => 'database',
                'can_remove' => true,
            ];
        }

        foreach ($this->envOperatorEmails() as $email) {
            if (isset($byEmail[$email])) {
                $byEmail[$email]['source'] = 'database+environment';
                $byEmail[$email]['can_remove'] = true;

                continue;
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $byEmail[$email] = [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email ?? $email,
                'source' => 'environment',
                'can_remove' => false,
            ];
        }

        $dbCount = $this->databaseOperatorCount();
        $envConfigured = $this->envOperatorEmails() !== [];

        return collect($byEmail)
            ->values()
            ->map(function (array $row) use ($dbCount, $envConfigured): array {
                if ($row['source'] === 'environment') {
                    $row['can_remove'] = false;
                } elseif ($dbCount <= 1 && ! $envConfigured) {
                    $row['can_remove'] = false;
                }

                return $row;
            })
            ->sortBy('email')
            ->values()
            ->all();
    }

    public function userCanManageInstance(User $user): bool
    {
        if ($user->is_instance_operator) {
            return true;
        }

        $email = strtolower((string) $user->email);

        return $email !== '' && in_array($email, $this->envOperatorEmails(), true);
    }

    public function addByEmail(string $email): User
    {
        $normalized = strtolower(trim($email));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$normalized])->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => __('No user exists with that email address.'),
            ]);
        }

        if (! $user->is_instance_operator) {
            $user->forceFill(['is_instance_operator' => true])->save();
        }

        return $user->fresh();
    }

    public function remove(User $actor, User $target): void
    {
        if (! $target->is_instance_operator) {
            throw ValidationException::withMessages([
                'user_id' => __('That user is not a database operator.'),
            ]);
        }

        if (! $this->canRemoveDatabaseOperator($target)) {
            throw ValidationException::withMessages([
                'user_id' => __('Cannot remove the last database operator unless NRTH_OPERATOR_EMAILS is set as a break-glass.'),
            ]);
        }

        $target->forceFill(['is_instance_operator' => false])->save();
    }

    public function canRemoveDatabaseOperator(User $target): bool
    {
        if (! $target->is_instance_operator) {
            return false;
        }

        if ($this->envOperatorEmails() !== []) {
            return true;
        }

        return $this->databaseOperatorCount() > 1;
    }

    public function promoteFirstUserIfNoOperators(): ?User
    {
        if ($this->databaseOperatorCount() > 0) {
            return null;
        }

        $user = User::query()->orderBy('id')->first();
        if ($user === null) {
            return null;
        }

        $user->forceFill(['is_instance_operator' => true])->save();

        return $user->fresh();
    }

    public function databaseOperatorCount(): int
    {
        return User::query()->where('is_instance_operator', true)->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function databaseOperators(): Collection
    {
        return User::query()
            ->where('is_instance_operator', true)
            ->orderBy('email')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function envOperatorEmails(): array
    {
        return config('nrth.operator_emails', []);
    }
}
