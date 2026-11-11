<?php

namespace App\Policies;

use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TakeoutRunPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null && $user->isBusinessOwner($user->currentTeam);
    }

    public function view(User $user, TakeoutRun $takeoutRun): bool
    {
        return $user->isBusinessOwner($takeoutRun->team);
    }

    public function create(User $user): bool
    {
        return $user->currentTeam !== null && $user->isBusinessOwner($user->currentTeam);
    }

    public function download(User $user, TakeoutRun $takeoutRun): bool
    {
        return $user->isBusinessOwner($takeoutRun->team);
    }

    public function delete(User $user, TakeoutRun $takeoutRun): bool
    {
        return $user->isBusinessOwner($takeoutRun->team);
    }

    public function retry(User $user, TakeoutRun $takeoutRun): bool
    {
        return $user->isBusinessOwner($takeoutRun->team);
    }
}
