<?php

namespace App\Rules;

use App\Models\Team;
use App\Models\TeamRole;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TeamRoleKey implements ValidationRule
{
    public function __construct(private readonly Team $team) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('The :attribute must be a valid role.'));

            return;
        }

        EnsureTeamSystemRoles::ensureFor($this->team);

        $exists = TeamRole::query()
            ->where('team_id', $this->team->id)
            ->where('key', $value)
            ->exists();

        if (! $exists) {
            $fail(__('The :attribute must be a valid role.'));
        }
    }
}
