<?php

namespace App\Domain\Instance\Services;

use App\Models\User;

final class AddedOwner
{
    public function __construct(
        public readonly User $user,
        public readonly bool $noticeSent,
    ) {}
}
