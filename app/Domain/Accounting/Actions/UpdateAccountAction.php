<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\Unspecified;
use App\Domain\Accounting\DTOs\UpdateAccountDTO;
use App\Domain\Accounting\Exceptions\SystemAccountProtectedException;
use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAccountAction
{
    public function execute(Account $account, UpdateAccountDTO $dto): Account
    {
        return DB::transaction(function () use ($account, $dto): Account {
            if ($account->is_system) {
                if ($dto->name !== Unspecified::Value || $dto->code !== Unspecified::Value) {
                    throw SystemAccountProtectedException::cannotRename();
                }

                if ($dto->parentId !== Unspecified::Value) {
                    throw SystemAccountProtectedException::cannotRename();
                }
            }

            if ($dto->code !== Unspecified::Value && $dto->code !== $account->code) {
                if (Account::queryWithoutTeamScope()
                    ->where('team_id', $account->team_id)
                    ->where('code', $dto->code)
                    ->whereKeyNot($account->getKey())
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'code' => __('An account with this code already exists for this team.'),
                    ]);
                }
            }

            if ($dto->parentId !== Unspecified::Value) {
                if ($dto->parentId === $account->getKey()) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('An account cannot be its own parent.'),
                    ]);
                }

                if ($dto->parentId !== null) {
                    $parent = Account::queryWithoutTeamScope()
                        ->where('team_id', $account->team_id)
                        ->whereKey($dto->parentId)
                        ->first();

                    if ($parent === null) {
                        throw ValidationException::withMessages([
                            'parent_id' => __('The selected parent account does not exist for this team.'),
                        ]);
                    }

                    if ($parent->type !== $account->type) {
                        throw ValidationException::withMessages([
                            'parent_id' => __('The parent account type must match this account.'),
                        ]);
                    }

                    if ($this->wouldCreateParentCycle($account, $dto->parentId)) {
                        throw ValidationException::withMessages([
                            'parent_id' => __('An account cannot be moved under itself or one of its descendants.'),
                        ]);
                    }
                }
            }

            if ($dto->name !== Unspecified::Value) {
                $account->name = $dto->name;
            }

            if ($dto->code !== Unspecified::Value) {
                $account->code = $dto->code;
            }

            if ($dto->description !== Unspecified::Value) {
                $account->description = $dto->description;
            }

            if ($dto->parentId !== Unspecified::Value) {
                $account->parent_id = $dto->parentId;
            }

            $account->save();

            return $account->refresh();
        });
    }

    /**
     * True when assigning {@see $potentialParentId} would make {@see $account} an ancestor of its parent (cycle).
     */
    private function wouldCreateParentCycle(Account $account, int $potentialParentId): bool
    {
        $walker = Account::queryWithoutTeamScope()->find($potentialParentId);

        while ($walker !== null) {
            if ($walker->getKey() === $account->getKey()) {
                return true;
            }

            $walker = $walker->parent_id
                ? Account::queryWithoutTeamScope()->find($walker->parent_id)
                : null;
        }

        return false;
    }
}
