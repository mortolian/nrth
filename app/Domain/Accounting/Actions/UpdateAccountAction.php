<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\Unspecified;
use App\Domain\Accounting\DTOs\UpdateAccountDTO;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Exceptions\SystemAccountProtectedException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAccountAction
{
    public function execute(Account $account, UpdateAccountDTO $dto): Account
    {
        return DB::transaction(function () use ($account, $dto): Account {
            if ($account->is_system) {
                // Names (and descriptions) may be customized; codes, type, and hierarchy stay fixed.
                if ($dto->code !== Unspecified::Value && $dto->code !== $account->code) {
                    throw SystemAccountProtectedException::cannotRename();
                }

                if ($dto->type !== Unspecified::Value && $dto->type !== $account->type) {
                    throw SystemAccountProtectedException::cannotRename();
                }

                if ($dto->parentId !== Unspecified::Value) {
                    throw SystemAccountProtectedException::cannotRename();
                }
            }

            $effectiveType = $dto->type !== Unspecified::Value ? $dto->type : $account->type;
            $typeChanging = $dto->type !== Unspecified::Value && $dto->type !== $account->type;

            if ($typeChanging) {
                $this->assertTypeCanChange($account, $effectiveType);
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

            $effectiveParentId = $dto->parentId !== Unspecified::Value
                ? $dto->parentId
                : $account->parent_id;

            if ($effectiveParentId !== null) {
                if ($effectiveParentId === $account->getKey()) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('An account cannot be its own parent.'),
                    ]);
                }

                $parent = Account::queryWithoutTeamScope()
                    ->where('team_id', $account->team_id)
                    ->whereKey($effectiveParentId)
                    ->first();

                if ($parent === null) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('The selected parent account does not exist for this team.'),
                    ]);
                }

                if ($parent->type !== $effectiveType) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('The parent account type must match this account.'),
                    ]);
                }

                if ($this->wouldCreateParentCycle($account, $effectiveParentId)) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('An account cannot be moved under itself or one of its descendants.'),
                    ]);
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

            if ($dto->type !== Unspecified::Value) {
                $account->type = $dto->type;
            }

            if ($dto->parentId !== Unspecified::Value) {
                $account->parent_id = $dto->parentId;
            }

            if ($dto->isActive !== Unspecified::Value) {
                if ($account->is_system && ! $dto->isActive) {
                    throw SystemAccountProtectedException::cannotDeactivate();
                }

                $account->is_active = $dto->isActive;
            }

            $account->save();

            return $account->refresh();
        });
    }

    private function assertTypeCanChange(Account $account, AccountType $newType): void
    {
        if ($account->journalEntries()->exists()) {
            throw ValidationException::withMessages([
                'type' => __('This account has ledger activity, so its type cannot be changed.'),
            ]);
        }

        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'type' => __('Remove or reassign sub-accounts before changing this account’s type.'),
            ]);
        }

        $linkedToBanking = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $account->team_id)
            ->where('gl_account_id', $account->id)
            ->exists();

        if ($linkedToBanking && ! in_array($newType, [AccountType::Asset, AccountType::Liability], true)) {
            throw ValidationException::withMessages([
                'type' => __('This account is linked to a banking account, so it must stay an asset or liability.'),
            ]);
        }
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
