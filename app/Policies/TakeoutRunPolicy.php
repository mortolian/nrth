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
        return $user->currentTeam !== null && $user->ownsTeam($user->currentTeam);
    }

    public function view(User $user, TakeoutRun $takeoutRun): bool
    {
        return $user->ownsTeam($takeoutRun->team);
    }

    public function create(User $user): bool
    {
        return $user->currentTeam !== null && $user->ownsTeam($user->currentTeam);
    }

    public function download(User $user, TakeoutRun $takeoutRun): bool
    {
        return $user->ownsTeam($takeoutRun->team);
    }
}
