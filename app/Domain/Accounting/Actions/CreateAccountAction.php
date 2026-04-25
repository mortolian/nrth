<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\CreateAccountDTO;
use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccountAction
{
    public function execute(CreateAccountDTO $dto): Account
    {
        return DB::transaction(function () use ($dto): Account {
            if (Account::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->where('code', $dto->code)
                ->exists()) {
                throw ValidationException::withMessages([
                    'code' => __('An account with this code already exists for this team.'),
                ]);
            }

            if ($dto->parentId !== null) {
                $parent = Account::queryWithoutTeamScope()
                    ->where('team_id', $dto->teamId)
                    ->whereKey($dto->parentId)
                    ->first();

                if ($parent === null) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('The selected parent account does not exist for this team.'),
                    ]);
                }

                if ($parent->type !== $dto->type) {
                    throw ValidationException::withMessages([
                        'type' => __('The account type must match the parent account type.'),
                    ]);
                }
            }

            return Account::queryWithoutTeamScope()->create([
                'team_id' => $dto->teamId,
                'parent_id' => $dto->parentId,
                'code' => $dto->code,
                'name' => $dto->name,
                'description' => $dto->description,
                'type' => $dto->type,
                'is_system' => $dto->isSystem,
                'is_active' => true,
            ]);
        });
    }
}
